# Security - Phase B

## Authentication

Every /v1/* endpoint requires Authorization: Bearer <RAG_INTERNAL_TOKEN>.
Comparison uses hmac.compare_digest (app/auth.py) -- constant-time, so
response timing cannot be used to guess the token character-by-character.
The token is never echoed in any response body, never logged (see Logging
below), and never sent to a browser -- only .215 and AIKB (.220) hold it,
server-side, in their own configuration.

## Rate limiting

In-process token bucket, per source IP, default 120 requests/minute
(RATE_LIMIT_PER_MINUTE). Appropriate for a single rag-api replica with a
small, known caller set. Protects primarily against accidental request
loops in calling code, not a determined external attacker -- the network
boundary (LAN-only bind, to be firewalled further) is the primary defense
against that.

## SSRF defenses (ingestion/ssrf_guard.py)

Threat model: the crawler fetches URLs discovered by following links on a
target site. Those links could point anywhere, including internal
infrastructure, if the crawler naively followed them.

Defenses, in order:
1. Scheme allowlist: http/https only. file://, ftp://, etc. rejected.
2. DNS resolution happens BEFORE connecting, and every resolved IP address
   is checked against a deny-list: loopback (127.0.0.0/8, ::1), link-local
   including the common cloud metadata endpoint 169.254.169.254
   (169.254.0.0/16, fe80::/10), and private ranges (10/8, 172.16/12,
   192.168/16, fc00::/7 unique-local IPv6).
3. IP-literal URLs (http://127.0.0.1/) are checked directly, before DNS
   resolution even happens, since they would otherwise bypass the DNS-based
   check entirely.
4. Every redirect target is re-validated with the SAME checks before being
   followed. This is the most commonly missed SSRF vector: a URL that
   resolves safely but redirects to an internal address. Tested explicitly
   in tests/test_redirect_ssrf.py.
5. Private ranges can be explicitly allow-listed via
   CRAWLER_ALLOWLISTED_PRIVATE_RANGES for a future, deliberately-reviewed
   internal-crawl use case. Empty by default. The always-denied set
   (loopback, link-local) can NEVER be allow-listed, regardless of this
   setting -- tested explicitly.

## Prompt-injection isolation (app/grounding.py)

Retrieved content (from crawled pages or manually-entered documents) is
wrapped in an explicit `=== BEGIN/END UNTRUSTED REFERENCE DATA ===` block
in the prompt sent to Gemini, with an inline instruction that any
instruction-like text within it must be treated as data, never obeyed. The
system-level instructions are written before this block, in a portion of
the prompt the untrusted content can never reach or precede. Tested
explicitly in test_grounding_policy.py, including a simulated injection
attempt ("IGNORE ALL PREVIOUS INSTRUCTIONS...") to confirm it stays
strictly inside the untrusted-data markers.

This is defense-in-depth, not a guarantee -- no prompt-based defense against
injection is airtight against a sufficiently adversarial payload. If this
becomes a demonstrated problem in practice, the next escalation is
output-side filtering or a second classification pass, not implemented in
Phase B.

## Grounded-answer policy (no-fabrication guarantee)

/v1/chat retrieves first, generates only from retrieved context. If
retrieval or generation fails outright (Gemini down, Qdrant unreachable,
timeout), the endpoint returns HTTP 503 -- it never falls back to answering
from the model's general knowledge and presenting that as a Dynamic
Technologies-specific answer. A successful-but-low-confidence answer is
still returned (with escalated=true), since "I don't know, let me get a
human" is a legitimate and honest answer; a hard technical failure returns
no answer at all rather than guessing why it failed and generating an
excuse.

## Request size and validation

Every request body is a Pydantic model with explicit field constraints
(chat message capped at 4000 chars, retrieval query capped at 4000 chars,
document content_hash must be exactly a 64-char hex string, all numeric
IDs must be >= 1, crawl safety limits are bounded ranges not arbitrary
integers). Requests failing validation get HTTP 422 automatically.

## Logging (app/logging_setup.py)

Structured JSON logs via log_event(), with a mandatory scrub of any field
named gemini_api_key, api_key, authorization, rag_internal_token, token, or
password -- these are replaced with "[REDACTED]" even if accidentally
passed. Correlation fields used throughout: request_id, knowledge_base_id,
document_id, document_version_id, job_id, latency_ms, model, error_code.
Never logs full user message content or full Authorization headers.

## Outbound request safety

Every Gemini call has an explicit timeout (REQUEST_TIMEOUT, default 25s)
and a bounded exponential-backoff retry (tenacity, max 3 attempts) --
failures surface as a typed GeminiError rather than hanging or crashing the
process. Every crawler fetch has its own configurable timeout
(request_timeout_seconds) independent of the Gemini timeout.

## What is explicitly NOT covered in Phase B (documented gap, not hidden)

- No dedicated Gemini spend cap/alert.
- No output-side prompt-injection detection (input-side isolation only).
- rag-api's port (192.168.1.220:8500) is not yet firewalled to specific
  caller IPs -- LAN-wide bind for now, pending confirmation of which
  firewall manager is authoritative on .220 (never assumed, never guessed
  at with a blind ufw command against an unconfirmed system).
- CSRF is not applicable here (rag-api has no browser-facing forms; all
  callers are authenticated server-to-server clients).

## V2 Corrections

### SSRF — DNS-rebinding / TOCTOU closed

The V1 design validated a hostname's DNS resolution once, then let httpx
perform its own independent resolution when actually connecting — a
textbook rebinding gap. `ingestion/ssrf_guard.py::resolve_and_validate()`
now resolves exactly once and returns the specific validated IP;
`crawler.py` connects directly to that IP (never re-resolving), preserving
the original `Host` header and TLS SNI via httpx's `sni_hostname` request
extension. Verified genuinely honored by the pinned httpx/httpcore version
by reading the installed library's own source, not assumed from
documentation. Every redirect hop repeats the full resolve-and-validate
sequence independently — proven by a test asserting the exact call count
across a multi-hop redirect chain.

Deny-by-default policy expanded to explicitly include multicast (a real
gap found during testing — Python's `ipaddress.is_global` does not
classify multicast addresses as non-global, so an explicit `is_multicast`
check was added), carrier-grade NAT, and the IANA TEST-NET/benchmarking
ranges, alongside the existing loopback/RFC1918/link-local coverage.

### RAG_INTERNAL_TOKEN — enforced, not just recommended

`app/config.py` now raises a validation error (application fails to start)
if the token is empty, whitespace-only, or under 32 characters. Verified
via real subprocess execution, not a mock — a fresh process attempting to
import the config module genuinely exits non-zero with an actionable error
message. Auth failures are now consistently 401 (missing/malformed header)
vs. 403 (wrong token), rather than FastAPI's generic 422 for a missing
header.

### Request size — genuinely enforced

`app/request_size.py` is real ASGI middleware, not documentation. Rejects
oversized bodies with HTTP 413, fast-path via `Content-Length` before any
read, slow-path enforcement during streaming, never logs oversized
content. `MAX_REQUEST_BODY_BYTES` configurable.

### Confidence policy — corrected to be actually retrieval-primary

Testing surfaced a real logic bug in the first draft of the confidence
policy: `detect_escalation()` combined a "weak confidence" condition with
"no good match" using AND, which meant a high (possibly spoofed or
mistakenly-wired) confidence value could suppress escalation even when
actual retrieval evidence was weak — precisely the failure mode this
policy exists to prevent. Fixed: escalation is now decided from retrieval
evidence (`top_score`) alone, plus keyword matches; the confidence
parameter is explicitly unused for this decision. See `CHANGELOG.md`
item 10 and `app/grounding.py`'s module docstring.

## V3 Corrections

No security-relevant behavior changed in this patch — it is a Gemini API
correctness patch only (embedding batching, task-type/normalization
accuracy, generation model/config update). The embedding-count-mismatch
guard added in `app/gemini_client.py` (raises rather than silently
zipping a wrong vector to a wrong chunk) is arguably a data-integrity
safety improvement worth noting here: without it, a provider-side batching
quirk could have silently corrupted which embedding was associated with
which document chunk, with no error raised at any layer.
