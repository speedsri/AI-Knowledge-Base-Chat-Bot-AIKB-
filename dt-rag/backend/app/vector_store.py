"""
Qdrant wrapper. V2 corrections:
  - Uses query_points() (qdrant-client 1.19.0) -- .search() was REMOVED
    entirely in this client version (confirmed by direct inspection: it
    does not exist as an attribute on AsyncQdrantClient in 1.19.0), so the
    prior .search()-based code would have crashed with AttributeError on
    first use against the pinned dependency.
  - Adds get_chunk_indices_for_version() (via scroll) and
    delete_points_by_ids(), used by ingestion/pipeline.py to implement
    non-destructive reindexing: upsert the new representation FIRST, and
    only delete stale leftover chunk points (from a previous, larger
    version) AFTER the new upsert has succeeded.
  - Startup readiness no longer assumes an immediately-reachable Qdrant;
    see app/main.py's startup handler for the retry wrapper (item 7 fix --
    Qdrant's official image healthcheck situation, see docker-compose.yml
    comments).

Startup validates the configured collection's vector size against
EMBEDDING_DIMENSION and refuses to start on mismatch -- never silently
recreates or deletes production vector data.
"""
import logging
import uuid
from qdrant_client import AsyncQdrantClient, models
from app.config import settings

logger = logging.getLogger("dt-rag.vector_store")

_NAMESPACE = uuid.UUID("6f9619ff-8b86-d011-b42d-00cf4fc964ff")
_client: AsyncQdrantClient | None = None


def get_client() -> AsyncQdrantClient:
    global _client
    if _client is None:
        _client = AsyncQdrantClient(url=settings.qdrant_url)
    return _client


class VectorStoreError(Exception):
    pass


def point_id_for(document_version_id: int, chunk_index: int) -> str:
    return str(uuid.uuid5(_NAMESPACE, f"{document_version_id}:{chunk_index}"))


async def ensure_collection():
    client = get_client()
    collections = await client.get_collections()
    names = [c.name for c in collections.collections]

    if settings.qdrant_collection not in names:
        logger.info(f"Creating Qdrant collection '{settings.qdrant_collection}' dim={settings.embedding_dimension}")
        await client.create_collection(
            collection_name=settings.qdrant_collection,
            vectors_config=models.VectorParams(size=settings.embedding_dimension, distance=models.Distance.COSINE),
        )
        for field, schema in [
            ("is_published", models.PayloadSchemaType.BOOL),
            ("knowledge_base_id", models.PayloadSchemaType.INTEGER),
            ("document_id", models.PayloadSchemaType.INTEGER),
            ("document_version_id", models.PayloadSchemaType.INTEGER),
        ]:
            await client.create_payload_index(settings.qdrant_collection, field, schema)
        return

    info = await client.get_collection(settings.qdrant_collection)
    existing_size = info.config.params.vectors.size
    if existing_size != settings.embedding_dimension:
        raise VectorStoreError(
            f"Qdrant collection '{settings.qdrant_collection}' has vector size {existing_size}, "
            f"but EMBEDDING_DIMENSION is configured as {settings.embedding_dimension}. Refusing to "
            f"start. See ARCHITECTURE_PHASE_B.md for the re-index procedure. This check exists "
            f"specifically to avoid silently corrupting retrieval."
        )


async def upsert_chunks(points: list[dict]):
    """points: [{id, vector, payload}, ...]"""
    for p in points:
        if len(p["vector"]) != settings.embedding_dimension:
            raise VectorStoreError(f"Vector length {len(p['vector'])} != configured dimension {settings.embedding_dimension}")
    client = get_client()
    try:
        await client.upsert(
            collection_name=settings.qdrant_collection,
            points=[models.PointStruct(id=p["id"], vector=p["vector"], payload=p["payload"]) for p in points],
        )
    except Exception as e:
        logger.error(f"qdrant.upsert_failed error_type={type(e).__name__}")
        raise VectorStoreError("upsert_failed") from e


async def get_chunk_indices_for_version(document_version_id: int) -> list[int]:
    """
    Used by the non-destructive reindex flow (ingestion/pipeline.py) to
    discover which chunk_index values currently exist for a document
    version BEFORE deciding what (if anything) is now stale, after a
    successful re-upsert with a possibly smaller chunk count.
    """
    client = get_client()
    indices: list[int] = []
    next_offset = None
    try:
        while True:
            points, next_offset = await client.scroll(
                collection_name=settings.qdrant_collection,
                scroll_filter=models.Filter(must=[
                    models.FieldCondition(key="document_version_id", match=models.MatchValue(value=document_version_id))
                ]),
                with_payload=["chunk_index"],
                with_vectors=False,
                limit=256,
                offset=next_offset,
            )
            indices.extend(p.payload.get("chunk_index") for p in points if p.payload.get("chunk_index") is not None)
            if next_offset is None:
                break
    except Exception as e:
        logger.error(f"qdrant.scroll_failed error_type={type(e).__name__}")
        raise VectorStoreError("scroll_failed") from e
    return indices


async def delete_points_by_ids(point_ids: list[str]):
    if not point_ids:
        return
    client = get_client()
    try:
        await client.delete(
            collection_name=settings.qdrant_collection,
            points_selector=models.PointIdsList(points=point_ids),
        )
    except Exception as e:
        logger.error(f"qdrant.delete_by_ids_failed error_type={type(e).__name__}")
        raise VectorStoreError("delete_failed") from e


async def delete_by_document_version(document_version_id: int):
    """
    Retained for full-removal use cases (e.g. a document version being
    deleted entirely in AIKB). NOT used by the reindex happy path anymore
    -- see ingestion/pipeline.py for the non-destructive upsert-then-clean
    sequence that replaced the old delete-first approach.
    """
    client = get_client()
    try:
        await client.delete(
            collection_name=settings.qdrant_collection,
            points_selector=models.FilterSelector(
                filter=models.Filter(must=[
                    models.FieldCondition(key="document_version_id", match=models.MatchValue(value=document_version_id))
                ])
            ),
        )
    except Exception as e:
        logger.error(f"qdrant.delete_by_version_failed error_type={type(e).__name__}")
        raise VectorStoreError("delete_failed") from e


async def search(
    query_vector: list[float],
    knowledge_base_id: int | None = None,
    top_k: int = 5,
    score_threshold: float = 0.72,
    include_unpublished: bool = False,
):
    """
    V2: uses query_points() -- the .search() method this previously called
    does not exist in qdrant-client 1.19.0 at all (confirmed by direct
    inspection of the installed client). query_points() returns an object
    with a `.points` list, not a bare list -- callers must use `.points`.
    """
    must = []
    if not include_unpublished:
        must.append(models.FieldCondition(key="is_published", match=models.MatchValue(value=True)))
    if knowledge_base_id is not None:
        must.append(models.FieldCondition(key="knowledge_base_id", match=models.MatchValue(value=knowledge_base_id)))

    try:
        client = get_client()
        response = await client.query_points(
            collection_name=settings.qdrant_collection,
            query=query_vector,
            query_filter=models.Filter(must=must) if must else None,
            limit=top_k,
            score_threshold=score_threshold,
            with_payload=True,
        )
        return response.points
    except Exception as e:
        logger.error(f"qdrant.query_points_failed error_type={type(e).__name__}")
        raise VectorStoreError("search_failed") from e


async def check_health() -> str:
    try:
        await get_client().get_collections()
        return "ok"
    except Exception:
        return "unreachable"
