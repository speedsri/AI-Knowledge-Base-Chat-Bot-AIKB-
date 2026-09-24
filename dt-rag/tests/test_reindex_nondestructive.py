"""
Item 4: reindex must not delete good vectors before success. These tests
prove the actual ordering in ingestion/pipeline.py -- not just that it
looks right by inspection.
"""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from unittest.mock import AsyncMock, patch

pytestmark = pytest.mark.asyncio


async def test_embedding_failure_touches_qdrant_not_at_all():
    """If embedding fails, upsert/delete must NEVER be called -- the
    previously-indexed representation (if any) is left completely intact."""
    from ingestion.pipeline import reindex_document_version
    from app.gemini_client import GeminiError

    with patch("ingestion.pipeline.gemini_client.embed_texts", new=AsyncMock(side_effect=GeminiError("embed_failed"))), \
         patch("ingestion.pipeline.vector_store.upsert_chunks", new=AsyncMock()) as mock_upsert, \
         patch("ingestion.pipeline.vector_store.delete_points_by_ids", new=AsyncMock()) as mock_delete, \
         patch("ingestion.pipeline.vector_store.get_chunk_indices_for_version", new=AsyncMock()) as mock_get_indices:

        with pytest.raises(GeminiError):
            await reindex_document_version(
                knowledge_base_id=1, document_id=1, document_version_id=99,
                title="Test", canonical_url=None,
                normalized_content="Some real content here, long enough to chunk.",
                content_hash="a" * 64, is_published=True,
            )

        mock_upsert.assert_not_called()
        mock_delete.assert_not_called()
        mock_get_indices.assert_not_called()


async def test_upsert_failure_does_not_trigger_any_delete():
    """If the upsert itself fails, delete must never be called either --
    a failed upsert must not have deliberately erased anything beforehand,
    and must not attempt cleanup of a representation that was never
    successfully written."""
    from ingestion.pipeline import reindex_document_version
    from app.vector_store import VectorStoreError

    fake_vector = [0.1] * 768
    with patch("ingestion.pipeline.gemini_client.embed_texts", new=AsyncMock(return_value=[fake_vector])), \
         patch("ingestion.pipeline.vector_store.upsert_chunks", new=AsyncMock(side_effect=VectorStoreError("upsert_failed"))) as mock_upsert, \
         patch("ingestion.pipeline.vector_store.delete_points_by_ids", new=AsyncMock()) as mock_delete, \
         patch("ingestion.pipeline.vector_store.get_chunk_indices_for_version", new=AsyncMock()) as mock_get_indices:

        with pytest.raises(VectorStoreError):
            await reindex_document_version(
                knowledge_base_id=1, document_id=1, document_version_id=99,
                title="Test", canonical_url=None,
                normalized_content="Some real content here.",
                content_hash="a" * 64, is_published=True,
            )

        mock_upsert.assert_called_once()
        mock_delete.assert_not_called()
        mock_get_indices.assert_not_called()


async def test_successful_reindex_upserts_before_cleaning_stale_chunks():
    """Proves the ORDER: upsert must be called, and only AFTER it succeeds
    does the code look up + delete stale higher-index chunks left over from
    a previous, larger version."""
    from ingestion.pipeline import reindex_document_version

    fake_vector = [0.1] * 768
    call_order = []

    async def fake_upsert(points):
        call_order.append("upsert")

    async def fake_get_indices(document_version_id):
        call_order.append("get_indices")
        return [0, 1, 2, 3]  # previous version had 4 chunks (indices 0-3)

    async def fake_delete(ids):
        call_order.append("delete")

    with patch("ingestion.pipeline.gemini_client.embed_texts", new=AsyncMock(return_value=[fake_vector])), \
         patch("ingestion.pipeline.vector_store.upsert_chunks", new=fake_upsert), \
         patch("ingestion.pipeline.vector_store.get_chunk_indices_for_version", new=fake_get_indices), \
         patch("ingestion.pipeline.vector_store.delete_points_by_ids", new=fake_delete):

        chunks_indexed, hash_verified = await reindex_document_version(
            knowledge_base_id=1, document_id=1, document_version_id=99,
            title="Test", canonical_url=None,
            normalized_content="Short new content.",  # chunks to just 1 chunk now
            content_hash="a" * 64, is_published=True,
        )

    assert call_order == ["upsert", "get_indices", "delete"]
    assert chunks_indexed == 1


async def test_no_stale_chunks_means_no_delete_call():
    """If the new version has the same or more chunks than before, nothing
    is stale, and delete_points_by_ids must not be called at all."""
    from ingestion.pipeline import reindex_document_version

    fake_vector = [0.1] * 768
    with patch("ingestion.pipeline.gemini_client.embed_texts", new=AsyncMock(return_value=[fake_vector])), \
         patch("ingestion.pipeline.vector_store.upsert_chunks", new=AsyncMock()), \
         patch("ingestion.pipeline.vector_store.get_chunk_indices_for_version", new=AsyncMock(return_value=[0])), \
         patch("ingestion.pipeline.vector_store.delete_points_by_ids", new=AsyncMock()) as mock_delete:

        await reindex_document_version(
            knowledge_base_id=1, document_id=1, document_version_id=99,
            title="Test", canonical_url=None,
            normalized_content="Short content, one chunk only.",
            content_hash="a" * 64, is_published=True,
        )

        mock_delete.assert_not_called()


async def test_reindex_is_idempotent_same_content_same_point_ids():
    """Re-running reindex with identical content must compute identical
    deterministic point IDs -- an upsert of the same IDs with the same
    vectors is a true no-op at the storage layer."""
    from app import vector_store

    id_run1 = vector_store.point_id_for(document_version_id=42, chunk_index=0)
    id_run2 = vector_store.point_id_for(document_version_id=42, chunk_index=0)
    assert id_run1 == id_run2
