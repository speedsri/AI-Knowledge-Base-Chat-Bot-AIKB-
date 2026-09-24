"""Item 9: zero-context chat must not call Gemini generation at all."""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from httpx import AsyncClient, ASGITransport
from unittest.mock import patch, AsyncMock

pytestmark = pytest.mark.asyncio


async def test_zero_retrieval_matches_skips_generation_call(auth_headers):
    from app.main import app

    with patch("app.main.gemini_client.embed_text", new=AsyncMock(return_value=[0.1] * 768)), \
         patch("app.main.vector_store.search", new=AsyncMock(return_value=[])), \
         patch("app.main.gemini_client.generate_answer", new=AsyncMock(side_effect=AssertionError("generate_answer MUST NOT be called with zero retrieval matches"))):

        transport = ASGITransport(app=app)
        async with AsyncClient(transport=transport, base_url="http://test") as client:
            resp = await client.post(
                "/v1/chat",
                json={"knowledge_base_id": 1, "conversation_ref": "t1", "channel": "admin_test", "message": "obscure question"},
                headers=auth_headers,
            )

    assert resp.status_code == 200
    body = resp.json()
    assert body["grounded"] is False
    assert body["confidence"] == 0.0
    assert body["escalated"] is True
    assert body["sources"] == []
    assert "don't have enough information" in body["answer"].lower()


async def test_response_contract_stable_with_context(auth_headers):
    """When context IS found, the response shape must match the same
    ChatResponse contract (no fields renamed/removed for this path)."""
    from app.main import app
    fake_hit = type("Hit", (), {
        "score": 0.9,
        "payload": {
            "chunk_text": "Some real content.", "canonical_url": "https://example.com",
            "title": "Doc", "document_id": 1, "document_version_id": 2,
        },
    })()

    with patch("app.main.gemini_client.embed_text", new=AsyncMock(return_value=[0.1] * 768)), \
         patch("app.main.vector_store.search", new=AsyncMock(return_value=[fake_hit])), \
         patch("app.main.gemini_client.generate_answer", new=AsyncMock(return_value=("A real answer.", 0.9))):

        transport = ASGITransport(app=app)
        async with AsyncClient(transport=transport, base_url="http://test") as client:
            resp = await client.post(
                "/v1/chat",
                json={"knowledge_base_id": 1, "conversation_ref": "t1", "channel": "admin_test", "message": "real question"},
                headers=auth_headers,
            )

    assert resp.status_code == 200
    body = resp.json()
    for key in ("answer", "grounded", "confidence", "escalated", "model", "sources", "latency_ms"):
        assert key in body
