"""Item 8: real request-body size enforcement."""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from httpx import AsyncClient, ASGITransport

pytestmark = pytest.mark.asyncio


async def test_oversized_body_rejected_with_413(auth_headers, monkeypatch):
    monkeypatch.setattr("app.request_size.settings.max_request_body_bytes", 100)
    from app.main import app
    huge_payload = {"knowledge_base_id": 1, "query": "x" * 5000}
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        resp = await client.post("/v1/retrieval/test", json=huge_payload, headers=auth_headers)
    assert resp.status_code == 413


async def test_normal_sized_body_not_rejected(auth_headers, monkeypatch):
    monkeypatch.setattr("app.request_size.settings.max_request_body_bytes", 2_000_000)
    from app.main import app
    from unittest.mock import patch, AsyncMock
    from app.vector_store import VectorStoreError
    with patch("app.main.gemini_client.embed_text", new=AsyncMock(side_effect=VectorStoreError("n/a"))):
        transport = ASGITransport(app=app)
        async with AsyncClient(transport=transport, base_url="http://test") as client:
            resp = await client.post(
                "/v1/retrieval/test", json={"knowledge_base_id": 1, "query": "small"}, headers=auth_headers,
            )
    assert resp.status_code != 413


async def test_oversized_body_not_logged(auth_headers, monkeypatch, caplog):
    monkeypatch.setattr("app.request_size.settings.max_request_body_bytes", 100)
    from app.main import app
    secret_marker = "SHOULD_NEVER_APPEAR_IN_LOGS_xyz123"
    huge_payload = {"knowledge_base_id": 1, "query": secret_marker + ("x" * 5000)}
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        await client.post("/v1/retrieval/test", json=huge_payload, headers=auth_headers)
    assert secret_marker not in caplog.text


def test_normalized_content_field_is_bounded():
    from app.schemas import ReindexRequest
    from pydantic import ValidationError
    with pytest.raises(ValidationError):
        ReindexRequest(
            knowledge_base_id=1, document_id=1, document_version_id=1,
            title="t", canonical_url=None,
            normalized_content="x" * 2_000_000,  # exceeds max_length=1_500_000
            content_hash="a" * 64, is_published=True,
        )
