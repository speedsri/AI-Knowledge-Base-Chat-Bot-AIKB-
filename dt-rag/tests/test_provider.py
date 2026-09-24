import pytest
from httpx import AsyncClient, ASGITransport

pytestmark = pytest.mark.asyncio


async def test_provider_test_reports_not_configured_with_no_key(auth_headers, monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "")
    from app.main import app
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        resp = await client.post("/v1/provider/test", headers=auth_headers)
    assert resp.status_code == 200
    body = resp.json()
    assert body["configured"] is False
    assert body["healthy"] is False
    assert body["error_code"] == "provider_not_configured"
    assert "api_key" not in body
    assert "gemini_api_key" not in body


async def test_provider_test_never_leaks_key_even_if_configured(auth_headers, monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key-value-should-never-appear")
    from unittest.mock import patch, AsyncMock
    from app.main import app
    with patch("app.main.gemini_client.embed_text", new=AsyncMock(return_value=[0.1] * 768)):
        transport = ASGITransport(app=app)
        async with AsyncClient(transport=transport, base_url="http://test") as client:
            resp = await client.post("/v1/provider/test", headers=auth_headers)
    assert "fake-key-value-should-never-appear" not in resp.text
