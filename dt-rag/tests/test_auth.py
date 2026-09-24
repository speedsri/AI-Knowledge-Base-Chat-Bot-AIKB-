import pytest
import subprocess
import sys
import os
from httpx import AsyncClient, ASGITransport
from unittest.mock import patch, AsyncMock

pytestmark = pytest.mark.asyncio


async def test_missing_auth_header_is_401_not_422():
    """V2 fix (item 6): FastAPI's default for a missing required header is
    422; this must be 401 since it's an authentication failure, not a
    malformed-request problem."""
    from app.main import app
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        resp = await client.post("/v1/retrieval/test", json={"knowledge_base_id": 1, "query": "hi"})
    assert resp.status_code == 401


async def test_malformed_auth_header_is_401():
    from app.main import app
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        resp = await client.post(
            "/v1/retrieval/test",
            json={"knowledge_base_id": 1, "query": "hi"},
            headers={"Authorization": "NotBearer sometoken"},
        )
    assert resp.status_code == 401


async def test_wrong_token_is_403(wrong_auth_headers):
    from app.main import app
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        resp = await client.post(
            "/v1/retrieval/test", json={"knowledge_base_id": 1, "query": "hi"}, headers=wrong_auth_headers,
        )
    assert resp.status_code == 403


async def test_correct_token_accepted(auth_headers):
    from app.main import app
    from app.vector_store import VectorStoreError
    with patch("app.main.gemini_client.embed_text", new=AsyncMock(side_effect=VectorStoreError("n/a"))):
        transport = ASGITransport(app=app)
        async with AsyncClient(transport=transport, base_url="http://test") as client:
            resp = await client.post(
                "/v1/retrieval/test", json={"knowledge_base_id": 1, "query": "hi"}, headers=auth_headers,
            )
    assert resp.status_code not in (401, 403)


def test_empty_configured_token_rejected_at_startup():
    """V2 (item 6): application MUST fail to start (not silently run) if
    RAG_INTERNAL_TOKEN is empty. Verified by spawning a fresh subprocess so
    module-level settings instantiation genuinely runs from scratch --
    this is not a mock, it's a real process exiting with an error."""
    env = dict(os.environ)
    env["RAG_INTERNAL_TOKEN"] = ""
    env["GEMINI_API_KEY"] = ""
    env["QDRANT_URL"] = "http://localhost:6333"
    backend_dir = os.path.join(os.path.dirname(__file__), "..", "backend")
    result = subprocess.run(
        [sys.executable, "-c", "from app.config import settings"],
        cwd=backend_dir, env=env, capture_output=True, text=True,
    )
    assert result.returncode != 0
    assert "RAG_INTERNAL_TOKEN" in result.stderr


def test_short_configured_token_rejected_at_startup():
    env = dict(os.environ)
    env["RAG_INTERNAL_TOKEN"] = "tooshort"
    env["GEMINI_API_KEY"] = ""
    env["QDRANT_URL"] = "http://localhost:6333"
    backend_dir = os.path.join(os.path.dirname(__file__), "..", "backend")
    result = subprocess.run(
        [sys.executable, "-c", "from app.config import settings"],
        cwd=backend_dir, env=env, capture_output=True, text=True,
    )
    assert result.returncode != 0
    assert "at least 32" in result.stderr


def test_whitespace_only_token_rejected_at_startup():
    env = dict(os.environ)
    env["RAG_INTERNAL_TOKEN"] = "   "
    env["GEMINI_API_KEY"] = ""
    env["QDRANT_URL"] = "http://localhost:6333"
    backend_dir = os.path.join(os.path.dirname(__file__), "..", "backend")
    result = subprocess.run(
        [sys.executable, "-c", "from app.config import settings"],
        cwd=backend_dir, env=env, capture_output=True, text=True,
    )
    assert result.returncode != 0


def test_valid_strong_token_accepted_at_startup():
    env = dict(os.environ)
    env["RAG_INTERNAL_TOKEN"] = "a" * 32
    env["GEMINI_API_KEY"] = ""
    env["QDRANT_URL"] = "http://localhost:6333"
    backend_dir = os.path.join(os.path.dirname(__file__), "..", "backend")
    result = subprocess.run(
        [sys.executable, "-c", "from app.config import settings; print('OK')"],
        cwd=backend_dir, env=env, capture_output=True, text=True,
    )
    assert result.returncode == 0
    assert "OK" in result.stdout
