# Multi-Domain AI Knowledge Base & Voice Assistant — Architecture & Implementation Plan

## 1. Requirements Analysis Summary

Core drivers behind every design decision below:

- **Provider-independent RAG**: retrieval logic must never assume OpenAI or Gemini internals. Embeddings, chunking, and similarity search are abstracted behind interfaces.
- **N knowledge bases, not 2**: Agriculture and Dynamic Technologies are just rows in a `knowledge_bases` table. Nothing in code may special-case them.
- **Self-hosted, boring infrastructure**: PHP 8.2 + MySQL 8 + Docker Compose. No external vector DB dependency, no microservices.
- **Grounding over fluency**: the system prompt and retrieval pipeline must make it structurally hard for the model to state unsupported facts as certain.
- **Practical voice**: browser Web Speech API only — no server-side audio pipeline.

Key tension to flag up front: MySQL 8/MariaDB has no native vector index (no `VECTOR` type, no ANN). I address this in §6.

---

## 2. Final Architecture & Directory Structure

```
/app
  /Core            # DB connection (PDO singleton), Router, Container, Config, Env loader
  /Auth            # Login, session, CSRF, RBAC middleware, password reset
  /KnowledgeBase   # KB + Category CRUD, KB selection heuristics
  /Documents       # Upload handling, text extraction, chunking pipeline
  /Website         # Site importer, crawler, diffing/re-index logic
  /AI
    /Contracts     # AIProviderInterface, EmbeddingProviderInterface
    /OpenAI        # OpenAIProvider, OpenAIEmbeddingProvider
    /Gemini        # GeminiProvider, GeminiEmbeddingProvider
    /RAG           # Retriever, ChunkScorer, ContextBuilder, PromptBuilder
    ProviderRouter.php   # fallback/priority logic
  /Chat            # Conversation + message persistence, context window trimming
  /Voice           # Server-side glue only (STT/TTS happens in browser)
  /Analytics       # Event logging, aggregation queries
  /Services        # Cross-cutting: FileStorage, HtmlCleaner, Hasher, RateLimiter

/admin             # Server-rendered admin dashboard controllers/views (thin, calls /app services)
/public            # index.php front controller, widget.js, assets, uploaded-file NEVER served from here
/config            # config.php (reads env), providers.php, categories seed data
/database
  /migrations      # numbered SQL migration files
  /seeders         # default roles, default KB categories
/scripts
  backup.sh / restore.sh
  worker.php       # CLI background job runner (cron-invoked)
/storage
  /documents       # uploaded originals — outside web root
  /logs
  /cache
/tests
  /Unit /Integration /Mocks
/docker
  Dockerfile, docker-compose.yml, .env.example, nginx or apache conf
```

**Request flow (text chat):**
`public/index.php` → Router → `Chat\ChatController` → `KnowledgeBase\Selector` (if auto mode) → `AI\RAG\Retriever` → `AI\RAG\ContextBuilder` → `AI\ProviderRouter` → provider call → `Chat\MessageStore` → JSON response with `answer` + `sources`.

**Background flow (ingestion):**
Upload/crawl request writes a row with status `Uploaded`/`Queued` → `scripts/worker.php` (cron, e.g. every minute) picks up pending jobs → runs extraction → chunking → embedding → indexing, updating status at each step so the admin UI can poll it.

---

## 3. MySQL Schema (core tables, abbreviated to key columns)

```sql
-- Identity & access
users(id, name, email UNIQUE, password_hash, totp_secret NULL, is_active, created_at)
roles(id, name)                                   -- Administrator, Editor, Viewer
permissions(id, key_name)                          -- e.g. 'documents.upload', 'kb.manage'
role_permissions(role_id, permission_id)
user_roles(user_id, role_id)

-- Knowledge structure
knowledge_bases(id, name, slug UNIQUE, description, status ENUM('active','disabled'),
                 language_hint, created_at, updated_at)

categories(id, knowledge_base_id FK, name, slug, sort_order, status, created_at)

documents(id, knowledge_base_id FK, category_id FK NULL, source_type ENUM('upload','website','manual'),
          title, original_filename, storage_path, mime_type, file_hash,
          status ENUM('Uploaded','Processing','Extracting','Chunking','Embedding','Indexed','Failed'),
          error_message NULL, priority_tier TINYINT DEFAULT 3,
          created_by FK users, created_at, updated_at)

document_versions(id, document_id FK, version_no, storage_path, content_hash,
                   created_at)                     -- keeps history on re-upload/re-crawl

document_chunks(id, document_id FK, chunk_index, content TEXT, token_count,
                 page_number NULL, section_heading NULL, content_hash,
                 created_at)
  INDEX(document_id, chunk_index)

document_embeddings(id, chunk_id FK UNIQUE, provider ENUM('openai','gemini'),
                     model, dim INT, vector LONGBLOB,   -- packed float32, see §6
                     created_at)
  INDEX(provider)

-- Website ingestion
website_sources(id, knowledge_base_id FK, domain, url, title,
                 enabled, crawl_frequency ENUM('manual','daily','weekly'),
                 last_crawled_at, next_crawl_at, status, created_at)

source_crawls(id, website_source_id FK, document_id FK NULL,
              content_hash, http_status, started_at, finished_at,
              result ENUM('created','updated','unchanged','failed'), error_message NULL)

-- Conversations
conversations(id, user_id FK NULL, knowledge_base_id FK NULL, mode ENUM('all','single'),
              locale, created_at, updated_at)
messages(id, conversation_id FK, role ENUM('user','assistant','system'),
         content MEDIUMTEXT, provider_used, retrieved_chunk_ids JSON,
         latency_ms, created_at)

feedback(id, message_id FK, conversation_id FK, rating ENUM('up','down'),
         comment NULL, created_at)

-- AI configuration
ai_providers(id, name ENUM('openai','gemini'), api_key_encrypted, model,
             temperature, max_output_tokens, timeout_seconds,
             priority TINYINT, enabled, system_prompt_override NULL)

ai_settings(id, key_name UNIQUE, value_json)        -- top_k, similarity_threshold, chunk size, etc.

system_settings(id, key_name UNIQUE, value_json)
audit_logs(id, user_id FK NULL, action, entity_type, entity_id, meta_json, ip, created_at)
analytics_events(id, event_type, knowledge_base_id NULL, provider NULL,
                  meta_json, created_at)
```

Notes:
- `priority_tier` on `documents` implements §5 (1=official upload, 2=official website, 3=approved internal, 4=other).
- `document_embeddings.vector` stores packed binary floats (see §6) rather than JSON text, for size and speed.
- API keys are stored encrypted at rest (libsodium secretbox with a key from env), never in plaintext in DB or logs.

---

## 4. RAG & Multi-Knowledge-Base Architecture

**Chunking**: recursive splitting by heading → paragraph → sentence, target ~500–800 tokens with ~15% overlap, stored per `document_chunks` row with `section_heading` preserved for citation display.

**Embeddings**: `EmbeddingProviderInterface::embed(string $text): float[]`. Both OpenAI (`text-embedding-3-small` by default, configurable) and Gemini (`text-embedding-004`-class, configurable) implementations return plain float arrays — the RAG layer never knows which provider produced them. Each embedding row records which provider/model generated it so a KB can be re-embedded cleanly if the admin switches providers.

**Knowledge-base selection (§4 of spec)**:
- *Manual*: user picks a KB or "All Knowledge" — passed straight through as a `knowledge_base_id` filter (or none) on the chunk query.
- *Automatic*: a lightweight classifier step — first pass is a cheap embedding-similarity vote (embed the question, compare against a small per-KB "centroid" or against KB name/description embeddings) rather than an extra LLM call, to keep latency and cost down. If confidence is below a configurable threshold, fall back to asking the LLM directly to pick a KB slug from the enabled list, or default to searching all KBs and showing citations that make the source KB obvious. This keeps classification cheap in the common case and correct in the ambiguous case.

**Retrieval pipeline**:
```
question → embed(question)
         → SQL pre-filter (knowledge_base_id, category_id, status='Indexed')
         → similarity scoring (see §6) → top-K (configurable, default 6)
         → drop results below similarity_threshold (configurable, default 0.72 cosine)
         → order candidates by (similarity DESC, priority_tier ASC)
         → truncate to context token budget
         → ContextBuilder formats into a structured context block with
           [KB name | Category | Source title | URL or filename/page] per chunk
```

**PromptBuilder** enforces the grounding rules from §15/§51 of the spec via a fixed system-prompt template (admin can extend, not replace, the grounding instructions):
- Answer only from the provided context unless the user explicitly requests general knowledge.
- If retrieved context is empty or all below threshold, respond with the "knowledge base does not currently contain enough information" fallback rather than calling the LLM to guess (saves a call and guarantees the behavior — this is enforced in PHP, not just prompted).
- Always emit a machine-parseable sources block (e.g. trailing JSON comment or structured field) that the ChatController converts into the citation UI, so citations are never hallucinated text — they are built from the actual retrieved `document_chunks`/`documents` rows, not from what the model claims it used.

**Multi-KB independence**: nothing in `AI/RAG` or `AI/*Provider` references "Agriculture" or "Dynamic Technologies" by name. The only domain-specific artifacts are seed data rows (KB + category names) in `database/seeders`. Adding a third KB is an admin-panel action, not a deploy.

---

## 5. Dynamic Technologies Website Import & Refresh

**Import model**: `website_sources` rows are explicitly created by an admin (URL + KB + category), not auto-discovered. A "Fetch site map" convenience action can propose URLs under the configured domain for the admin to approve, but nothing is ingested without an enabled `website_sources` row.

**Per-URL job** (`Website\Importer`):
1. `robots.txt` check for the domain (cached per domain per day); refuse if disallowed.
2. cURL fetch with timeout + configurable rate limit (min delay between requests to the same domain).
3. HTML → readable text via a DOM-based extractor: strip `<script>`/`<style>`/`<nav>`/`<footer>`/`<header>` heuristically, keep heading hierarchy, keep list/table structure converted to Markdown-ish text.
4. Compute `content_hash` (sha256 of cleaned text). Compare to the current `documents.file_hash` for that source's linked document.
   - No existing document → create it, status `Uploaded`, `source_type='website'`, `priority_tier=2`.
   - Hash unchanged → mark `source_crawls.result='unchanged'`, update `last_crawled_at`, do nothing else (avoids duplicate chunks, per §23).
   - Hash changed → create a new `document_versions` row, **delete the old chunks/embeddings for that document** (not the document row itself, to keep history via `document_versions`), then re-run chunk→embed→index. This avoids duplicate/stale chunks accumulating.
5. If the page returns 404/410 or is removed from `website_sources`, mark the document `status='Failed'` with a reason of "source removed" rather than silently keeping it queryable — retrieval excludes non-`Indexed` documents by construction.

**Scheduling**: `next_crawl_at` computed from `crawl_frequency`; `scripts/worker.php` (cron every N minutes) picks due sources, one at a time per domain, respecting the rate limit — no concurrent crawling of the same domain.

**Manual re-crawl** is the same code path triggered synchronously-enqueued from the admin UI (`POST /api/sources/crawl`), just skipping the schedule check.

---

## 6. OpenAI/Gemini Provider Abstraction & Fallback

```php
interface AIProviderInterface {
    public function complete(string $systemPrompt, array $messages, array $options): AIResponse;
}
interface EmbeddingProviderInterface {
    public function embed(string $text): array; // float[]
    public function dimensions(): int;
}
```

`OpenAIProvider` and `GeminiProvider` each implement `AIProviderInterface` against their respective chat-completion HTTP APIs; `OpenAIEmbeddingProvider`/`GeminiEmbeddingProvider` implement the embedding interface. Model name, temperature, max tokens, and timeout come from the `ai_providers` table — never hard-coded.

**`ProviderRouter`**:
- Reads enabled providers ordered by `priority`.
- Calls the highest-priority provider; on timeout, 5xx, or rate-limit response, retries that same provider up to a small configurable limit with exponential backoff (e.g. 2 attempts, 500ms/1500ms), then falls through to the next provider in priority order.
- If all providers fail, returns a controlled error (`AIProviderException`) that the ChatController turns into a friendly "the assistant is temporarily unavailable" message — never a stack trace.
- Fallback is logged (`audit_logs`/`analytics_events`) so admins can see provider health over time.

**Embedding/vector-model consistency caveat (important, flagged as risk in §7)**: OpenAI and Gemini embeddings are *not* comparable in the same vector space and typically differ in dimensionality. The system therefore embeds and searches **per-provider**: `document_embeddings` records which provider/model produced each vector, and retrieval only compares the query embedding against chunk embeddings from the *same* embedding provider/model. Practically this means: pick one embedding provider as the "active" one in `ai_settings`; if the admin switches it, a background re-embedding job must run before search quality is restored (old embeddings aren't deleted immediately — they're kept until the new ones are confirmed indexed, then pruned). Chat-completion provider (used to *generate the answer*) can fail over independently of which embedding provider is active — those are separate concerns.

---

## 7. Technically Risky / Ambiguous Requirements

1. **No native vector search in MySQL 8/MariaDB.** There's no ANN index. Approach: store embeddings as packed binary floats, and compute cosine similarity in PHP after a coarse SQL pre-filter (by `knowledge_base_id`/`category_id`/`status`). This is fine at the scale implied here (hundreds to low tens-of-thousands of chunks per KB) but will not scale to millions of chunks — documented as a known ceiling, with the interface designed so a real ANN backend (e.g. a dedicated FAISS-like PHP extension, or later a proper vector DB) can be swapped in behind the same `Retriever` interface without touching the rest of the app.
2. **Automatic KB routing accuracy.** Pure embedding-similarity classification will sometimes misroute borderline questions (e.g. "What materials are used in storage tanks?" could plausibly be agriculture *or* engineering). Mitigated with a confidence threshold + "search all, cite clearly" fallback rather than forcing a hard pick.
3. **Website scraping fragility.** Site redesigns, JS-rendered content (no headless browser in this stack — cURL only, per §37's "no unnecessary infra" constraint), and inconsistent HTML structure across pages will require per-domain extraction tuning. Initial version uses generic heuristics; may need manual correction/admin edit of cleaned content in early operation.
4. **OCR quality for scanned agricultural PDFs.** Tesseract is "good enough," not high-accuracy; garbled OCR text will degrade chunk/embedding quality. Flag documents with low OCR-confidence scores for manual review rather than silently indexing garbage.
5. **Sinhala/Tamil embedding & retrieval quality.** Both OpenAI's and Gemini's embedding models support these languages but with weaker semantic resolution than English; cross-language retrieval (query in Sinhala matching an English source document) will be noticeably weaker. This is disclosed to the admin as an expectation-setting note, not silently promised as "just works."
6. **Browser Speech Recognition support/consistency.** Web Speech API (`SpeechRecognition`) has inconsistent support outside Chromium-based browsers, and language coverage for Sinhala/Tamil recognition specifically is uneven. The UI must detect and degrade gracefully per §18, not assume availability.
7. **"All Knowledge" search cost.** Searching every enabled KB per query multiplies embedding-compare work; needs a sane top-K-per-KB-then-merge strategy rather than one giant unbounded scan.

---

## 8. Implementation Plan (Phases, matching §54 but with concrete exit criteria)

| Phase | Deliverable | Exit criteria |
|---|---|---|
| 1. Foundation | PHP skeleton, PDO/Core, migrations runner, auth (login/session/CSRF/password hash), RBAC tables + middleware, Docker Compose (app+mysql), base Bootstrap UI shell | Can log in as seeded admin; empty dashboard renders |
| 2. Multi-KB core | `knowledge_bases`/`categories` CRUD, document upload + storage, extraction (PDF/DOCX/TXT/MD/CSV/JSON), chunking, embedding job, chunk browsing in admin | Upload a PDF → see it reach `Indexed` status with visible chunks |
| 3. AI + RAG | Provider interfaces, OpenAI + Gemini chat & embedding implementations, ProviderRouter fallback, Retriever/ContextBuilder/PromptBuilder, `/api/chat`, citations rendering | Ask a question against one seeded KB, get a grounded answer with correct source citation, and get the "not enough information" fallback when appropriate |
| 4. Dynamic Technologies KB | Website source management UI, importer (fetch/clean/hash/diff), scheduler worker, DT category seed data | Import 3–4 real DT pages, confirm re-crawl with no changes produces no duplicate chunks, confirm content-change re-indexes correctly |
| 5. Agriculture KB | Agriculture category seed data, bulk document import tooling, KB auto-selection logic | Ask an agriculture question and a DT question in the same session with "All Knowledge" mode and confirm correct routing/citation |
| 6. Voice | Mic button, Web Speech STT wired to existing chat submit, TTS playback of assistant responses, language selector, graceful unsupported-browser fallback | Works end-to-end in Chrome desktop; degrades cleanly in unsupported browsers |
| 7. Widget | `widget.js` embeddable script, scoped auth token (not raw API keys), theme/branding options, KB selection param | Widget embeds on a static test HTML page and completes a full chat round trip |
| 8. Hardening | Rate limiting, structured logging, analytics dashboard, feedback capture, audit logs, backup/restore scripts, PHPUnit suite (mocked AI calls), install docs | `backup.sh && restore.sh` round-trips a working DB; test suite green without live API keys |

---

## 9. Open Questions (only where a real decision is needed from you)

1. **Deployment target for cron**: is a standard Linux host with real `cron` available, or should I build the worker loop as a long-running `while(true)` process instead (for environments without cron)?
2. **Encryption key for API keys at rest**: should this come from a `.env` value you'll manage, or do you want a first-run setup step that generates and displays it once?

Everything else in the spec I've resolved with the sensible defaults above (top-K=6, similarity threshold=0.72, chunk size ~500–800 tokens, exponential backoff 2 retries, weekly default crawl frequency) — all exposed as admin-configurable settings, not hard-coded assumptions.

---

Ready to start **Phase 1** on confirmation, or immediately if you'd rather I proceed with the defaults above without waiting on the two open questions (I can make reasonable assumptions for both and flag them clearly in the delivered code).
