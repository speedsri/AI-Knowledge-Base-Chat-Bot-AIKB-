"""
Crawler foundation. V2 correction (item 5): every fetch now connects
directly to a resolved-and-validated IP address (via
ssrf_guard.resolve_and_validate), never letting httpx perform its own,
independent DNS resolution -- this closes the DNS-rebinding / TOCTOU gap
present in the prior design. The original hostname is preserved via the
`Host` header (HTTP) and httpx's `sni_hostname` request extension (TLS SNI
+ certificate verification target), which the pinned httpx/httpcore
version genuinely honors (verified by inspecting the installed library
source -- see TEST_PHASE_B.md).

Every redirect hop repeats the full resolve-and-validate sequence from
scratch against the redirect's Location header -- a URL that resolves
safely can still redirect to a private address, and each hop is a fresh
opportunity for a rebinding attack if not re-validated independently.

V2 correction (item 3): this module no longer writes ANYTHING to Qdrant.
It only fetches, validates, and normalizes pages, returning prepared
results. See ingestion/pipeline.py -- Qdrant indexing happens exclusively
via POST /v1/reindex against canonical AIKB document/document_version data,
never from crawled-but-unregistered pages.
"""
import asyncio
import logging
import socket
from urllib.parse import urljoin, urlparse, urlunparse
from urllib.robotparser import RobotFileParser
import httpx
from bs4 import BeautifulSoup

from app.config import settings
from ingestion.ssrf_guard import resolve_and_validate, SsrfViolation
from ingestion.normalize import canonicalize_url, extract_text, extract_title

logger = logging.getLogger("dt-rag.crawler")

USER_AGENT = "DynamicTechAI-Bot/2.0 (+https://dynamictecsl.site)"
ALLOWED_CONTENT_TYPES = ("text/html",)


class CrawlResult:
    def __init__(self):
        self.pages = []   # {url, title, text} -- prepared, NOT indexed
        self.failed = []  # {url, reason}


def _build_pinned_url(scheme: str, ip: str, port: int, path: str, query: str) -> str:
    netloc = f"{ip}:{port}" if ":" not in ip else f"[{ip}]:{port}"
    return urlunparse((scheme, netloc, path or "/", "", query, ""))


async def _fetch_pinned(client: httpx.AsyncClient, url: str, max_size_bytes: int, max_hops: int = 5):
    """
    Resolves-and-validates, then connects to the validated IP directly at
    every hop (including redirects), never letting httpx re-resolve the
    hostname independently.
    """
    current_url = url
    for _ in range(max_hops):
        parsed = urlparse(current_url)
        ip, port, hostname = resolve_and_validate(current_url)  # full re-validation every hop
        pinned_url = _build_pinned_url(parsed.scheme, ip, port, parsed.path, parsed.query)

        request = client.build_request("GET", pinned_url, headers={"Host": hostname})
        request.extensions["sni_hostname"] = hostname  # preserve TLS SNI + cert verification target

        resp = await client.send(request)

        if resp.status_code in (301, 302, 303, 307, 308):
            location = resp.headers.get("location")
            if not location:
                return None, current_url
            # Resolve the redirect relative to the ORIGINAL (unpinned) URL,
            # not the pinned one, so relative redirects behave correctly.
            next_url = urljoin(current_url, location)
            current_url = next_url
            continue  # loop re-validates next_url from scratch at the top

        if resp.status_code != 200:
            return None, current_url

        content_type = resp.headers.get("content-type", "")
        if not any(ct in content_type for ct in ALLOWED_CONTENT_TYPES):
            return None, current_url

        if len(resp.content) > max_size_bytes:
            logger.warning(f"crawler.size_limit_exceeded url={current_url} bytes={len(resp.content)}")
            return None, current_url

        return resp.text, current_url

    return None, current_url


async def _get_robot_parser(client: httpx.AsyncClient, base_url: str) -> RobotFileParser:
    parsed = urlparse(base_url)
    robots_url = f"{parsed.scheme}://{parsed.netloc}/robots.txt"
    rp = RobotFileParser()
    rp.set_url(robots_url)
    try:
        html, _ = await _fetch_pinned(client, robots_url, max_size_bytes=1_000_000)
        if html is not None:
            rp.parse(html.splitlines())
        else:
            rp.parse([])
    except SsrfViolation:
        rp.parse([])
    return rp


async def crawl(
    origin_url: str,
    max_pages=None,
    crawl_delay_seconds=None,
    request_timeout_seconds=None,
    max_document_size_kb=None,
    excluded_path_patterns=None,
):
    max_pages = max_pages or settings.crawler_default_max_pages
    delay = crawl_delay_seconds or settings.crawler_default_delay_seconds
    timeout = request_timeout_seconds or settings.crawler_default_timeout_seconds
    max_size_bytes = (max_document_size_kb or settings.crawler_default_max_doc_size_kb) * 1024
    excluded = excluded_path_patterns or []

    resolve_and_validate(origin_url)  # fail fast before doing anything else
    allowed_domain = urlparse(origin_url).netloc

    result = CrawlResult()
    visited = set()
    queue = [canonicalize_url(origin_url)]

    async with httpx.AsyncClient(timeout=timeout, follow_redirects=False) as client:
        robots = await _get_robot_parser(client, origin_url) if settings.crawler_respect_robots_txt else None

        while queue and len(visited) < max_pages:
            url = queue.pop(0)
            if url in visited:
                continue
            visited.add(url)

            if any(pattern in url for pattern in excluded):
                continue
            if robots is not None and not robots.can_fetch(USER_AGENT, url):
                logger.info(f"crawler.robots_disallowed url={url}")
                continue

            try:
                html, final_url = await _fetch_pinned(client, url, max_size_bytes)
                if html is None:
                    continue

                text = extract_text(html)
                if text:
                    result.pages.append({
                        "url": canonicalize_url(final_url),
                        "title": extract_title(html) or final_url,
                        "text": text,
                    })

                if urlparse(final_url).netloc == allowed_domain:
                    for link in _extract_links(final_url, html):
                        canon = canonicalize_url(link)
                        if canon not in visited and urlparse(canon).netloc == allowed_domain:
                            queue.append(canon)

            except SsrfViolation as e:
                logger.warning(f"crawler.ssrf_blocked url={url} reason={e.reason}")
                result.failed.append({"url": url, "reason": f"ssrf_blocked: {e.reason}"})
            except Exception as e:
                logger.warning(f"crawler.fetch_failed url={url} error={type(e).__name__}")
                result.failed.append({"url": url, "reason": str(e)})

            await asyncio.sleep(delay)

    return result


def _extract_links(base_url, html):
    soup = BeautifulSoup(html, "lxml")
    links = []
    for a in soup.find_all("a", href=True):
        href = urljoin(base_url, a["href"])
        if urlparse(href).scheme in ("http", "https"):
            links.append(href)
    return links
