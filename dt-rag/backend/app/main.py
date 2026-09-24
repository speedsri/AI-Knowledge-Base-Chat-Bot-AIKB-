import time
import uuid
import logging
import asyncio
from fastapi import FastAPI, Depends, Request, HTTPException
from tenacity import retry, wait_fixed, stop_after_attempt, retry_if_exception_type

from app.config import settings
from app.logging_setup import configure_logging, log_event
from app import vector_store, gemini_client, jobs, auth, runtime_config
from app.gemini_client import GeminiError
from app.vector_store import VectorStoreError
from app.rate_limit import check_rate_limit
from app.request_size import RequestSizeLimitMiddleware
from app.grounding import (
    detect_escalation,
    retrieval_based_confidence,
    is_grounded,
    explicit_escalation_keyword_hit,
    small_talk_response,
)
from app.schemas import (
    ChatRequest, ChatResponse, SourceRef,
    RetrievalTestRequest, RetrievalTestResponse, RetrievalMatch,
    ReindexRequest, ReindexResponse,
    IngestionStartRequest, IngestionStartResponse, IngestionStatusResponse,
    PreparedPage, IngestionResultResponse,
    DocumentVersionDeleteRequest, DocumentVersionDeleteResponse,
    ProviderConfigureRequest, ProviderRuntimeResponse,
    ProviderTestResponse, HealthResponse, SystemStatusResponse,
    RagConfigSyncRequest, RagConfigResponse,
)

configure_logging()
logger = logging.getLogger("dt-rag-api")

app = FastAPI(title="Dynamic Technologies RAG API - Phase B", version="0.3.0")

# New on every RAG API process start. Used only to detect restarts.
# It is not a credential or secret.
RAG_BOOT_ID = str(uuid.uuid4())
app.add_middleware(RequestSizeLimitMiddleware)


@retry(
    wait=wait_fixed(settings.qdrant_startup_retry_wait_seconds),
    stop=stop_after_attempt(settings.qdrant_startup_retry_attempts),
    retry=retry_if_exception_type(Exception),
    reraise=True,
)
async def _ensure_collection_with_retry():
    """
    V2 fix (item 7): rag-api no longer assumes Qdrant is reachable the
    instant this container starts (the prior Docker Compose healthcheck
    approach assumed `wget` exists inside the official qdrant/qdrant image,
    which was never verified and is a common source of containers stuck in
    "unhealthy"/"starting" forever). Instead, rag-api itself retries
    connecting to Qdrant with a bounded backoff at startup. If Qdrant is
    still unreachable after all retries, this re-raises and the container
    exits non-zero (so `docker compose ps` shows a real failure, not a
    silently-degraded but "running" process).
    """
    await vector_store.ensure_collection()


@app.on_event("startup")
async def startup():
    await _ensure_collection_with_retry()
    logger.info(
        "rag-api ready",
        extra={"fields": {
            "embedding_model": settings.embedding_model,
            "embedding_dimension": settings.embedding_dimension,
            "generation_model": settings.generation_model,
        }},
    )


def _request_id() -> str:
    return str(uuid.uuid4())


@app.get("/health", response_model=HealthResponse)
async def health():
    qdrant_status = await vector_store.check_health()
    gemini_status = "configured" if gemini_client.is_configured() else "not_configured"
    overall = "ok" if qdrant_status == "ok" else "degraded"
    return HealthResponse(status=overall, dependencies={"qdrant": qdrant_status, "gemini": gemini_status})


@app.get(
    "/v1/provider/runtime",
    response_model=ProviderRuntimeResponse
)
async def provider_runtime(
    _: bool = Depends(auth.verify_internal_token),
):
    return ProviderRuntimeResponse(
        configured=gemini_client.is_configured(),
        credential_source=gemini_client.runtime_credential_source(),
        boot_id=RAG_BOOT_ID,
    )


@app.post(
    "/v1/provider/configure",
    response_model=ProviderTestResponse
)
async def provider_configure(
    body: ProviderConfigureRequest,
    request: Request,
    _: bool = Depends(auth.verify_internal_token),
):
    check_rate_limit(request)

    result = await gemini_client.configure_api_key(
        body.api_key
    )

    return ProviderTestResponse(**result)


@app.post("/v1/provider/test", response_model=ProviderTestResponse)
async def provider_test(request: Request, _: bool = Depends(auth.verify_internal_token)):
    check_rate_limit(request)
    result = await gemini_client.test_provider()
    return ProviderTestResponse(**result)


@app.post("/v1/retrieval/test", response_model=RetrievalTestResponse)
async def retrieval_test(body: RetrievalTestRequest, request: Request, _: bool = Depends(auth.verify_internal_token)):
    check_rate_limit(request)
    rid = _request_id()

    try:
        query_vector = await gemini_client.embed_text(body.query, task_type="RETRIEVAL_QUERY")
        hits = await vector_store.search(
            query_vector,
            knowledge_base_id=body.knowledge_base_id,
            top_k=body.top_k or settings.top_k_default,
            score_threshold=body.similarity_threshold or settings.similarity_threshold_default,
        )
    except (GeminiError, VectorStoreError) as e:
        log_event(logger, "error", "retrieval_test.failed", request_id=rid, error=str(e))
        raise HTTPException(503, "Retrieval temporarily unavailable.")

    matches = [
        RetrievalMatch(
            document_id=h.payload.get("document_id"),
            document_version_id=h.payload.get("document_version_id"),
            title=h.payload.get("title"),
            source_url=h.payload.get("canonical_url"),
            score=h.score,
            chunk_index=h.payload.get("chunk_index", 0),
            text_preview=(h.payload.get("chunk_text", "")[:280]),
        )
        for h in hits
    ]
    log_event(logger, "info", "retrieval_test.completed", request_id=rid,
              knowledge_base_id=body.knowledge_base_id, match_count=len(matches))
    return RetrievalTestResponse(matches=matches)


@app.post("/v1/chat", response_model=ChatResponse)
async def chat(body: ChatRequest, request: Request, _: bool = Depends(auth.verify_internal_token)):
    check_rate_limit(request)
    rid = _request_id()
    t0 = time.perf_counter()
    cfg = runtime_config.get_config()
    threshold = cfg["similarity_threshold"] or settings.similarity_threshold_default
    top_k = cfg["top_k_retrieval"] or settings.top_k_default

    small_talk_answer = small_talk_response(
        body.message
    )

    explicit_escalation = (
        explicit_escalation_keyword_hit(
            body.message,
            cfg["escalation_keywords"],
        )
    )

    if (
        small_talk_answer is not None
        and not explicit_escalation
    ):
        latency_ms = int(
            (time.perf_counter() - t0) * 1000
        )

        log_event(
            logger,
            "info",
            "chat.small_talk",
            request_id=rid,
            knowledge_base_id=body.knowledge_base_id,
            channel=body.channel,
            latency_ms=latency_ms,
        )

        return ChatResponse(
            answer=small_talk_answer,
            grounded=False,
            confidence=0.0,
            escalated=False,
            model="none",
            sources=[],
            latency_ms=latency_ms,
        )

    try:
        retrieval_query = body.message

        if body.history:
            recent_history = body.history[-4:]

            history_for_retrieval = "\n".join(
                f"{item.role.upper()}: {item.content}"
                for item in recent_history
            )

            retrieval_query = (
                f"{history_for_retrieval}\n"
                f"CURRENT USER MESSAGE: {body.message}"
            )

            # Preserve the newest/current part if the combined text is large.
            retrieval_query = retrieval_query[-8000:]

        query_vector = await gemini_client.embed_text(
            retrieval_query,
            task_type="RETRIEVAL_QUERY",
        )
        hits = await vector_store.search(
            query_vector, knowledge_base_id=body.knowledge_base_id, top_k=top_k, score_threshold=threshold,
        )
    except (GeminiError, VectorStoreError) as e:
        log_event(logger, "error", "chat.retrieval_failed", request_id=rid, error=str(e))
        raise HTTPException(503, "Knowledge retrieval temporarily unavailable. Please try again shortly.")

    top_score = hits[0].score if hits else None

    # V2 (item 9): if retrieval found nothing above threshold, do NOT spend
    # a Gemini generation call just to have it say "I don't know." Return a
    # deterministic, no-fabrication response immediately.
    if not hits:
        latency_ms = int((time.perf_counter() - t0) * 1000)
        log_event(logger, "info", "chat.no_context_short_circuit", request_id=rid,
                  knowledge_base_id=body.knowledge_base_id, channel=body.channel, latency_ms=latency_ms)
        return ChatResponse(
            answer=(
                "I don't have enough information in the knowledge base to answer that. "
                "I'll flag this for a team member to follow up."
            ),
            grounded=False,
            confidence=0.0,
            escalated=True,
            model="none",
            sources=[],
            latency_ms=latency_ms,
        )

    context_blocks = [
        {"text": h.payload.get("chunk_text", ""), "source_url": h.payload.get("canonical_url"), "title": h.payload.get("title")}
        for h in hits
    ]

    history = [
        item.model_dump()
        for item in body.history
    ]

    model = settings.generation_model
    try:
        answer, _self_reported = await gemini_client.generate_answer(
            model=model, system_prompt=cfg["system_prompt"], context_blocks=context_blocks,
            history=history, user_query=body.message, temperature=cfg["temperature"],
        )
    except GeminiError as e:
        log_event(logger, "warning", "chat.primary_model_failed", request_id=rid, model=model, error=e.error_code)
        model = settings.generation_fallback_model
        try:
            answer, _self_reported = await gemini_client.generate_answer(
                model=model, system_prompt=cfg["system_prompt"], context_blocks=context_blocks,
                history=history, user_query=body.message, temperature=cfg["temperature"],
            )
        except GeminiError as e2:
            log_event(logger, "error", "chat.generation_failed", request_id=rid, error=e2.error_code)
            raise HTTPException(503, "AI generation temporarily unavailable. Please try again shortly.")

    latency_ms = int((time.perf_counter() - t0) * 1000)

    # V2 (item 10): confidence/grounded are computed from RETRIEVAL EVIDENCE,
    # not the model's self-reported number (which is discarded above as
    # `_self_reported` -- retained only if a future logging need arises,
    # never used to override this computation).
    confidence = retrieval_based_confidence(top_score, threshold)
    grounded = is_grounded(top_score, threshold, has_context=True)
    escalated = detect_escalation(body.message, confidence, cfg["escalation_keywords"], threshold, top_score)

    sources = [
        SourceRef(
            document_id=h.payload.get("document_id"),
            document_version_id=h.payload.get("document_version_id"),
            title=h.payload.get("title"),
            source_url=h.payload.get("canonical_url"),
            score=h.score,
        )
        for h in hits
    ]

    log_event(logger, "info", "chat.completed", request_id=rid, knowledge_base_id=body.knowledge_base_id,
              channel=body.channel, model=model, confidence=confidence, escalated=escalated, latency_ms=latency_ms)

    return ChatResponse(
        answer=answer, grounded=grounded, confidence=confidence, escalated=escalated,
        model=model, sources=sources, latency_ms=latency_ms,
    )


@app.post("/v1/reindex", response_model=ReindexResponse)
async def reindex(body: ReindexRequest, request: Request, _: bool = Depends(auth.verify_internal_token)):
    check_rate_limit(request)
    rid = _request_id()

    from ingestion.pipeline import reindex_document_version
    try:
        chunks_indexed, hash_verified = await reindex_document_version(
            knowledge_base_id=body.knowledge_base_id,
            document_id=body.document_id,
            document_version_id=body.document_version_id,
            title=body.title,
            canonical_url=body.canonical_url,
            normalized_content=body.normalized_content,
            content_hash=body.content_hash,
            is_published=body.is_published,
        )
    except (GeminiError, VectorStoreError) as e:
        log_event(logger, "error", "reindex.failed", request_id=rid, document_version_id=body.document_version_id, error=str(e))
        raise HTTPException(503, "Indexing temporarily unavailable. Please try again shortly. The previously indexed content, if any, has not been removed.")

    log_event(logger, "info", "reindex.completed", request_id=rid, document_id=body.document_id,
              document_version_id=body.document_version_id, chunks_indexed=chunks_indexed)
    return ReindexResponse(ok=True, chunks_indexed=chunks_indexed, content_hash_verified=hash_verified)


@app.post("/v1/document-version/delete", response_model=DocumentVersionDeleteResponse)
async def document_version_delete(
    body: DocumentVersionDeleteRequest, request: Request, _: bool = Depends(auth.verify_internal_token)
):
    """
    Phase B.2 (live baseline). Deletes all Qdrant points for ONE specific
    document_version_id -- used by AIKB's manual-document-edit path, and
    now also by Phase C's WebsiteCrawlImporter, to remove a PREVIOUS
    version's vectors only after the NEW version has already been
    successfully reindexed (see Blocker 1 in CHANGELOG_PHASE_C_V2.md --
    /v1/reindex only cleans up stale chunks of the SAME document_version_id
    it was given; it does not know about or touch any other version).
    """
    check_rate_limit(request)
    rid = _request_id()

    try:
        await vector_store.delete_by_document_version(body.document_version_id)
    except VectorStoreError as e:
        log_event(logger, "error", "document_version_delete.failed", request_id=rid,
                  document_version_id=body.document_version_id, error=str(e))
        raise HTTPException(503, "Deletion temporarily unavailable. Please try again shortly.")

    log_event(logger, "info", "document_version_delete.completed", request_id=rid,
              document_version_id=body.document_version_id)
    return DocumentVersionDeleteResponse(ok=True, document_version_id=body.document_version_id)


@app.post("/v1/ingestion/start", response_model=IngestionStartResponse)
async def ingestion_start(body: IngestionStartRequest, request: Request, _: bool = Depends(auth.verify_internal_token)):
    check_rate_limit(request)
    job_id = jobs.create_job(external_job_id=body.external_job_id)

    asyncio.create_task(_run_crawl_job(job_id, body))

    log_event(logger, "info", "ingestion.started", job_id=job_id, knowledge_base_id=body.knowledge_base_id,
              origin_url=body.origin_url)
    return IngestionStartResponse(job_id=job_id, status="pending")


async def _run_crawl_job(job_id: str, body: IngestionStartRequest):
    """
    Crawls and prepares pages ONLY. Does not write anything to Qdrant.
    `documents_changed` here means "pages successfully fetched and
    normalized" (prepared, awaiting AIKB to pull via GET
    /v1/ingestion/result/{job_id} and import), NOT "pages indexed" --
    nothing is indexed by this path.

    PHASE C: every successfully crawled page's content_hash is computed
    here (via the existing app.chunking.sha256_hex -- the same function
    used by /v1/reindex, so hashing is consistent across both paths) and
    the full prepared page list (success and failure entries alike) is
    stored on the job via jobs.update_job(..., pages=...), retrievable via
    GET /v1/ingestion/result/{job_id}. ingestion/crawler.py itself is
    UNCHANGED -- content_hash is derived here from the page text the
    crawler already returns, not added to the crawler's own output.
    """
    from ingestion.crawler import crawl
    from app.chunking import sha256_hex

    jobs.update_job(job_id, status=jobs.JobStatus.RUNNING.value)
    try:
        result = await crawl(
            origin_url=body.origin_url,
            max_pages=body.max_pages,
            crawl_delay_seconds=body.crawl_delay_seconds,
            request_timeout_seconds=body.request_timeout_seconds,
            max_document_size_kb=body.max_document_size_kb,
            excluded_path_patterns=body.excluded_path_patterns,
        )

        prepared_pages = [
            {
                "canonical_url": page["url"],
                "title": page.get("title"),
                "normalized_content": page["text"],
                "content_hash": sha256_hex(page["text"]),
                "success": True,
                "error": None,
            }
            for page in result.pages
        ] + [
            {
                "canonical_url": failure["url"],
                "title": None,
                "normalized_content": None,
                "content_hash": None,
                "success": False,
                "error": failure.get("reason"),
            }
            for failure in result.failed
        ]

        jobs.update_job(
            job_id, status=jobs.JobStatus.DONE.value,
            pages_crawled=len(result.pages) + len(result.failed),
            documents_changed=len(result.pages),   # "prepared", not "indexed" -- see docstring
            documents_unchanged=0,
            documents_failed=len(result.failed),
            pages=prepared_pages,
            pages_purged=False,
        )
        log_event(logger, "info", "ingestion.completed", job_id=job_id, pages_prepared=len(result.pages))
    except Exception as e:
        jobs.update_job(job_id, status=jobs.JobStatus.FAILED.value, error=str(e))
        log_event(logger, "error", "ingestion.failed", job_id=job_id, error=str(e))


@app.get("/v1/ingestion/status/{job_id}", response_model=IngestionStatusResponse)
async def ingestion_status(job_id: str, _: bool = Depends(auth.verify_internal_token)):
    job = jobs.get_job(job_id)
    if job is None:
        raise HTTPException(404, "Job not found (job state is in-memory and does not survive a rag-api restart)")
    return IngestionStatusResponse(**job)


@app.get("/v1/ingestion/result/{job_id}", response_model=IngestionResultResponse)
async def ingestion_result(job_id: str, _: bool = Depends(auth.verify_internal_token)):
    """
    PHASE C: returns the prepared page results for a crawl job -- the
    handshake point AIKB uses to import authoritative MySQL content
    before ever calling /v1/reindex. Returns an empty `pages` list (with
    `pages_available=true`) while the job is still pending/running --
    callers should check `status` first. If the job's page payload has
    already been purged (TTL expiry) or the job itself was evicted under
    the retention cap, this returns `pages_available=false` with an empty
    list rather than a 404, so callers can distinguish "nothing to import
    because it already happened/expired" from "the job id is simply wrong"
    (the latter still raises 404, matching /v1/ingestion/status/{job_id}'s
    existing behavior).
    """
    job = jobs.get_job(job_id)
    if job is None:
        raise HTTPException(404, "Job not found (job state is in-memory and does not survive a rag-api restart)")

    pages = job.get("pages")
    pages_available = pages is not None
    return IngestionResultResponse(
        job_id=job_id,
        status=job["status"],
        pages=[PreparedPage(**p) for p in (pages or [])],
        pages_available=pages_available,
    )


@app.get("/v1/system/status", response_model=SystemStatusResponse)
async def system_status(_: bool = Depends(auth.verify_internal_token)):
    return SystemStatusResponse(
        embedding_model=settings.embedding_model,
        embedding_dimension=settings.embedding_dimension,
        qdrant_collection=settings.qdrant_collection,
        generation_model=settings.generation_model,
        generation_fallback_model=settings.generation_fallback_model,
        active_ingestion_jobs=jobs.job_count(),
    )


# ---------------------------------------------------------------------
# V2 addition (item 11): server-to-server RAG config sync from AIKB.
# ---------------------------------------------------------------------
@app.post("/v1/config/sync", response_model=RagConfigResponse)
async def config_sync(body: RagConfigSyncRequest, request: Request, _: bool = Depends(auth.verify_internal_token)):
    check_rate_limit(request)
    updated = runtime_config.sync_config(**body.model_dump())
    return RagConfigResponse(**updated)


@app.get("/v1/config/current", response_model=RagConfigResponse)
async def config_current(_: bool = Depends(auth.verify_internal_token)):
    return RagConfigResponse(**runtime_config.get_config())
