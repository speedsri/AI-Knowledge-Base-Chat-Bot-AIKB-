"""
HTML-to-text normalization and URL canonicalization, kept separate from the
crawler so both can be unit tested without any network access.
"""
import trafilatura
from urllib.parse import urlparse, urlunparse, urldefrag


def canonicalize_url(url: str) -> str:
    url, _ = urldefrag(url)
    parsed = urlparse(url)
    scheme = parsed.scheme.lower()
    netloc = parsed.netloc.lower()
    path = parsed.path
    if len(path) > 1 and path.endswith("/"):
        path = path.rstrip("/")
    return urlunparse((scheme, netloc, path, parsed.params, parsed.query, ""))


def extract_text(html: str):
    extracted = trafilatura.extract(html, include_tables=True, include_links=False, favor_recall=True)
    if extracted and len(extracted.strip()) > 40:
        return extracted.strip()
    return None


def extract_title(html: str):
    from bs4 import BeautifulSoup
    soup = BeautifulSoup(html, "lxml")
    if soup.title and soup.title.string:
        return soup.title.string.strip()
    h1 = soup.find("h1")
    return h1.get_text(strip=True) if h1 else None
