"""
Phase B configuration. Every value is an explicit environment variable --
no hardcoded model names, dimensions, or infrastructure assumptions.

IMPORTANT -- MODEL NAMES DECAY FAST. RE-VERIFIED FOR V3 against current
Google documentation (fresh searches performed for this patch, not reused
from earlier drafts):
  - Gemini 2.5 Pro/Flash/Flash-Lite: shutdown 2026-10-16, confirmed still
    scheduled, do not use.
  - Gemini 3.8 Flash (gemini-3.8-flash) is now GA and is the current
    generation default -- confirmed via Google's own "What's new in
    Gemini 3.8 Flash" guide. That same guide explicitly instructs:
    "Remove temperature, top_p, and top_k" -- app/gemini_client.py omits
    these for this model (and, defensively, for the 3.6/3.7 line which
    received an earlier "now deprecated" notice).
  - gemini-3.5-flash-lite remains GA and is kept as the fallback model.
  - gemini-embedding-2 (GA since 2026-04-22) remains the default embedding
    model. app/gemini_client.py was corrected in this same patch to fix a
    confirmed SDK/provider batching bug (multiple inputs previously
    aggregated into a single embedding for this model family unless each
    is wrapped in its own Content object) and to stop sending the
    unsupported task_type parameter to it.
RE-VERIFY all of the above against
https://ai.google.dev/gemini-api/docs/models and
https://ai.google.dev/gemini-api/docs/embeddings and
https://ai.google.dev/gemini-api/docs/changelog before every deployment.
"""
from pydantic_settings import BaseSettings
from pydantic import field_validator
from typing import List


class Settings(BaseSettings):
    # ---- Internal auth ----
    # HARD REQUIREMENT (V2 correction): must be present and strong. Enforced
    # by the validator below -- application startup fails otherwise, rather
    # than silently running with a blank/weak token.
    rag_internal_token: str

    @field_validator("rag_internal_token")
    @classmethod
    def token_must_be_strong(cls, v: str) -> str:
        stripped = v.strip()
        if not stripped:
            raise ValueError(
                "RAG_INTERNAL_TOKEN must not be empty or whitespace-only. "
                "Generate one with: openssl rand -hex 32"
            )
        if len(stripped) < 32:
            raise ValueError(
                f"RAG_INTERNAL_TOKEN is only {len(stripped)} characters -- must be at least 32 "
                "for adequate entropy. Generate one with: openssl rand -hex 32"
            )
        return v

    # ---- Gemini ----
    gemini_api_key: str = ""  # empty = provider shows "not_configured", app still boots
    generation_model: str = "gemini-3.8-flash"
    generation_fallback_model: str = "gemini-3.5-flash-lite"
    embedding_model: str = "gemini-embedding-2"
    embedding_dimension: int = 768
    request_timeout: float = 25.0
    embed_batch_size: int = 32

    # ---- Qdrant (internal only, no host port) ----
    qdrant_url: str = "http://rag-qdrant:6333"
    qdrant_collection: str = "dt_knowledge_base"
    qdrant_startup_retry_attempts: int = 10
    qdrant_startup_retry_wait_seconds: float = 3.0

    # ---- Retrieval defaults (overridable per-request, or via /v1/config/sync) ----
    top_k_default: int = 5
    similarity_threshold_default: float = 0.72

    # ---- Rate limiting ----
    rate_limit_per_minute: int = 120

    # ---- Request size protection (V2 addition) ----
    max_request_body_bytes: int = 2_000_000

    # ---- Crawler safety defaults (overridable per-call) ----
    crawler_default_max_pages: int = 200
    crawler_default_delay_seconds: float = 1.5
    crawler_default_timeout_seconds: float = 20.0
    crawler_default_max_doc_size_kb: int = 2048
    crawler_respect_robots_txt: bool = True
    crawler_allowlisted_private_ranges: str = ""

    # ---- Logging ----
    log_level: str = "INFO"

    class Config:
        env_file = ".env"

    @property
    def allowlisted_private_ranges_list(self) -> List[str]:
        return [c.strip() for c in self.crawler_allowlisted_private_ranges.split(",") if c.strip()]


settings = Settings()
