from typing import Literal

from pydantic import BaseModel, Field, field_validator


class ChatHistoryMessage(BaseModel):
    role: Literal["user", "assistant"]
    content: str = Field(..., min_length=1, max_length=4000)


class ChatRequest(BaseModel):
    knowledge_base_id: int = Field(..., ge=1)
    conversation_ref: str = Field(..., min_length=1, max_length=200)
    channel: str = Field(..., min_length=1, max_length=40)
    message: str = Field(..., min_length=1, max_length=4000)
    history: list[ChatHistoryMessage] = Field(
        default_factory=list,
        max_length=6,
    )

    @field_validator("history")
    @classmethod
    def history_total_size_must_be_bounded(
        cls,
        value: list[ChatHistoryMessage],
    ) -> list[ChatHistoryMessage]:
        total_chars = sum(len(item.content) for item in value)

        if total_chars > 6000:
            raise ValueError(
                "conversation history must not exceed 6000 characters"
            )

        return value


class SourceRef(BaseModel):
    document_id: int
    document_version_id: int
    title: str | None = None
    source_url: str | None = None
    score: float


class ChatResponse(BaseModel):
    answer: str
    grounded: bool
    confidence: float
    escalated: bool
    model: str
    sources: list[SourceRef]
    latency_ms: int


class RetrievalTestRequest(BaseModel):
    knowledge_base_id: int = Field(..., ge=1)
    query: str = Field(..., min_length=1, max_length=4000)
    top_k: int | None = Field(None, ge=1, le=50)
    similarity_threshold: float | None = Field(None, ge=0.0, le=1.0)


class RetrievalMatch(BaseModel):
    document_id: int
    document_version_id: int
    title: str | None = None
    source_url: str | None = None
    score: float
    chunk_index: int
    text_preview: str


class RetrievalTestResponse(BaseModel):
    matches: list[RetrievalMatch]


class ReindexRequest(BaseModel):
    knowledge_base_id: int = Field(..., ge=1)
    document_id: int = Field(..., ge=1)
    document_version_id: int = Field(..., ge=1)
    title: str = Field(..., min_length=1, max_length=500)
    canonical_url: str | None = Field(None, max_length=2000)
    # V2: bounded (item 8) -- 1.5M chars is generous for any real document
    # version's text while still being a real limit, defense-in-depth
    # alongside the global MAX_REQUEST_BODY_BYTES middleware.
    normalized_content: str = Field(..., min_length=1, max_length=1_500_000)
    content_hash: str = Field(..., min_length=64, max_length=64)
    is_published: bool = True

    @field_validator("content_hash")
    @classmethod
    def hash_must_be_hex(cls, v: str) -> str:
        try:
            int(v, 16)
        except ValueError:
            raise ValueError("content_hash must be a hex-encoded SHA-256 digest")
        return v.lower()


class ReindexResponse(BaseModel):
    ok: bool
    chunks_indexed: int
    content_hash_verified: bool


# ---------------------------------------------------------------------
# Phase B.2 (live baseline, reconstructed here for merge purposes):
# POST /v1/document-version/delete
# ---------------------------------------------------------------------
class DocumentVersionDeleteRequest(BaseModel):
    document_version_id: int = Field(..., ge=1)


class DocumentVersionDeleteResponse(BaseModel):
    ok: bool
    document_version_id: int


class IngestionStartRequest(BaseModel):
    knowledge_base_id: int = Field(..., ge=1)
    origin_url: str = Field(..., min_length=1, max_length=2000)
    max_pages: int | None = Field(None, ge=1, le=2000)
    crawl_delay_seconds: float | None = Field(None, ge=0.5, le=30.0)
    request_timeout_seconds: float | None = Field(None, ge=5.0, le=120.0)
    max_document_size_kb: int | None = Field(None, ge=64, le=20480)
    excluded_path_patterns: list[str] = Field(default_factory=list)
    external_job_id: str | None = Field(None, max_length=100)


class IngestionStartResponse(BaseModel):
    job_id: str
    status: str


class IngestionStatusResponse(BaseModel):
    job_id: str
    external_job_id: str | None
    status: str
    pages_crawled: int
    documents_changed: int
    documents_unchanged: int
    documents_failed: int
    error: str | None
    created_at: str
    updated_at: str


# ---------------------------------------------------------------------
# PHASE C: GET /v1/ingestion/result/{job_id}
# ---------------------------------------------------------------------
class PreparedPage(BaseModel):
    """One crawled-and-normalized page, ready for AIKB to import into
    MySQL and (only then) reindex. No secrets ever appear here -- this is
    exactly the same normalized text the crawler already extracts; nothing
    additional is exposed."""
    canonical_url: str
    title: str | None = None
    normalized_content: str | None = None  # None when success=False
    content_hash: str | None = None        # None when success=False
    success: bool
    error: str | None = None               # populated only when success=False


class IngestionResultResponse(BaseModel):
    job_id: str
    status: str
    pages: list[PreparedPage]
    pages_available: bool  # False if this job's page payload was already purged (TTL) or evicted (retention cap ran it out)



class ProviderConfigureRequest(BaseModel):
    """
    Server-to-server runtime Gemini credential update.

    The API key is accepted only over the authenticated internal API.
    It is never returned in any response or written to logs.
    """
    api_key: str = Field(..., min_length=1, max_length=1024)


class ProviderRuntimeResponse(BaseModel):
    configured: bool
    credential_source: str
    boot_id: str


class ProviderTestResponse(BaseModel):
    provider: str
    configured: bool
    healthy: bool
    generation_model: str
    embedding_model: str
    embedding_dimension: int
    latency_ms: int | None
    error_code: str | None


class RagConfigSyncRequest(BaseModel):
    """
    V2 addition (item 11). Called by AIKB whenever its RAG Settings screen
    is saved. All fields optional -- only supplied fields are updated;
    omitted fields keep their current synced (or default) value.
    """
    system_prompt: str | None = Field(None, max_length=8000)
    temperature: float | None = Field(None, ge=0.0, le=2.0)
    top_k_retrieval: int | None = Field(None, ge=1, le=50)
    similarity_threshold: float | None = Field(None, ge=0.0, le=1.0)
    escalation_keywords: list[str] | None = None


class RagConfigResponse(BaseModel):
    system_prompt: str
    temperature: float
    top_k_retrieval: int | None
    similarity_threshold: float | None
    escalation_keywords: list[str]


class HealthResponse(BaseModel):
    status: str
    dependencies: dict[str, str]


class SystemStatusResponse(BaseModel):
    embedding_model: str
    embedding_dimension: int
    qdrant_collection: str
    generation_model: str
    generation_fallback_model: str
    active_ingestion_jobs: int
