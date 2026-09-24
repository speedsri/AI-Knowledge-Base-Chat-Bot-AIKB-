import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from unittest.mock import AsyncMock, MagicMock, patch

pytestmark = pytest.mark.asyncio


async def test_ensure_collection_creates_when_missing():
    from app import vector_store

    fake_client = AsyncMock()
    fake_collections = MagicMock()
    fake_collections.collections = []
    fake_client.get_collections = AsyncMock(return_value=fake_collections)
    fake_client.create_collection = AsyncMock()
    fake_client.create_payload_index = AsyncMock()

    with patch("app.vector_store.get_client", return_value=fake_client):
        await vector_store.ensure_collection()

    fake_client.create_collection.assert_called_once()


async def test_ensure_collection_raises_on_dimension_mismatch():
    from app import vector_store
    from app.config import settings

    fake_client = AsyncMock()
    existing_collection = MagicMock()
    existing_collection.name = settings.qdrant_collection
    fake_collections = MagicMock()
    fake_collections.collections = [existing_collection]
    fake_client.get_collections = AsyncMock(return_value=fake_collections)

    fake_info = MagicMock()
    fake_info.config.params.vectors.size = settings.embedding_dimension + 1
    fake_client.get_collection = AsyncMock(return_value=fake_info)

    with patch("app.vector_store.get_client", return_value=fake_client):
        with pytest.raises(vector_store.VectorStoreError):
            await vector_store.ensure_collection()


async def test_ensure_collection_passes_when_dimension_matches():
    from app import vector_store
    from app.config import settings

    fake_client = AsyncMock()
    existing_collection = MagicMock()
    existing_collection.name = settings.qdrant_collection
    fake_collections = MagicMock()
    fake_collections.collections = [existing_collection]
    fake_client.get_collections = AsyncMock(return_value=fake_collections)

    fake_info = MagicMock()
    fake_info.config.params.vectors.size = settings.embedding_dimension
    fake_client.get_collection = AsyncMock(return_value=fake_info)

    with patch("app.vector_store.get_client", return_value=fake_client):
        await vector_store.ensure_collection()


async def test_upsert_rejects_wrong_length_vector():
    from app import vector_store
    from app.config import settings

    wrong_length_vector = [0.1] * (settings.embedding_dimension + 5)
    with pytest.raises(vector_store.VectorStoreError):
        await vector_store.upsert_chunks([{"id": "x", "vector": wrong_length_vector, "payload": {}}])


def test_point_id_is_deterministic():
    from app import vector_store
    id1 = vector_store.point_id_for(document_version_id=5, chunk_index=2)
    id2 = vector_store.point_id_for(document_version_id=5, chunk_index=2)
    assert id1 == id2


def test_point_id_differs_for_different_chunks():
    from app import vector_store
    id1 = vector_store.point_id_for(document_version_id=5, chunk_index=2)
    id2 = vector_store.point_id_for(document_version_id=5, chunk_index=3)
    assert id1 != id2
