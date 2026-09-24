"""
Item 6: tests that would have caught the gemini-embedding-2 batching bug.
Mocks the SDK boundary (client.aio.models.embed_content) so these run
without a real API key, but assert on the ACTUAL request shape sent
(Content-wrapped vs. plain list, task_type present/absent) and on the
response-count guard.
"""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from unittest.mock import AsyncMock, MagicMock, patch

pytestmark = pytest.mark.asyncio


def _fake_embed_response(count, dim=768):
    resp = MagicMock()
    resp.embeddings = [MagicMock(values=[0.1] * dim) for _ in range(count)]
    return resp


async def test_one_input_returns_one_embedding_embedding_2(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-2")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(1))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        result = await gemini_client.embed_texts(["one document"], task_type="RETRIEVAL_DOCUMENT")

    assert len(result) == 1
    assert len(result[0]) == 768


async def test_two_chunks_request_two_content_objects_embedding_2(monkeypatch):
    """The core batching-bug regression test: for gemini-embedding-2, each
    input must be wrapped in its own types.Content object -- NOT passed as
    a plain list of strings (which is what caused the aggregation bug)."""
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-2")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client
    from google.genai import types

    fake_client = MagicMock()
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(2))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        result = await gemini_client.embed_texts(["chunk one", "chunk two"], task_type="RETRIEVAL_DOCUMENT")

    assert len(result) == 2
    call_kwargs = fake_client.aio.models.embed_content.call_args.kwargs
    contents_sent = call_kwargs["contents"]
    assert len(contents_sent) == 2
    assert all(isinstance(c, types.Content) for c in contents_sent)


async def test_ten_chunks_returns_exactly_ten_vectors_embedding_2(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-2")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.embed_batch_size", 32)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(10))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        result = await gemini_client.embed_texts([f"chunk {i}" for i in range(10)], task_type="RETRIEVAL_DOCUMENT")

    assert len(result) == 10


async def test_embedding_count_mismatch_raises_safe_error(monkeypatch):
    """Simulates the actual bug: 3 inputs requested, provider returns only 1
    (the real, documented gemini-embedding-2 aggregation behavior when
    Content-wrapping is NOT applied). Must raise, never silently
    zip/truncate."""
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-2")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client
    from app.gemini_client import GeminiError

    fake_client = MagicMock()
    # Simulate the bug: 3 requested, only 1 returned.
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(1))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        with pytest.raises(GeminiError) as exc_info:
            await gemini_client.embed_texts(["a", "b", "c"], task_type="RETRIEVAL_DOCUMENT")

    assert exc_info.value.error_code == "embedding_count_mismatch"


async def test_embedding_2_does_not_send_task_type(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-2")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(1))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        await gemini_client.embed_texts(["a query"], task_type="RETRIEVAL_QUERY")

    call_kwargs = fake_client.aio.models.embed_content.call_args.kwargs
    config = call_kwargs["config"]
    assert config.task_type is None


async def test_embedding_2_applies_retrieval_query_instruction(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-2")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(1))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        await gemini_client.embed_texts(["what is the price"], task_type="RETRIEVAL_QUERY")

    contents_sent = fake_client.aio.models.embed_content.call_args.kwargs["contents"]
    embedded_text = contents_sent[0].parts[0].text
    assert embedded_text == "task: search result | query: what is the price"


async def test_embedding_2_applies_retrieval_document_instruction(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-2")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(1))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        await gemini_client.embed_texts(["Our services include..."], task_type="RETRIEVAL_DOCUMENT")

    contents_sent = fake_client.aio.models.embed_content.call_args.kwargs["contents"]
    embedded_text = contents_sent[0].parts[0].text
    assert embedded_text.startswith("title: none | text: ")
    assert "Our services include..." in embedded_text


async def test_embedding_2_query_and_document_formatted_differently():
    """Do not treat query and document text identically -- asymmetric
    retrieval formatting must actually differ."""
    from app.gemini_client import _prepare_retrieval_text
    query_text = _prepare_retrieval_text("same text", "RETRIEVAL_QUERY")
    doc_text = _prepare_retrieval_text("same text", "RETRIEVAL_DOCUMENT")
    assert query_text != doc_text


async def test_embedding_001_retains_native_task_type(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-001")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(1))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        await gemini_client.embed_texts(["a document"], task_type="RETRIEVAL_DOCUMENT")

    call_kwargs = fake_client.aio.models.embed_content.call_args.kwargs
    assert call_kwargs["config"].task_type == "RETRIEVAL_DOCUMENT"
    # 001 family: plain strings, NOT wrapped in Content objects.
    assert call_kwargs["contents"] == ["a document"]


async def test_embedding_001_multiple_inputs_not_content_wrapped(monkeypatch):
    """gemini-embedding-001 already returns one embedding per input for a
    plain list -- it must NOT be wrapped in Content objects (that's only
    required/correct for the -2 family)."""
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-001")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    fake_client.aio.models.embed_content = AsyncMock(return_value=_fake_embed_response(3))

    with patch("app.gemini_client.get_client", return_value=fake_client):
        result = await gemini_client.embed_texts(["a", "b", "c"], task_type="RETRIEVAL_DOCUMENT")

    assert len(result) == 3
    call_kwargs = fake_client.aio.models.embed_content.call_args.kwargs
    assert call_kwargs["contents"] == ["a", "b", "c"]


async def test_embedding_001_manually_normalizes_truncated_output(monkeypatch):
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-001")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    resp = MagicMock()
    resp.embeddings = [MagicMock(values=[3.0, 4.0] + [0.0] * 766)]  # norm = 5.0, not unit length
    fake_client.aio.models.embed_content = AsyncMock(return_value=resp)

    with patch("app.gemini_client.get_client", return_value=fake_client):
        result = await gemini_client.embed_texts(["text"], task_type="RETRIEVAL_QUERY")

    norm = sum(x * x for x in result[0]) ** 0.5
    assert abs(norm - 1.0) < 1e-6


async def test_embedding_2_does_not_manually_renormalize(monkeypatch):
    """gemini-embedding-2 auto-normalizes -- this module must trust that
    and NOT apply its own (redundant) manual normalization step, so the
    provider's actual output is preserved unmodified."""
    monkeypatch.setattr("app.gemini_client.settings.embedding_model", "gemini-embedding-2")
    monkeypatch.setattr("app.gemini_client.settings.embedding_dimension", 768)
    monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")
    from app import gemini_client

    fake_client = MagicMock()
    resp = MagicMock()
    # Deliberately non-unit-length vector -- if this module wrongly
    # re-normalized it, the test would fail on the exact-value assertion below.
    non_unit_vector = [3.0, 4.0] + [0.0] * 766
    resp.embeddings = [MagicMock(values=non_unit_vector)]
    fake_client.aio.models.embed_content = AsyncMock(return_value=resp)

    with patch("app.gemini_client.get_client", return_value=fake_client):
        result = await gemini_client.embed_texts(["text"], task_type="RETRIEVAL_QUERY")

    assert result[0] == non_unit_vector  # untouched, not re-normalized
