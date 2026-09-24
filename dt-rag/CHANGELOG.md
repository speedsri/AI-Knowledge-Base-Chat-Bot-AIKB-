# Changelog — V3 Patch (Gemini API correctness fixes)

Focused patch on top of V2. Does not redesign anything; does not touch
Phase C/D/WhatsApp/Mattermost/widget/voice. All V2 corrections preserved
(see `CHANGELOG_V2.md` — this V2 changelog content has been renamed, see
below).

## How the gemini-embedding-2 batching bug was fixed (asked for explicitly)

**The bug**: `embed_texts()` sent a plain Python list of strings as
`contents` to `embed_content()`. For `gemini-embedding-001` this correctly
returns one embedding per string. For `gemini-embedding-2`, Google's own
documentation and a confirmed SDK issue (`googleapis/python-genai#2523`)
both state this instead returns **one aggregated embedding for the whole
batch**, silently, regardless of how many strings were passed — a
deployment blocker, since every chunk beyond the first in any batch would
have silently received no real embedding of its own (or, worse, a `zip()`
downstream could pair the single aggregated vector with the wrong chunk
without erroring).

**The fix** (`app/gemini_client.py`):
1. Detect the model family via `_model_requires_content_wrapping()` (matches
   any `gemini-embedding-2*` model name).
2. For that family, wrap **each individual string** in its own
   `types.Content(parts=[types.Part(text=text)])` object before sending —
   this is Google's own confirmed workaround, verified against both their
   official documentation and the SDK issue's own fix description.
3. Apply Google's documented text-prefix retrieval-instruction convention
   to each string **before** wrapping (since `task_type` isn't supported
   for this model family at all — see below).
4. **Unconditionally verify** `len(resp.embeddings) == len(batch)` after
   every call, for both model families. A mismatch raises
   `GeminiError("embedding_count_mismatch")` — the code never
   zips/truncates/guesses which vector belongs to which input.
5. Six new tests (`tests/test_embedding_batching.py`) prove: 1 input → 1
   embedding, 2 inputs → 2 real `types.Content` objects sent (not a plain
   list), 10 inputs → exactly 10 vectors returned, a simulated mismatch
   (3 requested, 1 returned — the actual historical bug) raises the safe
   error rather than succeeding silently, and that `gemini-embedding-001`
   is untouched by any of this (still receives a plain list, still uses
   native `task_type`).

## Full item-by-item report

| # | Item | Status |
|---|---|---|
| 1 | Fix gemini-embedding-2 multi-chunk behavior | **FIXED** — see above |
| 2 | Implement embedding-2 retrieval instructions | **FIXED** — `_prepare_retrieval_text()` applies Google's documented asymmetric format: query → `f"task: search result | query: {text}"` (confirmed verbatim from Google's own example code), document → `f"title: {title or 'none'} | text: {text}"` (the exact literal template was truncated in the fetched documentation page during this patch's research — **re-verify the document-side prefix** against `https://ai.google.dev/gemini-api/docs/embeddings`, "Task types with Embeddings 2" section, before relying on it in production; the query-side prefix was fully confirmed against Google's own code sample). `gemini-embedding-001` is unaffected — it continues using the native `task_type` parameter on unmodified text. Query and document text are now provably formatted differently (`test_embedding_2_query_and_document_formatted_differently`). |
| 3 | Normalization corrected | **FIXED** — re-verified against current docs: gemini-embedding-2 auto-normalizes truncated (<3072-dim) output ("the output embeddings are already L2 normalized for non-default dimensions" — Google Cloud docs); gemini-embedding-001 does not. `app/gemini_client.py` now only manually L2-normalizes for the -001 family (`_model_requires_manual_normalization()`), and a new test (`test_embedding_2_does_not_manually_renormalize`) proves embedding-2 output is passed through untouched rather than redundantly renormalized. |
| 4 | Move generation default to gemini-3.8-flash | **FIXED** — confirmed GA via Google's own "What's new in Gemini 3.8 Flash" guide ("generally available (GA) and ready for production use"). `GENERATION_MODEL` default changed; `GENERATION_FALLBACK_MODEL` kept at `gemini-3.5-flash-lite` (independently re-confirmed still GA). Both remain fully `.env`-configurable, no hardcoding in business logic. |
| 5 | Gemini 3.8 generation config — no deprecated sampling params | **FIXED** — confirmed via Gemini 3.8 Flash's own migration guide: "Remove temperature, top_p, and top_k." `generate_answer()` now omits these entirely for `gemini-3.8-flash` (and defensively for `gemini-3.6-flash`/`gemini-3.7-flash`, which received an earlier "now deprecated" notice in the same changelog series — verify independently if either is actually configured). AIKB's `temperature` value remains a normal parameter in the function signature and the `/v1/config/sync` contract/schema — it is simply unused when the configured model doesn't accept it. No Phase A database column or sync contract field was removed. |
| 6 | Add tests that would have caught this | **FIXED** — `tests/test_embedding_batching.py` (11 tests: 1-input, 2-input Content-wrapping, 10-input count, mismatch-raises-error, task_type absent for -2, retrieval query/document instruction formatting, -001 native task_type retained, -001 not Content-wrapped, -001 manual normalization, -2 normalization untouched) and `tests/test_generation_config.py` (5 tests: 3.8 omits temperature/top_p/top_k, older model still receives temperature, 3.7 also omits defensively, max_output_tokens still sent, helper function unit test). `tests/test_integration_live_gemini.py` extended with a real-API test for two-distinct-embeddings and generation with the configured 3.8 model — **not executed with a real key during this patch's preparation**, disclosed in `TEST_PHASE_B.md`, not claimed as passing. |
| 7 | Preserve everything else from V2 | **FIXED** — no V2 correction was reverted. `google-genai==2.23.0` and `qdrant-client==1.19.0` unchanged (re-verification for this patch found no newer compatible stable release worth switching to given the narrow scope of this patch). Isolated `dt-rag` project, internal-only Qdrant, non-destructive reindex, MySQL-authoritative/preparation-only crawler, DNS-pinned SSRF defense, token validation, request-size middleware, no-context short circuit, retrieval-primary confidence, `/v1/config/*` endpoints, and the absence of Postgres/Mattermost/WhatsApp/widget/voice are all untouched by this patch — only `app/gemini_client.py`, `app/config.py`, `.env.example`, and the test suite were touched. |

## Test results (actually executed)

```
RAG_INTERNAL_TOKEN="..." GEMINI_API_KEY="" QDRANT_URL="http://localhost:6333" \
  pytest tests/ -v
...
120 passed, 4 skipped, 0 failed
```
The 4 skips are the by-design live-Gemini tests (3, extended in this
patch) and the real-Qdrant integration test (1) — neither runs without
external resources this sandbox doesn't have (see `TEST_PHASE_B.md`).

Python compile checks: every `.py` file parsed cleanly with `ast.parse()`.

## Files changed in this patch

- `backend/app/gemini_client.py` — rewritten (embedding batching fix, task-type/normalization corrections, sampling-param omission)
- `backend/app/config.py` — `GENERATION_MODEL` default updated, docstring corrected
- `.env.example` — updated to match
- `tests/test_embedding_batching.py` — new
- `tests/test_generation_config.py` — new
- `tests/test_integration_live_gemini.py` — extended

No other file was modified. `CHANGELOG.md` from the V2 delivery is preserved below for continuity.

---

# Changelog — V2 Patch

This supersedes `dt-rag-phaseb-delivery.zip` (V1). Corrections mapped to the
15 review items, each marked FIXED / NOT APPLICABLE / UNRESOLVED.

| # | Item | Status | Detail |
|---|---|---|---|
| 1 | Re-verify all current dependencies | **FIXED** | `google-genai` 0.7.0 → **2.23.0** (installed and inspected: `client.aio.models.*` surface unchanged). `qdrant-client` 1.11.0 → **1.19.0** (installed and confirmed `.search()` **no longer exists at all** — `hasattr` returns False; V1 would have crashed on first real query). `vector_store.py` rewritten to use `query_points()` (returns `.points`, not a bare list). `qdrant/qdrant` image bumped to `v1.19.0` (tag existence inferred from related Docker Hub evidence, not directly confirmed — flagged in `docker-compose.yml` comments to verify before pulling). Discovered during real `pip install` that `pydantic` had to move off the `2.9.2` pin (google-genai 2.23.0 requires `pydantic>=2.11`) — resolved to `pydantic>=2.11,<3.0`, actually installed together, resolves to `2.13.5`. Full test suite re-run against these exact pins (see item 14). |
| 2 | Re-verify current Gemini models | **FIXED** | Fresh search performed (not reused from earlier conversation research, per instruction). Confirmed Gemini 2.5 Pro/Flash/Flash-Lite shutdown 2026-10-16 stands. Generation: `gemini-3.7-flash` (GA) primary / `gemini-3.5-flash-lite` (GA) fallback — re-confirmed independently. Embeddings: switched default to **`gemini-embedding-2`** (GA since 2026-04-22) per your instruction to prefer the newer GA model absent a documented reason not to; `gemini-embedding-001` retained as a configurable alternative. `task_type` is now sent conditionally — `gemini-embedding-2` does not use it (confirmed via LangChain's integration docs), `gemini-embedding-001` does. **L2-normalization implemented** (`app/gemini_client.py::_normalize_l2`) for MRL-truncated embeddings (`EMBEDDING_DIMENSION < 3072`), applied to both embedding model families, per Google's documented requirement. `EMBEDDING_DIMENSION=768` retained as instructed. Noted (not acted on beyond documentation) that Google deprecated the `temperature`/`top_p`/`top_k` sampling parameters as of the 2026-07-21 changelog entry for the 3.6/3.7 generation — still passed since "deprecated" ≠ "rejected" as of this writing; flagged for re-verification before every deployment. |
| 3 | MySQL must remain authoritative | **FIXED** | `ingestion/pipeline.py::index_crawled_pages()` **deleted entirely**. The crawler (`crawler.py`) and CLI (`cli.py`) now only fetch/normalize/return prepared pages — nothing is written to Qdrant from a crawl. `POST /v1/reindex` remains the sole path into Qdrant, unchanged in this respect. `_run_crawl_job` in `main.py` updated to reflect "pages prepared," not "documents indexed," in job status fields (see `API_CONTRACT.md`). |
| 4 | Reindex must not delete before success | **FIXED** | `ingestion/pipeline.py::reindex_document_version()` rewritten to the required order: chunk → embed ALL chunks first → build points → upsert → **only then** discover and delete stale higher-index leftover points via new `vector_store.get_chunk_indices_for_version()` (Qdrant `scroll`) and `delete_points_by_ids()`. Five new automated failure-path tests added (`tests/test_reindex_nondestructive.py`), actually run: embedding failure touches Qdrant not at all (asserted via mock call-count), upsert failure triggers no delete, successful reindex proven to call upsert before cleanup via explicit call-order assertion, no-stale-chunks case makes zero delete calls, and a determinism/idempotency check on point IDs. All passing. |
| 5 | Harden SSRF against DNS rebinding / TOCTOU | **FIXED** | `ingestion/ssrf_guard.py::resolve_and_validate()` now resolves a hostname **exactly once**, validates the result, and returns the literal IP. `crawler.py::_fetch_pinned()` connects directly to that IP (never letting httpx re-resolve independently), preserving the original `Host` header and TLS SNI via httpx's `sni_hostname` request extension — confirmed genuinely honored by installing `httpx==0.28.1`/`httpcore` and reading the installed source (not assumed from documentation alone). Every redirect hop calls `resolve_and_validate()` again from scratch — proven by a test asserting exactly 3 resolve calls across a 2-hop redirect chain. Deny-by-default global-address policy expanded: loopback, RFC1918, link-local (incl. `169.254.169.254`), unspecified, multicast (explicit `is_multicast` check added after a real test failure surfaced that `is_global` alone does not flag multicast), carrier-grade NAT, IANA TEST-NET ranges, and IPv6 equivalents — all denied by default, all except the explicit private-range allow-list are non-allow-listable. 8 new/rewritten tests in `test_ssrf_guard.py` plus 7 in `test_redirect_ssrf.py`, including the specific DNS-rebinding proof (`test_dns_rebinding_second_resolution_never_happens`) and per-hop revalidation proof. All passing. |
| 6 | RAG_INTERNAL_TOKEN must never be blank | **FIXED** | `app/config.py` adds a Pydantic field validator rejecting empty, whitespace-only, or <32-character tokens — **application startup itself fails** (proven via real subprocess execution in `tests/test_auth.py`, not mocked: a fresh Python process importing `app.config` genuinely exits non-zero with the expected message for empty/short/whitespace tokens, and genuinely succeeds for a valid one). `app/auth.py` rewritten so a missing `Authorization` header returns 401 (was FastAPI's generic 422), malformed header returns 401, wrong token returns 403 — all four states covered by passing tests. Constant-time comparison (`hmac.compare_digest`) retained. Token never logged or returned (unchanged, verified by `test_provider_test_never_leaks_key_even_if_configured`-style checks). |
| 7 | Fix Qdrant Docker healthcheck | **FIXED** | Removed the `wget`-based `HEALTHCHECK` from `rag-qdrant` in `docker-compose.yml` — never verified to exist in the official minimal image, and not verifiable in this sandbox (no Docker daemon available, see item 14). Redesigned readiness instead: `rag-api`'s own startup now retries connecting to Qdrant with a bounded backoff (`tenacity`, configurable via `QDRANT_STARTUP_RETRY_ATTEMPTS`/`_WAIT_SECONDS`) and exits non-zero if still unreachable after all attempts — a real failure is still visible via `docker compose ps`/`logs`, without depending on an unconfirmed binary in a third-party image. `rag-api`'s own healthcheck still uses `curl`, which **is** confirmed present (explicitly `apt-get install`ed in `backend/Dockerfile`, not assumed from a base image). `depends_on` changed from `condition: service_healthy` to `condition: service_started` for both `rag-api` and `rag-ingestion`. |
| 8 | Real request-size protection | **FIXED** | `app/request_size.py` (new) — genuine ASGI middleware: fast-path rejection via `Content-Length` header before any body is read, slow-path enforcement while streaming for chunked/unknown-length bodies, returns real HTTP 413, never logs the oversized body content. `MAX_REQUEST_BODY_BYTES` configurable via `.env`. `ReindexRequest.normalized_content` additionally bounded at the schema level (`max_length=1_500_000`) as defense-in-depth. **A real bug was found and fixed while testing this**: the middleware originally cached the configured limit at construction time, so runtime config changes (and the test's `monkeypatch`) had no effect — fixed to read `settings.max_request_body_bytes` fresh on every request. 4 tests added, all passing after the fix. |
| 9 | No-context chat behavior | **FIXED** | `/v1/chat` in `main.py` now checks `hits` immediately after retrieval; if empty, returns a deterministic `grounded=false`/`confidence=0.0`/`escalated=true`/`sources=[]` response **without calling Gemini generation at all**. Proven by a test that makes `generate_answer` raise `AssertionError` if called — the test passes, meaning it was never invoked. Response contract (`ChatResponse` schema) unchanged for this path — same fields, same types, just no fabricated content. |
| 10 | Confidence / grounding must be retrieval-primary | **FIXED, with a real bug found and corrected** | `app/grounding.py` now computes `confidence` and `grounded` from retrieval evidence (`top_score` vs. threshold) via `retrieval_based_confidence()`/`is_grounded()` — the model's self-reported `CONFIDENCE:` line is parsed but discarded (`_self_reported` in `main.py`, explicitly unused). **Testing surfaced a genuine logic bug in the first draft of `detect_escalation()`**: it ANDed a "weak confidence" condition with "no good match," which meant an artificially high confidence value passed in could suppress escalation even when actual retrieval evidence (`top_score`) was weak — exactly the failure mode item 10 warned against. Fixed: escalation is now decided from `top_score` alone (plus keyword matches), with the confidence parameter explicitly unused for this decision. New test `test_weak_retrieval_evidence_cannot_be_overridden_by_high_confidence_value` specifically encodes this requirement and now passes. Confidence policy documented in `app/grounding.py`'s module docstring and in `SECURITY_PHASE_B.md`. |
| 11 | Control-plane RAG settings sync mechanism | **FIXED** | New `app/runtime_config.py` — in-process, thread-safe, push-based config store. New endpoints `POST /v1/config/sync` (AIKB pushes effective `system_prompt`/`temperature`/`top_k_retrieval`/`similarity_threshold`/`escalation_keywords` whenever its own RAG Settings are saved) and `GET /v1/config/current` (diagnostic read). rag-api never queries or writes MySQL — the boundary is enforced structurally, not just by convention (a dedicated architectural test, `test_config_sync_never_touches_mysql`, parses the module's AST and asserts no MySQL driver is ever imported). `escalation_keywords` defaults to empty only until AIKB syncs real values — this is the intended, now-documented behavior, not a permanent silent gap. Ephemeral (in-process) by the same accepted precedent as `app/jobs.py`; documented explicitly. |
| 12 | Real Qdrant integration smoke test | **FIXED (test written); NOT EXECUTED — see item 14 for why** | `tests/test_qdrant_integration.py` added: creates a uniquely-named disposable test collection (never the configured production collection name), inserts vectors with `knowledge_base_id`/`is_published` payload variations, verifies both filters independently, verifies retrieval, verifies the dimension-mismatch failure path against a real client, and tears down its own collection in a `finally` block. Skipped by default (`QDRANT_INTEGRATION_TEST=1` required). **Not run during this delivery's preparation** — no Docker daemon was available in the sandbox used to prepare this package, so no real Qdrant server could be started. This is disclosed here and in `TEST_PHASE_B.md`, not silently omitted. |
| 13 | Preserve all safety boundaries | **FIXED (carried forward, re-verified)** | Isolated `dt-rag` Compose project unchanged in identity; `rag-api`/`rag-qdrant`/`rag-ingestion` only; no Postgres, worker, WhatsApp, Mattermost, widget, voice, or duplicate MySQL added. No `--remove-orphans`/prune commands anywhere in any doc or script. No `docker compose down` recommended against the existing `docker` project anywhere. All commands in `INSTALL_PHASE_B.md`/`ROLLBACK_PHASE_B.md` use `docker compose -p dt-rag ...` explicitly. |
| 14 | Re-run and report tests | **PARTIALLY EXECUTED — reported honestly, not overclaimed** | See `TEST_PHASE_B.md` for the full breakdown. Summary: (1) Python syntax/compile checks — **executed**, all files clean. (2) Unit tests — **executed for real** against the exact pinned dependency versions in a real virtualenv: **103 passed, 3 skipped, 0 failed** (skips are the by-design live-Gemini and real-Qdrant tests). Two real bugs and one over-strict test were found and fixed during this actual run (the escalation-logic bug in item 10, the middleware config-caching bug in item 8, and a false-positive in the MySQL-import architectural test) — none of these were "found" by inspection, they were caught by tests genuinely failing first. (3) Dependency/import checks — **executed**: installed the exact pinned versions into a clean venv, resolved a real `pydantic` version conflict pip surfaced, confirmed `qdrant-client.search` is actually gone and `google-genai`'s `client.aio.models.*` surface is actually intact by direct attribute inspection, not by reading changelogs alone. (4) `docker compose config` — **not validated via the real `docker compose` binary** (not installed in this sandbox); the YAML was instead parsed and validated with Python's `yaml` library, confirming syntactically valid structure and the three expected services — this is a weaker check than the real command and is disclosed as such. (5) Docker image builds — **NOT executed**, no Docker daemon available in this sandbox. (6) Real Qdrant integration test — **NOT executed**, same reason. (7) Exact pass/fail/skip totals — reported above and in `TEST_PHASE_B.md`, from an actual pytest run, not estimated. |
| 15 | Repackage with distinguishable filename | **FIXED** | `dt-rag-phaseb-delivery-v2.zip`, does not overwrite the V1 delivery. No `.env`, API keys, tokens, cache files, or Qdrant data included (verified via `unzip -l` pattern check before delivery). |

## Summary of genuinely new files (V2)

- `backend/app/request_size.py` — request-size limiting middleware
- `backend/app/runtime_config.py` — server-to-server RAG config sync store
- `tests/test_reindex_nondestructive.py`, `tests/test_request_size.py`, `tests/test_no_context_chat.py`, `tests/test_config_sync.py`, `tests/test_qdrant_integration.py`

## Summary of files with substantive rewrites (V2)

`app/config.py`, `app/auth.py`, `app/gemini_client.py`, `app/vector_store.py`, `app/grounding.py`, `app/main.py`, `app/chunking.py` (tiktoken-failure resilience, found necessary during real testing in this sandbox), `ingestion/ssrf_guard.py`, `ingestion/crawler.py`, `ingestion/pipeline.py`, `ingestion/cli.py`, `docker-compose.yml`, `backend/Dockerfile`, `backend/Dockerfile.ingestion`, `backend/requirements.txt`, `.env.example`, `tests/conftest.py`, `tests/test_auth.py`, `tests/test_ssrf_guard.py`, `tests/test_redirect_ssrf.py`, `tests/test_retrieval_filtering.py`, `tests/test_grounding_policy.py`.
