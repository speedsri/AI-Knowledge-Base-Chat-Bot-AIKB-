import os
import sys
import pytest

os.environ.setdefault("RAG_INTERNAL_TOKEN", "test-token-32-characters-minimum-ok")
os.environ.setdefault("GEMINI_API_KEY", "")
os.environ.setdefault("QDRANT_URL", "http://localhost:6333")

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))


@pytest.fixture
def auth_headers():
    return {"Authorization": "Bearer test-token-32-characters-minimum-ok"}


@pytest.fixture
def wrong_auth_headers():
    return {"Authorization": "Bearer wrong-token-but-also-32-characters-long"}


@pytest.fixture(autouse=True)
def reset_runtime_config():
    """Ensure runtime_config state doesn't leak between tests."""
    from app import runtime_config
    runtime_config.reset_to_defaults()
    yield
    runtime_config.reset_to_defaults()
