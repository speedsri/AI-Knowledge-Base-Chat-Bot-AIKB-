# API Contract - Phase B

Base URL (internal, LAN-only): http://192.168.1.220:8500

All /v1/* endpoints require: Authorization: Bearer <RAG_INTERNAL_TOKEN>
Missing/malformed header -> 401. Wrong token -> 403 (constant-time compare).

## GET /health

No auth required (used for basic container healthchecks).

Response 200:
```json
{
  "status": "ok",
  "dependencies": {"qdrant": "ok", "gemini": "configured"}
}
```
status is "degraded" if any dependency check fails. Never returns secrets.

## POST /v1/provider/test

Request: (empty body)

Response 200:
```json
{
  "provider": "gemini",
  "configured": true,
  "healthy": true,
  "generation_model": "gemini-3.7-flash",
  "embedding_model": "gemini-embedding-001",
  "embedding_dimension": 768,
  "latency_ms": 340,
  "error_code": null
}
```
Never returns the API key. If not configured, configured=false,
healthy=false, error_code="provider_not_configured".

## POST /v1/retrieval/test

Request:
```json
{"knowledge_base_id": 1, "query": "What services do you offer?", "top_k": 5, "similarity_threshold": 0.72}
```
top_k and similarity_threshold are optional; defaults from .env apply.

Response 200:
```json
{
  "matches": [
    {
      "document_id": 1,
      "document_version_id": 2,
      "title": "Services Overview",
      "source_url": "https://dynamictecsl.site/services",
      "score": 0.83,
      "chunk_index": 3,
      "text_preview": "We offer..."
    }
  ]
}
```

## POST /v1/chat

Request:
```json
{"knowledge_base_id": 1, "conversation_ref": "admin-test-1", "channel": "admin_test", "message": "What services do you offer?"}
```

Response 200:
```json
{
  "answer": "Based on the available information...",
  "grounded": true,
  "confidence": 0.82,
  "escalated": false,
  "model": "gemini-3.7-flash",
  "sources": [
    {"document_id": 1, "document_version_id": 2, "title": "Services Overview", "source_url": "https://...", "score": 0.83}
  ],
  "latency_ms": 1234
}
```
Response 503 if retrieval or generation fails outright -- never a fabricated
answer (see SECURITY_PHASE_B.md and ARCHITECTURE_PHASE_B.md grounding policy).

## POST /v1/reindex

Called by AIKB after a document_versions row is created/updated in MySQL.
rag-api never queries MySQL itself -- this payload IS the canonical data.

Request:
```json
{
  "knowledge_base_id": 1,
  "document_id": 1,
  "document_version_id": 2,
  "title": "Services Overview",
  "canonical_url": null,
  "normalized_content": "Full plain text content of this version...",
  "content_hash": "<64-char hex sha256>",
  "is_published": true
}
```

Response 200:
```json
{"ok": true, "chunks_indexed": 4, "content_hash_verified": true}
```
content_hash_verified is false if rag-api's own computed SHA-256 of
normalized_content does not match the claimed content_hash -- a data
integrity signal, not a security boundary (the caller is authenticated).

## POST /v1/ingestion/start

Manually triggered only -- nothing calls this automatically in Phase B.

Request:
```json
{
  "knowledge_base_id": 1,
  "origin_url": "https://example.com",
  "max_pages": 50,
  "crawl_delay_seconds": 1.5,
  "request_timeout_seconds": 20,
  "max_document_size_kb": 2048,
  "excluded_path_patterns": ["/login", "/admin"],
  "external_job_id": null
}
```
All fields except knowledge_base_id and origin_url are optional; defaults
from .env apply.

Response 200:
```json
{"job_id": "b3f1...", "status": "pending"}
```

## GET /v1/ingestion/status/{job_id}

Response 200:
```json
{
  "job_id": "b3f1...",
  "external_job_id": null,
  "status": "done",
  "pages_crawled": 12,
  "documents_changed": 10,
  "documents_unchanged": 2,
  "documents_failed": 0,
  "error": null,
  "created_at": "2026-09-15T10:00:00+00:00",
  "updated_at": "2026-09-15T10:02:30+00:00"
}
```
Response 404 if job_id is unknown -- note job state is in-memory and does
NOT survive a rag-api restart (see ARCHITECTURE_PHASE_B.md).

## GET /v1/system/status

Response 200:
```json
{
  "embedding_model": "gemini-embedding-001",
  "embedding_dimension": 768,
  "qdrant_collection": "dt_knowledge_base",
  "generation_model": "gemini-3.7-flash",
  "generation_fallback_model": "gemini-3.5-flash-lite",
  "active_ingestion_jobs": 0
}
```

## Error responses

All errors follow FastAPI's standard shape: `{"detail": "message"}`. Common
codes: 401 (missing/malformed auth), 403 (wrong token), 404 (unknown job),
422 (request validation failure -- see each schema's constraints),
429 (rate limited), 503 (Gemini/Qdrant temporarily unavailable -- retry later).

## V2 Additions and Corrections

### POST /v1/config/sync (new)

Called by AIKB whenever its RAG Settings screen is saved. Requires auth.

Request (all fields optional; only supplied fields are updated):
```json
{"system_prompt": "...", "temperature": 0.4, "top_k_retrieval": 5, "similarity_threshold": 0.72, "escalation_keywords": ["pricing", "quote"]}
```
Response 200: the full resulting effective config (same shape as the request, all fields populated).

### GET /v1/config/current (new)

Returns the currently active synced config (or built-in defaults if AIKB
has never synced). Requires auth. Never contains any secret — this
endpoint has nothing to do with the Gemini API key.

### POST /v1/chat — corrected behavior

- If retrieval finds **zero** matches above the similarity threshold, this
  endpoint now returns immediately with a deterministic no-answer response
  **without calling Gemini generation at all**:
  ```json
  {"answer": "I don't have enough information in the knowledge base to answer that. I'll flag this for a team member to follow up.",
   "grounded": false, "confidence": 0.0, "escalated": true, "model": "none", "sources": [], "latency_ms": 12}
  ```
- `confidence` in every response is now **retrieval-evidence-based** (the
  top retrieved chunk's similarity score when grounded, `0.0` otherwise) —
  not the model's self-reported number from earlier drafts of this API.

### POST /v1/reindex — corrected behavior

Re-indexing the same `document_version_id` no longer deletes existing
vectors before generating new ones. See `ARCHITECTURE_PHASE_B.md` /
`CHANGELOG.md` item 4. `normalized_content` is now bounded to 1,500,000
characters at the schema level (HTTP 422 if exceeded), in addition to the
global `MAX_REQUEST_BODY_BYTES` limit (HTTP 413).

### POST /v1/ingestion/start — corrected semantics

`documents_changed` in the job status now means **"pages successfully
fetched and normalized"** (prepared, awaiting a future handshake), **not**
"pages indexed" — nothing is indexed into Qdrant by this endpoint anymore.
`documents_unchanged` is currently always `0` in Phase B (no durable store
exists yet to compare against for change detection at the crawl level;
change detection happens at the `/v1/reindex` layer via `content_hash`
once a document version handshake exists in a later phase).

### All endpoints — request size limit

Requests exceeding `MAX_REQUEST_BODY_BYTES` (default 2MB) now receive a
genuine HTTP 413, enforced by ASGI middleware — not merely documented.

### Auth error codes — corrected

- Missing `Authorization` header → **401** (was a generic FastAPI 422 in V1).
- Malformed header → 401.
- Wrong token → 403.

## V3 — No contract changes

This patch touches only internal Gemini API usage (`app/gemini_client.py`)
and default model configuration. No request/response schema, endpoint
path, or status code behavior changed. `/v1/config/sync`'s `temperature`
field is unchanged — it is still accepted and stored; whether it's
actually sent to Gemini now depends on which `GENERATION_MODEL` is
configured (see `SECURITY_PHASE_B.md`/`CHANGELOG.md`).
