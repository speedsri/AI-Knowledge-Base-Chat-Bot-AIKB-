import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from pydantic import ValidationError
from app.schemas import ReindexRequest


def _valid_payload(**overrides):
    payload = dict(
        knowledge_base_id=1,
        document_id=1,
        document_version_id=2,
        title="Test Document",
        canonical_url=None,
        normalized_content="Some normalized content.",
        content_hash="a" * 64,
        is_published=True,
    )
    payload.update(overrides)
    return payload


def test_valid_payload_is_accepted():
    req = ReindexRequest(**_valid_payload())
    assert req.document_version_id == 2


def test_rejects_non_hex_content_hash():
    with pytest.raises(ValidationError):
        ReindexRequest(**_valid_payload(content_hash="z" * 64))


def test_rejects_wrong_length_content_hash():
    with pytest.raises(ValidationError):
        ReindexRequest(**_valid_payload(content_hash="abc123"))


def test_content_hash_is_lowercased():
    req = ReindexRequest(**_valid_payload(content_hash="A" * 64))
    assert req.content_hash == "a" * 64


def test_rejects_empty_normalized_content():
    with pytest.raises(ValidationError):
        ReindexRequest(**_valid_payload(normalized_content=""))


def test_rejects_zero_or_negative_ids():
    with pytest.raises(ValidationError):
        ReindexRequest(**_valid_payload(knowledge_base_id=0))
    with pytest.raises(ValidationError):
        ReindexRequest(**_valid_payload(document_id=-1))


def test_rejects_missing_title():
    with pytest.raises(ValidationError):
        ReindexRequest(**_valid_payload(title=""))


def test_canonical_url_is_optional():
    req = ReindexRequest(**_valid_payload(canonical_url=None))
    assert req.canonical_url is None
    req2 = ReindexRequest(**_valid_payload(canonical_url="https://example.com/page"))
    assert req2.canonical_url == "https://example.com/page"
