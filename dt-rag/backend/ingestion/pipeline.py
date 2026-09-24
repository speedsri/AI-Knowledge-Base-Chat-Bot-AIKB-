"""
V2 corrections:

Item 3 (MySQL must remain authoritative): index_crawled_pages() has been
REMOVED ENTIRELY. Crawled pages are never written to Qdrant directly, and
never assigned synthetic document_id/document_version_id values. The
crawler (crawler.py) only fetches, validates, and normalizes pages,
returning prepared results (url, title, text) for a FUTURE Phase C
handshake to hand to AIKB, which creates the real document/document_version
rows first, and only THEN calls /v1/reindex -- the sole path into Qdrant.

Item 4 (reindex must not delete good vectors before success):
reindex_document_version() now follows this sequence:
  1. chunk the (already normalized) text
  2. generate ALL embeddings first -- if this fails, nothing in Qdrant has
     been touched yet, so the previous working representation is untouched
  3. build all new point IDs/payloads
  4. upsert the new point set -- if THIS fails, again nothing previously
     indexed was deleted first, so a failed upsert leaves the old,
     previously-working data intact
  5. ONLY AFTER a successful upsert, look up which chunk_index values now
     exist for this document_version_id and delete any that are >= the new
     chunk count (i.e. leftover points from a previous, larger version that
     the new, smaller version no longer has a corresponding chunk for)

This makes reindexing idempotent: re-running it with identical content
produces identical point IDs (deterministic uuid5) and an upsert that is a
no-op on unchanged data, plus a cleanup step that finds nothing stale to
remove.
"""
import logging
from app import vector_store, gemini_client
from app.chunking import chunk_text, sha256_hex

logger = logging.getLogger("dt-rag.pipeline")


async def reindex_document_version(
    knowledge_base_id: int,
    document_id: int,
    document_version_id: int,
    title: str,
    canonical_url,
    normalized_content: str,
    content_hash: str,
    is_published: bool,
):
    """
    Returns (chunks_indexed, content_hash_verified). See module docstring
    for the non-destructive ordering this follows.
    """
    computed_hash = sha256_hex(normalized_content)
    hash_verified = computed_hash == content_hash.lower()
    if not hash_verified:
        logger.warning(
            f"pipeline.hash_mismatch document_version_id={document_version_id} "
            f"claimed={content_hash[:12]} computed={computed_hash[:12]}"
        )

    # Step 1-2: chunk, then embed ALL chunks BEFORE touching Qdrant at all.
    # If this raises (GeminiError propagates up through embed_texts), the
    # caller (app/main.py) returns 503 and the previously-indexed
    # representation for this document_version_id is completely untouched.
    chunks = chunk_text(normalized_content)
    if not chunks:
        # An empty/whitespace-only document: nothing to index. Any
        # previously-indexed chunks for this version are now stale and
        # should be removed, but only via the same "discover then delete"
        # path used below -- there is simply no new upsert step to precede it.
        existing_indices = await vector_store.get_chunk_indices_for_version(document_version_id)
        stale_ids = [vector_store.point_id_for(document_version_id, idx) for idx in existing_indices]
        await vector_store.delete_points_by_ids(stale_ids)
        return 0, hash_verified

    vectors = await gemini_client.embed_texts(chunks, task_type="RETRIEVAL_DOCUMENT")

    # Step 3: build all new points.
    points = []
    for idx, (chunk, vector) in enumerate(zip(chunks, vectors)):
        points.append({
            "id": vector_store.point_id_for(document_version_id, idx),
            "vector": vector,
            "payload": {
                "knowledge_base_id": knowledge_base_id,
                "document_id": document_id,
                "document_version_id": document_version_id,
                "chunk_index": idx,
                "content_hash": computed_hash,
                "title": title,
                "canonical_url": canonical_url,
                "chunk_text": chunk,
                "is_published": is_published,
            },
        })

    # Step 4: upsert the new representation. If this raises, nothing was
    # deleted beforehand -- the old representation (if any) remains intact
    # and searchable.
    await vector_store.upsert_chunks(points)

    # Step 5: ONLY NOW, after a successful upsert, clean up any stale
    # leftover points from a previous larger version of this same
    # document_version_id.
    new_chunk_count = len(points)
    existing_indices = await vector_store.get_chunk_indices_for_version(document_version_id)
    stale_indices = [idx for idx in existing_indices if idx >= new_chunk_count]
    if stale_indices:
        stale_ids = [vector_store.point_id_for(document_version_id, idx) for idx in stale_indices]
        await vector_store.delete_points_by_ids(stale_ids)
        logger.info(f"pipeline.stale_chunks_removed document_version_id={document_version_id} count={len(stale_ids)}")

    return new_chunk_count, hash_verified
