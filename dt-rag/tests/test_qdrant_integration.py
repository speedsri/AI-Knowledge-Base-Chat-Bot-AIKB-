"""
Item 12: real Qdrant integration smoke test. SKIPPED BY DEFAULT.

This does not touch any existing Qdrant instance or the existing
production Docker project -- it only runs against whatever QDRANT_URL is
configured, and only when QDRANT_INTEGRATION_TEST=1 is explicitly set. It
creates and tears down its own uniquely-named, disposable collection
(never the configured QDRANT_COLLECTION), so even if accidentally pointed
at a shared instance it cannot corrupt real indexed data.

To run against the isolated dt-rag test stack:
    docker compose -p dt-rag -f docker-compose.yml --env-file .env up -d rag-qdrant
    QDRANT_INTEGRATION_TEST=1 QDRANT_URL=http://localhost:6333 pytest tests/test_qdrant_integration.py -v

NOT EXECUTED as part of the standard `pytest` run, and NOT executed while
preparing this delivery -- no Docker daemon was available in the sandbox
used to prepare this package. See TEST_PHASE_B.md for exactly what was and
was not actually run before packaging.
"""
import os
import sys
import uuid
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest

pytestmark = pytest.mark.asyncio

_RUN_INTEGRATION = os.environ.get("QDRANT_INTEGRATION_TEST") == "1"
skip_reason = "QDRANT_INTEGRATION_TEST=1 not set -- skipping real Qdrant integration test"


@pytest.mark.skipif(not _RUN_INTEGRATION, reason=skip_reason)
async def test_qdrant_full_roundtrip():
    from qdrant_client import AsyncQdrantClient, models

    qdrant_url = os.environ.get("QDRANT_URL", "http://localhost:6333")
    client = AsyncQdrantClient(url=qdrant_url)
    test_collection = f"dt_rag_test_{uuid.uuid4().hex[:8]}"  # never the real configured collection

    try:
        # 1. Collection created
        await client.create_collection(
            collection_name=test_collection,
            vectors_config=models.VectorParams(size=8, distance=models.Distance.COSINE),
        )
        await client.create_payload_index(test_collection, "is_published", models.PayloadSchemaType.BOOL)
        await client.create_payload_index(test_collection, "knowledge_base_id", models.PayloadSchemaType.INTEGER)

        # 2. Vector inserted
        await client.upsert(collection_name=test_collection, points=[
            models.PointStruct(id=str(uuid.uuid4()), vector=[0.1] * 8,
                                payload={"knowledge_base_id": 1, "is_published": True, "chunk_text": "kb1 published"}),
            models.PointStruct(id=str(uuid.uuid4()), vector=[0.1] * 8,
                                payload={"knowledge_base_id": 2, "is_published": True, "chunk_text": "kb2 published"}),
            models.PointStruct(id=str(uuid.uuid4()), vector=[0.1] * 8,
                                payload={"knowledge_base_id": 1, "is_published": False, "chunk_text": "kb1 unpublished"}),
        ])

        # 3. knowledge_base_id filter works
        resp = await client.query_points(
            collection_name=test_collection, query=[0.1] * 8,
            query_filter=models.Filter(must=[models.FieldCondition(key="knowledge_base_id", match=models.MatchValue(value=1))]),
            limit=10,
        )
        assert all(p.payload["knowledge_base_id"] == 1 for p in resp.points)
        assert len(resp.points) == 2  # both kb1 points (published + unpublished)

        # 4. unpublished filter works
        resp = await client.query_points(
            collection_name=test_collection, query=[0.1] * 8,
            query_filter=models.Filter(must=[
                models.FieldCondition(key="knowledge_base_id", match=models.MatchValue(value=1)),
                models.FieldCondition(key="is_published", match=models.MatchValue(value=True)),
            ]),
            limit=10,
        )
        assert len(resp.points) == 1
        assert resp.points[0].payload["chunk_text"] == "kb1 published"

        # 5. vector retrieved (basic sanity -- payload round-trips correctly)
        assert resp.points[0].payload["is_published"] is True

        # 6. dimension mismatch causes safe failure
        from app import vector_store as vs
        import app.config as cfg
        original_dim = cfg.settings.embedding_dimension
        original_collection = cfg.settings.qdrant_collection
        try:
            cfg.settings.embedding_dimension = 999  # deliberately wrong vs. the test collection's dim=8
            cfg.settings.qdrant_collection = test_collection
            vs._client = client
            with pytest.raises(vs.VectorStoreError):
                await vs.ensure_collection()
        finally:
            cfg.settings.embedding_dimension = original_dim
            cfg.settings.qdrant_collection = original_collection
            vs._client = None

    finally:
        await client.delete_collection(test_collection)  # never touches the real configured collection
