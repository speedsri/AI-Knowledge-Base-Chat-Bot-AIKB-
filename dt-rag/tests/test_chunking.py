import pytest
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

from app.chunking import chunk_text, sha256_hex


def test_short_text_returns_single_chunk():
    text = "This is a short document."
    chunks = chunk_text(text, max_tokens=400, overlap_tokens=60)
    assert chunks == [text]


def test_empty_text_returns_no_chunks():
    assert chunk_text("", max_tokens=400, overlap_tokens=60) == []
    assert chunk_text("   ", max_tokens=400, overlap_tokens=60) == []


def test_long_text_splits_into_multiple_chunks():
    text = "word " * 2000
    chunks = chunk_text(text, max_tokens=400, overlap_tokens=60)
    assert len(chunks) > 1


def test_chunking_is_deterministic():
    text = "word " * 2000
    chunks1 = chunk_text(text, max_tokens=400, overlap_tokens=60)
    chunks2 = chunk_text(text, max_tokens=400, overlap_tokens=60)
    assert chunks1 == chunks2


def test_overlap_must_be_less_than_max_tokens():
    with pytest.raises(ValueError):
        chunk_text("some text", max_tokens=100, overlap_tokens=100)


def test_sha256_hex_deterministic():
    assert sha256_hex("hello") == sha256_hex("hello")
    assert sha256_hex("hello") != sha256_hex("world")


def test_sha256_hex_is_64_char_hex():
    digest = sha256_hex("test content")
    assert len(digest) == 64
    int(digest, 16)
