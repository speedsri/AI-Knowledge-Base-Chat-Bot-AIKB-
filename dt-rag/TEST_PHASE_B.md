# Testing — Phase B (V2)

## What was actually executed before this delivery was packaged

Real commands, real output, not claimed:

```
$ python3 -m venv testvenv2
$ testvenv2/bin/pip install -r backend/requirements.txt -r backend/requirements-dev.txt
  (resolved cleanly; note pydantic floats to 2.13.5, required by google-genai 2.23.0)
$ RAG_INTERNAL_TOKEN="test-token-32-characters-minimum-ok" GEMINI_API_KEY="" \
  QDRANT_URL="http://localhost:6333" testvenv2/bin/python3 -m pytest tests/ -v
...
103 passed, 3 skipped, 0 failed
```

The 3 skips are by design:
- `tests/test_integration_live_gemini.py` (2 tests) — skipped because no real `GEMINI_API_KEY` was provided to this test run.
- `tests/test_qdrant_integration.py` (1 test) — skipped because `QDRANT_INTEGRATION_TEST=1` was not set.

## What was NOT executed, and why (disclosed, not hidden)

| Item | Status | Reason |
|---|---|---|
| `docker compose -p dt-rag config` (the real binary) | Not run | No `docker` binary available in the sandbox used to prepare this delivery. The YAML was instead parsed with Python's `yaml` library and confirmed structurally valid (3 services, correct top-level keys) — a weaker check than the real command. **Run the real command yourself before deploying**, per `INSTALL_PHASE_B.md` Step 3. |
| Docker image builds (`rag-api`, `rag-ingestion`) | Not run | Same reason — no Docker daemon in this sandbox. |
| `tests/test_qdrant_integration.py` | Not run | Requires a real, running Qdrant server; none was available in this sandbox. The test itself is written, safe (disposable uniquely-named collection, never the production collection), and ready to run — see the command in its own docstring. |
| `tests/test_integration_live_gemini.py` | Not run | Requires a real `GEMINI_API_KEY`; none was provided/appropriate to use in this environment. |

**Two genuine bugs and one over-strict test were found by tests actually
failing during this process**, not by inspection — see `CHANGELOG.md` items
8 and 10 for the middleware config-caching bug and the escalation-logic
bug respectively, and item 11's note for the false-positive architectural
test. All three are fixed and the corresponding tests now pass.

## Full test file list and what each covers

| File | Covers |
|---|---|
| `test_health.py` | `/health`, degraded status, no secret leakage |
| `test_auth.py` | 401 vs 422 vs 403 for all four auth states; **real subprocess** startup-validation for empty/short/whitespace/valid tokens (item 6) |
| `test_provider.py` | `/v1/provider/test` with no key configured, key never leaked |
| `test_chunking.py` | Chunk determinism, hashing (requires network access to fetch tiktoken's BPE file on first run in an environment that has never cached it — see note below) |
| `test_normalize.py` | URL canonicalization, title/text extraction |
| `test_ssrf_guard.py` | Scheme, localhost, all private/reserved/multicast/carrier-NAT ranges, IPv6 equivalents, allow-list behavior, deny-by-default fallback (item 5) |
| `test_redirect_ssrf.py` | DNS-rebinding proof, per-hop revalidation, SNI/Host preservation, size/content-type limits (item 5) |
| `test_reindex_validation.py` | `ReindexRequest` schema validation |
| `test_reindex_nondestructive.py` | Embed-first-then-upsert-then-cleanup ordering, all failure paths (item 4) |
| `test_retrieval_filtering.py` | `knowledge_base_id`/`is_published` filtering via `query_points`, confirms `.search()` no longer exists on the pinned client (item 1) |
| `test_vector_store_dimension.py` | Collection creation, dimension-mismatch refusal, point-ID determinism |
| `test_grounding_policy.py` | Prompt-injection isolation, retrieval-primary confidence policy (item 10) |
| `test_no_context_chat.py` | Zero-match short-circuit, generation never called, response contract stability (item 9) |
| `test_request_size.py` | 413 enforcement, no logging of oversized content, schema-level bound (item 8) |
| `test_config_sync.py` | Push-based config sync, partial updates, auth required, architectural MySQL-import guard (item 11) |
| `test_qdrant_integration.py` | Real-server smoke test — **not run this delivery**, see above (item 12) |
| `test_integration_live_gemini.py` | Real API smoke test — **not run this delivery**, see above |

## A note on `test_chunking.py` and network access

`tiktoken`'s `cl100k_base` encoding is downloaded on first use from
`openaipublic.blob.core.windows.net`, not bundled in the package. In the
sandbox used to prepare this delivery, that specific endpoint was not
reachable (blocked by the sandbox's own egress policy), which initially
caused `test_chunking.py`'s collection to fail with an unrelated-looking
`403` error — this is a sandbox networking limitation, not a code defect,
but it exposed a real robustness gap worth fixing regardless: **the
original code called `tiktoken.get_encoding(...)` at module import time**,
meaning if this endpoint were ever unreachable from a real deployment
(firewalled egress, transient Azure blob storage outage, etc.), the entire
application would fail to even import. `app/chunking.py` was rewritten to
lazy-load the encoder and fall back to an approximate character-based
chunker with a logged warning if the real tokenizer can't be loaded, and
both Dockerfiles now pre-warm the tiktoken cache at build time (see
`Dockerfile`'s new `RUN` step) so this fallback should never actually be
needed in normal operation. After this fix, `test_chunking.py` and every
test that transitively imports `ingestion.pipeline` passed in the same
network-restricted sandbox — proving the fallback works, not just that the
network issue was worked around.

## Running the suite yourself

```bash
cd dt-rag
python3 -m venv .venv && source .venv/bin/activate
pip install -r backend/requirements.txt -r backend/requirements-dev.txt
RAG_INTERNAL_TOKEN="$(openssl rand -hex 32)" pytest -v
```

For the real Qdrant integration test, against the actual isolated stack:
```bash
docker compose -p dt-rag -f docker-compose.yml --env-file .env up -d rag-qdrant
QDRANT_INTEGRATION_TEST=1 QDRANT_URL=http://localhost:6333 pytest tests/test_qdrant_integration.py -v
```

For the live Gemini smoke test:
```bash
GEMINI_API_KEY=your-real-key pytest tests/test_integration_live_gemini.py -v
```

## V3 Patch — Test Results (actually executed)

```
RAG_INTERNAL_TOKEN="test-token-32-characters-minimum-ok" GEMINI_API_KEY="" \
  QDRANT_URL="http://localhost:6333" pytest tests/ -v
...
120 passed, 4 skipped, 0 failed
```

New test files: `tests/test_embedding_batching.py` (11 tests),
`tests/test_generation_config.py` (5 tests). `tests/test_integration_live_gemini.py`
extended with 2 new real-API tests (distinct-embeddings, 3.8-generation) —
**still not executed with a real API key** in this patch's preparation
either; disclosed, not claimed as passing.

One real bug in the test-writing process itself was caught and fixed
during this run: several new tests initially failed with
`GeminiError: GEMINI_API_KEY is not set` because the global test
environment leaves `GEMINI_API_KEY=""` by design (see `conftest.py`) and
the new tests needed to additionally monkeypatch a fake key to reach the
mocked SDK boundary — fixed by adding
`monkeypatch.setattr("app.gemini_client.settings.gemini_api_key", "fake-key")`
to each affected test. This is a test-harness fix, not a production code
fix.

Docker image builds and the real-Qdrant integration test remain
**not executed** in this patch for the same reason as V2: no Docker daemon
available in the sandbox used to prepare this delivery.
