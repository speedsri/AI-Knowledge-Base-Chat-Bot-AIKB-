import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

from ingestion.normalize import canonicalize_url, extract_text, extract_title


def test_canonicalize_strips_fragment():
    assert canonicalize_url("https://example.com/page#section") == "https://example.com/page"


def test_canonicalize_lowercases_scheme_and_host():
    assert canonicalize_url("HTTPS://Example.COM/Page") == "https://example.com/Page"


def test_canonicalize_strips_trailing_slash_but_not_root():
    assert canonicalize_url("https://example.com/page/") == "https://example.com/page"
    assert canonicalize_url("https://example.com/") == "https://example.com/"


def test_canonicalize_is_idempotent():
    url = "https://example.com/page/"
    assert canonicalize_url(canonicalize_url(url)) == canonicalize_url(url)


def test_extract_text_returns_none_for_empty_html():
    assert extract_text("<html><body></body></html>") is None


def test_extract_text_returns_content_for_real_page():
    html = "<html><body><article><p>" + ("This is meaningful article content. " * 10) + "</p></article></body></html>"
    result = extract_text(html)
    assert result is not None
    assert "meaningful article content" in result


def test_extract_title_from_title_tag():
    html = "<html><head><title>My Page Title</title></head><body></body></html>"
    assert extract_title(html) == "My Page Title"


def test_extract_title_falls_back_to_h1():
    html = "<html><body><h1>Fallback Heading</h1></body></html>"
    assert extract_title(html) == "Fallback Heading"


def test_extract_title_returns_none_if_neither_present():
    html = "<html><body><p>No title or heading</p></body></html>"
    assert extract_title(html) is None
