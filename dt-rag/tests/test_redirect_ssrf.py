"""
V2 rewrite: tests the new _fetch_pinned() (crawler.py), which connects
directly to a resolved-and-validated IP rather than letting httpx resolve
the hostname independently. This is what actually closes the DNS-rebinding
/ TOCTOU gap (item 5).
"""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from ingestion.ssrf_guard import SsrfViolation
from ingestion.crawler import _fetch_pinned

pytestmark = pytest.mark.asyncio


class _FakeResponse:
    def __init__(self, status_code, headers=None, text="", content=b"", content_type="text/html"):
        self.status_code = status_code
        self.headers = headers or {}
        if "content-type" not in {k.lower(): v for k, v in self.headers.items()}:
            self.headers["content-type"] = content_type
        self.text = text
        self.content = content or text.encode()


def _make_fake_client(responses):
    """A fake httpx.AsyncClient whose build_request returns a bare object
    with a mutable `.extensions` dict (as the real Request does), and whose
    `.send()` returns responses in sequence."""
    fake_client = MagicMock()

    def build_request(method, url, headers=None):
        req = MagicMock()
        req.extensions = {}
        req.url = url
        req.headers = headers or {}
        return req

    fake_client.build_request = MagicMock(side_effect=build_request)
    fake_client.send = AsyncMock(side_effect=responses)
    return fake_client


async def test_dns_rebinding_second_resolution_never_happens():
    """
    The core TOCTOU/rebinding test: resolve_and_validate is called ONCE per
    hop and its result (a specific IP) is what the (fake) client is asked
    to send a request to -- proving the design does not perform a second,
    independent resolution that an attacker's DNS server could answer
    differently. We simulate this by patching resolve_and_validate to
    return a fixed safe IP, and asserting the pinned URL passed to
    build_request uses that exact IP, never the original hostname.
    """
    fake_client = _make_fake_client([_FakeResponse(200, text="<html>ok</html>")])

    with patch("ingestion.crawler.resolve_and_validate", return_value=("93.184.216.34", 443, "example.com")):
        html, final_url = await _fetch_pinned(fake_client, "https://example.com/page", max_size_bytes=10_000_000)

    assert html == "<html>ok</html>"
    called_url = fake_client.build_request.call_args.args[1]
    assert "93.184.216.34" in called_url
    assert "example.com" not in called_url  # the hostname must NOT appear in the connection target


async def test_redirect_to_private_ip_is_rejected():
    """A redirect target resolving to a private IP must be rejected when
    the NEXT hop's resolve_and_validate call is reached."""
    responses = [_FakeResponse(302, headers={"location": "http://internal.example.com/admin"})]
    fake_client = _make_fake_client(responses)

    def fake_resolve(url):
        if "internal.example.com" in url:
            raise SsrfViolation("resolved address 192.168.1.100 is in a denied range", url)
        return "93.184.216.34", 80, "example.com"

    with patch("ingestion.crawler.resolve_and_validate", side_effect=fake_resolve):
        with pytest.raises(SsrfViolation):
            await _fetch_pinned(fake_client, "https://example.com/redirect-me", max_size_bytes=10_000_000)


async def test_redirect_to_metadata_endpoint_is_rejected():
    responses = [_FakeResponse(302, headers={"location": "http://169.254.169.254/latest/meta-data/"})]
    fake_client = _make_fake_client(responses)

    def fake_resolve(url):
        if "169.254.169.254" in url:
            raise SsrfViolation("IP literal 169.254.169.254 is in a denied range", url)
        return "93.184.216.34", 80, "example.com"

    with patch("ingestion.crawler.resolve_and_validate", side_effect=fake_resolve):
        with pytest.raises(SsrfViolation):
            await _fetch_pinned(fake_client, "https://example.com/redirect-me", max_size_bytes=10_000_000)


async def test_every_redirect_hop_is_independently_revalidated():
    """Two redirects in a row -- resolve_and_validate must be called for
    EACH hop, not just the first."""
    responses = [
        _FakeResponse(302, headers={"location": "https://example.com/hop2"}),
        _FakeResponse(302, headers={"location": "https://example.com/hop3"}),
        _FakeResponse(200, text="<html>final</html>"),
    ]
    fake_client = _make_fake_client(responses)
    resolve_calls = []

    def fake_resolve(url):
        resolve_calls.append(url)
        return "93.184.216.34", 443, "example.com"

    with patch("ingestion.crawler.resolve_and_validate", side_effect=fake_resolve):
        html, final_url = await _fetch_pinned(fake_client, "https://example.com/hop1", max_size_bytes=10_000_000)

    assert html == "<html>final</html>"
    assert len(resolve_calls) == 3  # hop1, hop2, hop3 -- each independently revalidated


async def test_response_exceeding_max_size_is_rejected():
    fake_client = _make_fake_client([_FakeResponse(200, text="x" * 1000, content=b"x" * 1000)])
    with patch("ingestion.crawler.resolve_and_validate", return_value=("93.184.216.34", 443, "example.com")):
        html, final_url = await _fetch_pinned(fake_client, "https://example.com/big-page", max_size_bytes=500)
    assert html is None


async def test_non_html_content_type_is_rejected():
    fake_client = _make_fake_client([_FakeResponse(200, text="binarydata", content_type="application/pdf")])
    with patch("ingestion.crawler.resolve_and_validate", return_value=("93.184.216.34", 443, "example.com")):
        html, final_url = await _fetch_pinned(fake_client, "https://example.com/file.pdf", max_size_bytes=10_000_000)
    assert html is None


async def test_sni_hostname_extension_is_set_to_original_hostname():
    """The Host header and SNI extension must reflect the ORIGINAL
    hostname, not the pinned IP, so TLS certificate verification and
    virtual-hosting both work correctly against the real target site."""
    fake_client = _make_fake_client([_FakeResponse(200, text="<html>ok</html>")])
    captured_request = {}

    def build_request(method, url, headers=None):
        req = MagicMock()
        req.extensions = {}
        captured_request["headers"] = headers
        captured_request["req"] = req
        return req

    fake_client.build_request = MagicMock(side_effect=build_request)

    with patch("ingestion.crawler.resolve_and_validate", return_value=("93.184.216.34", 443, "example.com")):
        await _fetch_pinned(fake_client, "https://example.com/page", max_size_bytes=10_000_000)

    assert captured_request["headers"]["Host"] == "example.com"
    assert captured_request["req"].extensions["sni_hostname"] == "example.com"
