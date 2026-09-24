# Architecture - Phase B

## Position in the overall system

```
Internet -> Cloudflare -> .215 (dt-admin4, WhatsApp) --\
                                                          -> authenticated
                                                             internal HTTP -> rag-api (.220:8500)
AIKB PHP/MySQL (.220, control plane) -------------------/
```

.215 and AIKB (.220) are both, eventually, authorized callers of rag-api --
both hold RAG_INTERNAL_TOKEN server-side, neither exposes it to a browser.
Phase B does not modify either caller; it only stands up the callee.

## What's deliberately NOT here

- No dedicated Postgres. Approved decision: defer until the WhatsApp
  human-first workflow (Phase E) actually needs durable state machine rows.
  Introducing it now would be infrastructure with no consumer.
- No rag-worker. Same reasoning -- nothing in Phase B needs a background
  poller. /v1/ingestion/start runs its crawl as an asyncio.create_task
  inside rag-api's own process; see "Ingestion job model" below for why
  that's sufficient here and insufficient later.
- No WhatsApp, Mattermost, widget, or voice code. Not stubbed, not
  half-built -- genuinely absent.
- rag-api never touches MySQL. AIKB remains the sole owner of
  knowledge_bases, categories, documents, document_versions, etc. rag-api
  receives exactly what it needs to index a document version via
  /v1/reindex's request body, and returns exactly what AIKB needs
  (chunks_indexed, content_hash_verified) -- no callback, no shared
  credentials, no cross-database join.

## Gemini model selection - verified, not assumed

At the time this was written, Gemini 2.5 Pro/Flash/Flash-Lite are
scheduled for shutdown on 2026-10-16. Using V2/V3's old model names would
have produced a system that stops working within weeks. Current choices,
all via .env, none hardcoded in code:

| Purpose | Model | Status |
|---|---|---|
| Generation (primary) | gemini-3.7-flash | GA since 2026-08-13 |
| Generation (fallback) | gemini-3.5-flash-lite | GA, cheapest high-volume tier |
| Embeddings | gemini-embedding-001 | GA, text-only, stable |

gemini-embedding-2-preview (multimodal) exists but is preview-status; for a
text-only production RAG pipeline, the GA model was chosen deliberately.
Re-verify all three against https://ai.google.dev/gemini-api/docs/models
and https://ai.google.dev/gemini-api/docs/embeddings before every
deployment -- this catalog has changed multiple times within months in
2026 alone.

## Qdrant design

Collection dt_knowledge_base (configurable). One point per chunk. Point ID
is uuid5(document_version_id, chunk_index) -- deterministic, so re-indexing
the same version+chunk upserts rather than accumulates duplicates. Payload:
knowledge_base_id, document_id, document_version_id, chunk_index,
content_hash, title, canonical_url, chunk_text, is_published.

Qdrant is fully rebuildable from MySQL's
document_versions.normalized_content. /v1/reindex always deletes-then-
recreates a version's chunks -- there is no partial-update code path, so
"delete the whole collection and replay every document_version through
/v1/reindex" is always a valid, complete recovery procedure (see
ROLLBACK_PHASE_B.md).

Startup safety check: ensure_collection() compares the existing
collection's vector size against EMBEDDING_DIMENSION and raises
VectorStoreError -- refusing to start -- on any mismatch. It never
auto-deletes or auto-recreates an existing collection with different
dimensions; that would silently corrupt retrieval for whatever content was
already indexed.

## Ingestion job model - the explained-before-building decision

Decision: in-memory, in-process job tracking (app/jobs.py), not a new
database. A dict[job_id -> status] inside rag-api's own process.

Why this is sufficient for Phase B: /v1/ingestion/start is a manually
triggered, single-run operation for foundation testing -- nothing schedules
it, nothing runs it automatically, there's no multi-worker contention to
protect against. State surviving a rag-api restart isn't required because
nothing depends on that state persisting across a restart in this phase.

Why this is explicitly NOT sufficient for later phases: once Phase C
introduces real, possibly-long-running production crawls, and especially
once Phase E introduces a durable WhatsApp state machine with its own
timing guarantees, in-memory state becomes actively wrong -- a restart
mid-crawl would silently lose all progress with no way to resume or even
notice. That's the trigger for introducing dedicated Postgres, not before.

The seam left for that future connection: IngestionStartRequest accepts an
optional external_job_id. Once AIKB's existing background_jobs table is
wired (a Phase C/E decision) to create a row and pass its ID through,
rag-api echoes it back in every status response -- letting AIKB
cross-reference without rag-api ever writing to MySQL itself.

## SSRF defense architecture

ingestion/ssrf_guard.py is a standalone, unit-tested module (see
SECURITY_PHASE_B.md for the full threat model). Every URL the crawler
touches -- including every redirect hop -- passes through validate_url()
before a connection is made. This is enforced at the
_fetch_with_redirect_revalidation() level inside crawler.py, not just at
the initial origin_url -- the initial-URL-only check is the single most
common SSRF implementation mistake, and Phase B's test suite
(test_redirect_ssrf.py) specifically exercises the redirect-to-private-IP
case to prove this isn't a gap here.

## robots.txt policy - documented, not silently assumed

Default: respected (CRAWLER_RESPECT_ROBOTS_TXT=true). Dynamic Technologies'
own site is the eventual target, and there's no reason to deviate from the
standard polite-crawler convention. Overridable per deployment for a
future, explicitly-reviewed internal-only use case -- not overridden by
default.

## Network isolation

Separate Compose project dt-rag, separate network dt_rag_net, separate
.env, deployed from its own directory. Never shares a project identity,
network, or environment file with the existing docker project that runs
the protected containers. See INSTALL_PHASE_B.md / docker-compose.yml for
the exact mechanics.

## Known limitations of this phase, stated plainly

- Ingestion job status is lost on rag-api restart (see above -- deliberate).
- rag-api's port binds LAN-wide on .220 (192.168.1.220:8500) rather than
  being firewalled to specific caller IPs yet -- the firewall manager on
  .220 was never confirmed in earlier phases, and writing a blind ufw rule
  against an unconfirmed system was rejected as unsafe. This is a concrete
  follow-up for the next phase's hardening pass, not silently deferred
  forever.
- /v1/ingestion/start's crawl path assigns synthetic negative IDs to
  crawled pages (see ingestion/pipeline.py::index_crawled_pages) since
  Phase B has no live handshake back to AIKB to create real
  document/document_version rows for crawled content. Phase C replaces
  this with a real handshake.

## V2 Patch Addendum

See `CHANGELOG.md` for the full itemized correction report against the 15
review items that produced this patch. Highlights that change prior
statements in this document:

- **Dependencies re-pinned and actually verified by installation**:
  `google-genai==2.23.0`, `qdrant-client==1.19.0`. The `.search()` Qdrant
  client method referenced earlier in this document **no longer exists** in
  the pinned version — `vector_store.py` uses `query_points()` throughout.
- **Embedding model default changed to `gemini-embedding-2`** (GA since
  2026-04-22), superseding this document's earlier `gemini-embedding-001`
  default, per policy: prefer the newer GA model absent a documented reason
  not to. `gemini-embedding-001` remains supported and configurable.
- **Qdrant is no longer written to by the crawler at all.** The "Qdrant
  design" section's original description of the crawl path assigning
  synthetic negative IDs has been removed from the codebase entirely — see
  `CHANGELOG.md` item 3. Only `POST /v1/reindex`, fed by canonical AIKB
  document/document_version data, writes to Qdrant.
- **Reindexing is now genuinely non-destructive** — see `CHANGELOG.md`
  item 4 for the corrected embed-then-upsert-then-cleanup ordering,
  replacing this document's earlier (and, on reflection, unsafe)
  delete-then-recreate description.
- **The Qdrant healthcheck section is corrected** — `docker-compose.yml`
  no longer assumes `wget` exists in the official Qdrant image; readiness
  is now rag-api's own responsibility via a startup retry loop.
- **New**: `app/runtime_config.py` and `POST/GET /v1/config/*` for
  server-to-server RAG settings sync from AIKB, closing a gap where
  escalation keywords had no path to ever become non-empty.

## V3 Patch Addendum

Two Gemini API corrections, re-verified against Google's current official
documentation (see `CHANGELOG.md` for full detail):

1. **gemini-embedding-2 multi-input batching bug fixed** — this model
   family aggregates multiple `contents` strings into one embedding unless
   each is wrapped in its own `types.Content` object. `app/gemini_client.py`
   now does this automatically for any embedding-2-family model, and
   verifies the returned embedding count matches the request before
   accepting the result.
2. **Generation model moved to `gemini-3.8-flash`** (now GA), which per its
   own migration guide requires removing `temperature`/`top_p`/`top_k`
   entirely — `app/gemini_client.py` omits these for this model (and
   defensively for 3.6/3.7) while keeping AIKB's `temperature` value in
   the config contract for models that do accept it.

`gemini-embedding-2` also does not support `task_type` at all (corrected
from an earlier assumption); retrieval-query and retrieval-document text
now receive distinct, Google-documented instruction prefixes instead.
Normalization behavior was also corrected: embedding-2 auto-normalizes
truncated output, embedding-001 does not (V2 had this backwards for
embedding-2 specifically).
