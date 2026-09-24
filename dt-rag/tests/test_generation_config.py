"""Item 6: Gemini 3.8 generation config must not send deprecated sampling params."""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from unittest.mock import AsyncMock, MagicMock, patch

pytestmark = pytest.mark.asyncio


def _fake_generate_response(text="An answer.\nCONFIDENCE: 0.8"):
    resp = MagicMock()
    resp.text = text
    return resp


async def test_gemini_3_8_flash_omits_temperature(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.generate_content = AsyncMock(return_value=_fake_generate_response())

    with patch("app.gemini_client.get_client", return_value=fake_client):
        await gemini_client.generate_answer(
            model="gemini-3.8-flash", system_prompt="sys", context_blocks=[],
            history=[], user_query="q", temperature=0.7,
        )

    config = fake_client.aio.models.generate_content.call_args.kwargs["config"]
    assert config.temperature is None
    assert config.top_p is None
    assert config.top_k is None


async def test_older_model_still_receives_temperature(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.generate_content = AsyncMock(return_value=_fake_generate_response())

    with patch("app.gemini_client.get_client", return_value=fake_client):
        await gemini_client.generate_answer(
            model="gemini-2.5-flash", system_prompt="sys", context_blocks=[],
            history=[], user_query="q", temperature=0.7,
        )

    config = fake_client.aio.models.generate_content.call_args.kwargs["config"]
    assert config.temperature == 0.7


async def test_gemini_3_7_flash_also_omits_sampling_params(monkeypatch):
    """Defensive coverage per the 3.6/3.7 'now deprecated' changelog notice."""
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.generate_content = AsyncMock(return_value=_fake_generate_response())

    with patch("app.gemini_client.get_client", return_value=fake_client):
        await gemini_client.generate_answer(
            model="gemini-3.7-flash", system_prompt="sys", context_blocks=[],
            history=[], user_query="q", temperature=0.7,
        )

    config = fake_client.aio.models.generate_content.call_args.kwargs["config"]
    assert config.temperature is None


async def test_max_output_tokens_still_sent_for_gemini_3_8(monkeypatch):
    """Only the deprecated sampling params are omitted -- other config
    fields (like max_output_tokens) must still be sent normally."""
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.generate_content = AsyncMock(return_value=_fake_generate_response())

    with patch("app.gemini_client.get_client", return_value=fake_client):
        await gemini_client.generate_answer(
            model="gemini-3.8-flash", system_prompt="sys", context_blocks=[],
            history=[], user_query="q", max_output_tokens=2048,
        )

    config = fake_client.aio.models.generate_content.call_args.kwargs["config"]
    assert config.max_output_tokens == 2048


def test_model_supports_sampling_params_helper():
    from app.gemini_client import _model_supports_sampling_params
    assert _model_supports_sampling_params("gemini-3.8-flash") is False
    assert _model_supports_sampling_params("gemini-3.7-flash") is False
    assert _model_supports_sampling_params("gemini-3.6-flash") is False
    assert _model_supports_sampling_params("gemini-2.5-flash") is True
    assert _model_supports_sampling_params("gemini-3.5-flash-lite") is True
