"""Item 11: server-to-server RAG config sync, control-plane boundary preserved."""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from httpx import AsyncClient, ASGITransport

pytestmark = pytest.mark.asyncio


async def test_config_current_returns_defaults_initially(auth_headers):
    from app.main import app
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        resp = await client.get("/v1/config/current", headers=auth_headers)
    assert resp.status_code == 200
    body = resp.json()
    assert body["escalation_keywords"] == []
    assert isinstance(body["system_prompt"], str) and len(body["system_prompt"]) > 0


async def test_config_sync_updates_and_persists_within_process(auth_headers):
    from app.main import app
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        sync_resp = await client.post(
            "/v1/config/sync",
            json={"escalation_keywords": ["pricing", "quote"], "temperature": 0.2},
            headers=auth_headers,
        )
        assert sync_resp.status_code == 200
        assert sync_resp.json()["escalation_keywords"] == ["pricing", "quote"]
        assert sync_resp.json()["temperature"] == 0.2

        current_resp = await client.get("/v1/config/current", headers=auth_headers)
        assert current_resp.json()["escalation_keywords"] == ["pricing", "quote"]


async def test_config_sync_partial_update_preserves_other_fields(auth_headers):
    from app.main import app
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        await client.post("/v1/config/sync", json={"temperature": 0.9}, headers=auth_headers)
        resp = await client.post("/v1/config/sync", json={"escalation_keywords": ["urgent"]}, headers=auth_headers)
    body = resp.json()
    assert body["temperature"] == 0.9  # preserved from the earlier call
    assert body["escalation_keywords"] == ["urgent"]


async def test_config_sync_requires_auth():
    from app.main import app
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        resp = await client.post("/v1/config/sync", json={"temperature": 0.5})
    assert resp.status_code == 401


async def test_config_sync_never_touches_mysql():
    """
    Architectural guard, not just behavioral: runtime_config.py must not
    IMPORT any MySQL/database driver at all -- the control-plane boundary
    is enforced by rag-api simply having no capability to reach MySQL, not
    just by convention. Checks actual import statements only (the module's
    own comments legitimately discuss MySQL/AIKB in prose).
    """
    import app.runtime_config as rc
    import ast as ast_module
    import inspect
    tree = ast_module.parse(inspect.getsource(rc))
    imported_names = []
    for node in ast_module.walk(tree):
        if isinstance(node, ast_module.Import):
            imported_names.extend(alias.name for alias in node.names)
        elif isinstance(node, ast_module.ImportFrom):
            imported_names.append(node.module or "")
    forbidden = ("pymysql", "mysql", "mysqldb", "aiomysql", "mysqlclient")
    for name in imported_names:
        assert not any(f in name.lower() for f in forbidden), f"runtime_config.py imports a MySQL driver: {name}"
