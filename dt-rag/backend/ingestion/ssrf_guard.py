"""
Dedicated SSRF defense. V2 correction (item 5): the prior design validated
a hostname's DNS resolution once, then let httpx perform its OWN, separate
DNS resolution when actually connecting -- a classic DNS-rebinding / TOCTOU
gap (an attacker's DNS server can return a safe IP to our validation lookup
and a different, private IP to the connection lookup moments later, since
these are two independent resolutions).

FIX: this module now resolves the hostname EXACTLY ONCE per request/hop,
validates the resolved IP(s), and returns the specific validated IP the
caller must connect to. The crawler (crawler.py) then connects directly to
that literal IP -- never re-resolving the hostname -- while preserving the
original Host header and TLS SNI via httpx's `sni_hostname` request
extension (confirmed present and honored by the pinned httpx/httpcore
version -- see TEST_PHASE_B.md for how this was verified). Every redirect
hop repeats this full resolve-and-validate sequence from scratch.

Deny-by-default global-address policy: anything not confirmed to be a
public, globally-routable unicast address is rejected unless explicitly
allow-listed via CRAWLER_ALLOWLISTED_PRIVATE_RANGES (empty by default).
"""
import ipaddress
import socket
from urllib.parse import urlparse
from app.config import settings

ALLOWED_SCHEMES = {"http", "https"}

_ALWAYS_DENIED_NETWORKS = [
    ipaddress.ip_network("127.0.0.0/8"),
    ipaddress.ip_network("::1/128"),
    ipaddress.ip_network("169.254.0.0/16"),   # link-local, incl. cloud metadata endpoint
    ipaddress.ip_network("fe80::/10"),
    ipaddress.ip_network("0.0.0.0/8"),
    ipaddress.ip_network("100.64.0.0/10"),    # carrier-grade NAT (RFC 6598)
    ipaddress.ip_network("192.0.0.0/24"),     # IETF protocol assignments
    ipaddress.ip_network("192.0.2.0/24"),     # TEST-NET-1
    ipaddress.ip_network("198.18.0.0/15"),    # benchmarking
    ipaddress.ip_network("198.51.100.0/24"),  # TEST-NET-2
    ipaddress.ip_network("203.0.113.0/24"),   # TEST-NET-3
    ipaddress.ip_network("240.0.0.0/4"),      # reserved
    ipaddress.ip_network("255.255.255.255/32"),
    ipaddress.ip_network("::/128"),           # unspecified
    ipaddress.ip_network("2001:db8::/32"),    # documentation range
    ipaddress.ip_network("ff00::/8"),         # multicast
]

_PRIVATE_NETWORKS = [
    ipaddress.ip_network("10.0.0.0/8"),
    ipaddress.ip_network("172.16.0.0/12"),
    ipaddress.ip_network("192.168.0.0/16"),
    ipaddress.ip_network("fc00::/7"),
]


class SsrfViolation(Exception):
    def __init__(self, reason: str, url: str):
        self.reason = reason
        self.url = url
        super().__init__(f"SSRF check failed for {url}: {reason}")


def _allowlisted_networks():
    nets = []
    for cidr in settings.allowlisted_private_ranges_list:
        try:
            nets.append(ipaddress.ip_network(cidr, strict=False))
        except ValueError:
            continue
    return nets


def is_denied_ip(ip) -> bool:
    """
    Deny-by-default global-address policy: an address is denied if it is
    in any always-denied range, is multicast, OR if it is not a globally-
    routable unicast address at all (covers reserved and anything not
    explicitly known-public), UNLESS it falls in an explicitly allow-listed
    private range. Always-denied ranges and multicast can never be
    allow-listed.
    """
    if ip.is_multicast:
        return True

    for net in _ALWAYS_DENIED_NETWORKS:
        if ip in net:
            return True

    allowlisted = _allowlisted_networks()
    for net in _PRIVATE_NETWORKS:
        if ip in net:
            if any(ip in allow_net for allow_net in allowlisted):
                return False
            return True

    # Deny-by-default: if Python's own classification says this address is
    # not globally routable public unicast (and it wasn't already caught by
    # the explicit private/always-denied lists above, e.g. some IPv6
    # ranges), reject it too, unless explicitly allow-listed.
    if not ip.is_global:
        if any(ip in allow_net for allow_net in allowlisted):
            return False
        return True

    return False


def validate_url_syntax(url: str):
    """Scheme + hostname presence check only -- no DNS resolution. Used as
    a fast pre-check before the real (resolving) validation."""
    parsed = urlparse(url)
    if parsed.scheme not in ALLOWED_SCHEMES:
        raise SsrfViolation(f"scheme '{parsed.scheme}' not allowed", url)
    if not parsed.hostname:
        raise SsrfViolation("no hostname in URL", url)
    return parsed


def resolve_and_validate(url: str) -> tuple[str, int, str]:
    """
    Resolves the URL's hostname EXACTLY ONCE, validates every returned
    address, and returns (validated_ip, port, hostname) for the caller to
    connect to directly -- never re-resolving. Raises SsrfViolation if the
    hostname has no safe resolved address, or if it's an IP literal that is
    itself denied.

    This is the single function that must be called immediately before
    every single outbound connection attempt, including every redirect hop
    -- see ingestion/crawler.py.
    """
    parsed = validate_url_syntax(url)
    hostname = parsed.hostname
    port = parsed.port or (443 if parsed.scheme == "https" else 80)

    try:
        literal_ip = ipaddress.ip_address(hostname)
        if is_denied_ip(literal_ip):
            raise SsrfViolation(f"IP literal {literal_ip} is in a denied range", url)
        return str(literal_ip), port, hostname
    except ValueError:
        pass  # not an IP literal, resolve via DNS below

    try:
        resolved = socket.getaddrinfo(hostname, port)
    except socket.gaierror as e:
        raise SsrfViolation(f"DNS resolution failed: {e}", url)

    if not resolved:
        raise SsrfViolation("DNS resolution returned no addresses", url)

    for family, _, _, _, sockaddr in resolved:
        ip_str = sockaddr[0]
        try:
            ip = ipaddress.ip_address(ip_str)
        except ValueError:
            continue
        if not is_denied_ip(ip):
            return ip_str, port, hostname

    raise SsrfViolation("all resolved addresses are in denied ranges", url)


# Backwards-compatible name used by existing tests / callers that only need
# a boolean validation without needing the resolved IP back.
def validate_url(url: str) -> None:
    resolve_and_validate(url)
