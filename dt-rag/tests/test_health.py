import pytest
from httpx import AsyncClient, ASGITransport
from unittest.mock import patch, AsyncMock

pytestmark = pytest.mark.asyncio


async def test_health_ok_when_qdrant_reachable():
    from app.main import app
    with patch("app.main.vector_store.check_health", new=AsyncMock(return_value="ok")):
        transport = ASGITransport(app=app)
        async with AsyncClient(transport=transport, base_url="http://test") as client:
            resp = await client.get("/health")
    assert resp.status_code == 200
    body = resp.json()
    assert body["status"] == "ok"
    assert body["dependencies"]["qdrant"] == "ok"
    assert body["dependencies"]["gemini"] == "not_configured"


async def test_health_degraded_when_qdrant_unreachable():
    from app.main import app
    with patch("app.main.vector_store.check_health", new=AsyncMock(return_value="unreachable")):
        transport = ASGITransport(app=app)
        async with AsyncClient(transport=transport, base_url="http://test") as client:
            resp = await client.get("/health")
    assert resp.status_code == 200
    assert resp.json()["status"] == "degraded"


async def test_health_never_returns_secrets():
    from app.main import app
    with patch("app.main.vector_store.check_health", new=AsyncMock(return_value="ok")):
        transport = ASGITransport(app=app)
        async with AsyncClient(transport=transport, base_url="http://test") as client:
            resp = await client.get("/health")
    body_text = resp.text
    assert "test-token-for-pytest-only" not in body_text
