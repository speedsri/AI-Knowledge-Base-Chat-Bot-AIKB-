# dt-rag -- Phase B

The first real slice of the Dynamic Technologies RAG execution plane:
rag-api (FastAPI), rag-qdrant, and an ingestion foundation
(crawler + chunking + embedding + Qdrant upsert code -- present, tested, but
not wired to run automatically against any production site yet).

This is a fully isolated Docker Compose project (dt-rag) deployed
alongside -- never touching -- the existing, protected production containers
on 192.168.1.220: aikb_app, aikb_mysql, docker-mattermost-1,
docker-n8n-1, docker-db-1, docker-minio-1.

Start here, in order:
1. ARCHITECTURE_PHASE_B.md -- what this is, why it's built this way, and every deliberate scope boundary.
2. API_CONTRACT.md -- the 8 endpoints, request/response shapes.
3. SECURITY_PHASE_B.md -- auth, SSRF defenses, prompt-injection isolation, what's logged and what never is.
4. INSTALL_PHASE_B.md -- the actual, safe deployment sequence.
5. TEST_PHASE_B.md -- how to run the test suite and what it covers.
6. ROLLBACK_PHASE_B.md -- how to undo this, safely, without touching anything else.
7. CHANGELOG.md -- what's new relative to the historical V2/V3 designs this was built from.

## Scope, stated plainly

In Phase B: rag-api, rag-qdrant, ingestion foundation code (crawler, SSRF
guard, chunking, embedding, Qdrant upsert), all 8 contract endpoints, full
test suite.

Not in Phase B (deferred to later phases, per explicit instruction):
dedicated RAG PostgreSQL, rag-worker, WhatsApp integration, Mattermost
integration, the website widget, voice. None of these are stubbed or
half-built -- they simply don't exist yet in this package.

## Directory tree

```
dt-rag/
  docker-compose.yml
  .env.example
  pytest.ini
  README.md  ARCHITECTURE_PHASE_B.md  INSTALL_PHASE_B.md  ROLLBACK_PHASE_B.md
  API_CONTRACT.md  SECURITY_PHASE_B.md  TEST_PHASE_B.md  CHANGELOG.md

  backend/
    Dockerfile              (rag-api)
    Dockerfile.ingestion    (rag-ingestion: manual CLI, no daemon)
    requirements.txt
    requirements-dev.txt
    app/
      config.py auth.py rate_limit.py logging_setup.py
      gemini_client.py vector_store.py chunking.py grounding.py
      jobs.py schemas.py main.py
    ingestion/
      ssrf_guard.py crawler.py normalize.py pipeline.py cli.py

  tests/
    conftest.py
    test_health.py test_auth.py test_provider.py
    test_chunking.py test_normalize.py
    test_ssrf_guard.py test_redirect_ssrf.py
    test_reindex_validation.py test_retrieval_filtering.py
    test_vector_store_dimension.py test_grounding_policy.py
    test_integration_live_gemini.py   (skipped unless GEMINI_API_KEY is set)
```

## This is V2 — read CHANGELOG.md first

This package (`dt-rag-phaseb-delivery-v2.zip`) supersedes the original
Phase B delivery. It contains genuine corrections to dependency pins,
Gemini model choices, SSRF defenses, reindex safety, request-size limits,
confidence policy, and a Qdrant healthcheck fix — each mapped to a
FIXED/NOT APPLICABLE/UNRESOLVED status against the 15-item review that
produced this patch. **Read `CHANGELOG.md` before deploying** — it is the
authoritative record of what changed and why, including two real bugs that
were found and fixed by tests actually failing during preparation of this
delivery, not just by inspection.

`TEST_PHASE_B.md` reports exact, real pytest results (103 passed, 3
skipped, 0 failed) and is explicit about what could **not** be executed in
the sandbox used to prepare this delivery (Docker image builds, the real
Qdrant integration smoke test) — these are disclosed, not silently
skipped or falsely claimed as passing.

## V3 — Gemini API correctness patch

This is V3, a focused patch on top of V2 fixing two current-Gemini-API
issues that would have been a deployment blocker (`gemini-embedding-2`
silently aggregating multi-chunk embedding requests into one vector) and
two accuracy corrections (retrieval task instructions, normalization
behavior) plus one model update (`gemini-3.8-flash`, now GA, with its
required removal of deprecated sampling parameters). **Read
`CHANGELOG.md` first** — it explains exactly how the embedding bug was
fixed and reports real, executed test results (120 passed, 4 skipped, 0
failed). No architectural changes; Phase B scope is otherwise identical to
V2.
