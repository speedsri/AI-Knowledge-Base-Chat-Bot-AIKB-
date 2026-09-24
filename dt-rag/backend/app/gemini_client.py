"""
Async Gemini client wrapper. V3 corrections (re-verified against Google's
CURRENT official documentation, fresh searches performed for this patch,
not reused from earlier drafts):

1. EMBEDDING BATCHING BUG (deployment blocker, now fixed):
   Google's own documentation confirms: "gemini-embedding-001 generates
   individual embeddings for each string in a list of inputs. In contrast,
   gemini-embedding-2 produces a single, aggregated embedding when multiple
   inputs ... are provided directly in one request." This is also tracked
   as a known SDK issue (googleapis/python-genai#2523): passing a list of
   strings to `contents` for gemini-embedding-2 silently returns ONE
   embedding regardless of how many strings were passed. The confirmed fix
   (from Google's own docs and the SDK issue's own workaround) is to wrap
   each string in its own `types.Content(parts=[types.Part(text=s)])`
   object. This module does that for any embedding-2-family model, and
   ALWAYS verifies `len(resp.embeddings) == len(batch)` before accepting
   the result -- a mismatch raises GeminiError("embedding_count_mismatch")
   rather than silently zipping/truncating, which would silently associate
   the wrong vector with the wrong chunk.

2. TASK TYPE (re-verified): gemini-embedding-001 uses the `task_type`
   parameter. gemini-embedding-2 does NOT support `task_type` at all --
   Google's docs: "Note: You cannot use the task_type field for the
   gemini-embedding-2 model. Instead, include the task as an instruction in
   your prompt." The confirmed asymmetric retrieval format from Google's
   own documented example:
     query:    f"task: search result | query: {text}"
     document: f"title: {title or 'none'} | text: {text}"
   (the document-side literal template was truncated in the fetched
   documentation page during this patch's research -- RE-VERIFY the exact
   document prefix string against
   https://ai.google.dev/gemini-api/docs/embeddings ("Task types with
   Embeddings 2" section) before relying on it in production; the query
   prefix was fully confirmed).

3. NORMALIZATION (re-verified, corrected from the V2 patch's blanket
   claim): gemini-embedding-2 automatically L2-normalizes truncated
   (< 3072 dimension) output -- Google's docs state this explicitly.
   gemini-embedding-001 does NOT auto-normalize truncated output and
   requires the caller to do it manually. V3 therefore only manually
   normalizes for the -001 family; -2 family output is trusted as-is,
   accurately reflecting the provider's actual behavior rather than
   applying a redundant (though not harmful) manual step Google doesn't
   require.

4. GENERATION MODEL (re-verified): gemini-3.8-flash is GA
   ("Gemini 3.8 Flash generally available (GA)... our most intelligent
   Flash model") and its own migration guide explicitly instructs:
   "Remove temperature, top_p, and top_k." This module omits those
   parameters entirely for gemini-3.8-flash (and, defensively, for the
   3.6/3.7 line, which received the same "now deprecated" notice in an
   earlier changelog entry -- verify this holds for whatever model is
   actually configured before deploying). AIKB's temperature value is
   still accepted by this module's function signature and simply unused
   when the configured model doesn't support it -- the control-plane
   contract is unaffected (see app/schemas.py / /v1/config/sync).

RE-VERIFY all of the above against
https://ai.google.dev/gemini-api/docs/embeddings and
https://ai.google.dev/gemini-api/docs/models and
https://ai.google.dev/gemini-api/docs/changelog before every deployment --
this catalog changes fast enough that two corrections were needed to this
same file within one review cycle.
"""
import asyncio
import math
import logging
from google import genai
from google.genai import types
from tenacity import retry, wait_exponential, stop_after_attempt
from app.config import settings

logger = logging.getLogger("dt-rag.gemini")

_client: genai.Client | None = None

# Tracks where the currently active runtime credential came from.
# Never contains the credential itself.
_runtime_credential_source = (
    "env" if settings.gemini_api_key else "none"
)

# gemini-embedding-001 (and the legacy text-embedding-* family) use task_type
# and require manual normalization for truncated output.
_MODELS_USING_TASK_TYPE_PREFIXES = ("gemini-embedding-001", "embedding-001", "text-embedding")
_MODELS_REQUIRING_MANUAL_NORMALIZATION_PREFIXES = ("gemini-embedding-001", "embedding-001", "text-embedding")

# gemini-embedding-2 (and future "-2"/"-2-preview" variants) do not support
# task_type, aggregate multiple `contents` strings into one embedding
# unless each is wrapped in its own Content object, and auto-normalize
# truncated output.
_MODELS_REQUIRING_CONTENT_WRAPPING_PREFIXES = ("gemini-embedding-2",)

# Models whose current migration guidance instructs removing legacy
# sampling parameters entirely. Re-verify this list before every deployment
# -- confirmed for gemini-3.8-flash specifically ("Remove temperature,
# top_p, and top_k" -- Gemini 3.8 Flash migration guide); 3.6/3.7 received
# an earlier, softer "now deprecated" notice in the same changelog series
# and are included here defensively -- verify independently if either is
# actually configured as GENERATION_MODEL.
_MODELS_WITHOUT_SAMPLING_PARAMS_PREFIXES = ("gemini-3.6", "gemini-3.7", "gemini-3.8")


def get_client() -> genai.Client:
    global _client
    if _client is None:
        _client = genai.Client(api_key=settings.gemini_api_key or "unconfigured")
    return _client


class GeminiError(Exception):
    """Raised on any Gemini failure. Callers must treat this as 'no answer
    available' and must never fabricate a substitute answer on catching it."""
    def __init__(self, error_code: str, message: str = ""):
        self.error_code = error_code
        super().__init__(message or error_code)


def is_configured() -> bool:
    return bool(settings.gemini_api_key)


def _matches_prefix(model: str, prefixes: tuple) -> bool:
    return any(model.startswith(p) for p in prefixes)


def _model_uses_task_type(model: str) -> bool:
    return _matches_prefix(model, _MODELS_USING_TASK_TYPE_PREFIXES)


def _model_requires_content_wrapping(model: str) -> bool:
    return _matches_prefix(model, _MODELS_REQUIRING_CONTENT_WRAPPING_PREFIXES)


def _model_requires_manual_normalization(model: str) -> bool:
    return _matches_prefix(model, _MODELS_REQUIRING_MANUAL_NORMALIZATION_PREFIXES)


def _model_supports_sampling_params(model: str) -> bool:
    return not _matches_prefix(model, _MODELS_WITHOUT_SAMPLING_PARAMS_PREFIXES)


def _normalize_l2(vector: list[float]) -> list[float]:
    norm = math.sqrt(sum(x * x for x in vector))
    if norm == 0.0:
        return vector
    return [x / norm for x in vector]


def _prepare_retrieval_text(text: str, task_type: str, title: str | None = None) -> str:
    """
    Formats a text input with Google's documented asymmetric retrieval
    instruction prefix, used ONLY for embedding-2-family models (which
    don't support the task_type parameter). gemini-embedding-001 does not
    go through this function -- it uses the native task_type parameter
    instead, applied to the unmodified text.
    """
    if task_type == "RETRIEVAL_QUERY":
        return f"task: search result | query: {text}"
    if task_type == "RETRIEVAL_DOCUMENT":
        # Document-side prefix per Google's documented convention (title
        # defaults to "none" when not supplied). See module docstring note:
        # the exact literal template was truncated in the fetched page
        # during this patch's research -- RE-VERIFY before relying on this
        # in production.
        return f"title: {title or 'none'} | text: {text}"
    # Any other/unrecognized task_type: pass the text through unmodified
    # rather than guessing at a prefix format we haven't confirmed.
    return text


@retry(wait=wait_exponential(multiplier=1, min=1, max=8), stop=stop_after_attempt(3), reraise=True)
async def embed_texts(
    texts: list[str],
    task_type: str = "RETRIEVAL_DOCUMENT",
    titles: list[str | None] | None = None,
) -> list[list[float]]:
    """
    Batches internally at settings.embed_batch_size. Handles both embedding
    model families transparently:
      - gemini-embedding-001: sends task_type natively, contents as a plain
        list of strings (this family already returns one embedding per
        input for a list -- no wrapping needed), manually L2-normalizes
        truncated output.
      - gemini-embedding-2: does NOT send task_type; instead applies the
        documented text-prefix convention per input, wraps EACH input in
        its own types.Content object (required to get one embedding per
        input rather than one aggregated embedding for the whole batch),
        and trusts the provider's automatic normalization of truncated
        output.
    Always verifies the returned embedding count matches the requested
    input count -- raises GeminiError("embedding_count_mismatch") rather
    than silently accepting a wrong count.
    """
    if not is_configured():
        raise GeminiError("provider_not_configured", "GEMINI_API_KEY is not set")

    client = get_client()
    results: list[list[float]] = []
    batch_size = max(1, settings.embed_batch_size)
    model = settings.embedding_model
    uses_task_type = _model_uses_task_type(model)
    needs_content_wrapping = _model_requires_content_wrapping(model)
    needs_manual_normalization = _model_requires_manual_normalization(model) and settings.embedding_dimension < 3072

    if titles is not None and len(titles) != len(texts):
        raise ValueError("titles, if provided, must be the same length as texts")

    for i in range(0, len(texts), batch_size):
        batch = texts[i:i + batch_size]
        batch_titles = titles[i:i + batch_size] if titles is not None else [None] * len(batch)

        if needs_content_wrapping:
            # Apply the documented retrieval-instruction text prefix, then
            # wrap each input in its own Content object -- this is the
            # confirmed fix for the embedding-2 batching bug (see module
            # docstring, item 1).
            prepared = [
                _prepare_retrieval_text(text, task_type, title)
                for text, title in zip(batch, batch_titles)
            ]
            contents = [types.Content(parts=[types.Part(text=t)]) for t in prepared]
            config_kwargs = {"output_dimensionality": settings.embedding_dimension}
        else:
            contents = batch
            config_kwargs = {"output_dimensionality": settings.embedding_dimension}
            if uses_task_type:
                config_kwargs["task_type"] = task_type

        try:
            resp = await asyncio.wait_for(
                client.aio.models.embed_content(
                    model=model,
                    contents=contents,
                    config=types.EmbedContentConfig(**config_kwargs),
                ),
                timeout=settings.request_timeout,
            )
        except asyncio.TimeoutError:
            logger.error("gemini.embed_timeout")
            raise GeminiError("embed_timeout")
        except Exception as e:
            logger.error(f"gemini.embed_failed error_type={type(e).__name__}")
            raise GeminiError("embed_failed") from e

        # Explicit, mandatory guard (item 1's required safety check): never
        # silently zip/truncate a mismatched embedding count against the
        # requested inputs -- that would silently associate the wrong
        # vector with the wrong chunk of text.
        if len(resp.embeddings) != len(batch):
            logger.error(
                f"gemini.embedding_count_mismatch model={model} "
                f"requested={len(batch)} returned={len(resp.embeddings)}"
            )
            raise GeminiError(
                "embedding_count_mismatch",
                f"Requested {len(batch)} embeddings but received {len(resp.embeddings)} "
                f"from model {model}. Refusing to guess which input maps to which vector.",
            )

        for e in resp.embeddings:
            vec = list(e.values)
            if needs_manual_normalization:
                vec = _normalize_l2(vec)
            results.append(vec)

    return results


async def embed_text(text: str, task_type: str = "RETRIEVAL_QUERY", title: str | None = None) -> list[float]:
    return (await embed_texts([text], task_type=task_type, titles=[title]))[0]


async def generate_answer(
    model: str,
    system_prompt: str,
    context_blocks: list[dict],
    history: list[dict],
    user_query: str,
    temperature: float = 0.4,
    max_output_tokens: int = 1024,
) -> tuple[str, float]:
    """
    Returns (answer_text, self_reported_confidence). Raises GeminiError on
    any failure.

    V3: sampling parameters (temperature/top_p/top_k) are omitted entirely
    for models whose current migration guidance says to remove them (see
    _MODELS_WITHOUT_SAMPLING_PARAMS_PREFIXES) -- confirmed required for
    gemini-3.8-flash specifically. `temperature` remains a normal function
    parameter regardless (AIKB's control-plane value is still accepted and
    simply unused when the configured model doesn't support it -- see
    app/schemas.py's RagConfigSyncRequest, unchanged by this correction).

    Confidence policy unchanged from V2: this self-reported value is
    SECONDARY metadata only -- see app/grounding.py and
    SECURITY_PHASE_B.md "Confidence Policy".
    """
    if not is_configured():
        raise GeminiError("provider_not_configured", "GEMINI_API_KEY is not set")

    from app.grounding import build_prompt
    prompt = build_prompt(system_prompt, context_blocks, history, user_query)

    client = get_client()
    config_kwargs = {"max_output_tokens": max_output_tokens}
    if _model_supports_sampling_params(model):
        config_kwargs["temperature"] = temperature
    else:
        logger.info(f"gemini.sampling_params_omitted model={model} reason=deprecated_or_removed_by_provider")

    try:
        resp = await asyncio.wait_for(
            client.aio.models.generate_content(
                model=model,
                contents=prompt,
                config=types.GenerateContentConfig(**config_kwargs),
            ),
            timeout=settings.request_timeout,
        )
    except asyncio.TimeoutError:
        logger.error(f"gemini.generate_timeout model={model}")
        raise GeminiError("generation_timeout")
    except Exception as e:
        logger.error(f"gemini.generate_failed model={model} error_type={type(e).__name__}")
        raise GeminiError("generation_failed") from e

    raw = resp.text or ""
    if not raw.strip():
        raise GeminiError("empty_response")

    self_reported_confidence = 0.5
    answer = raw
    if "CONFIDENCE:" in raw:
        parts = raw.rsplit("CONFIDENCE:", 1)
        answer = parts[0].strip()
        try:
            self_reported_confidence = max(0.0, min(1.0, float(parts[1].strip())))
        except ValueError:
            pass
    return answer, self_reported_confidence


def runtime_credential_source() -> str:
    """
    Returns only the source label for the active credential.
    Never exposes the credential itself.
    """
    return _runtime_credential_source


async def configure_api_key(api_key: str) -> dict:
    """
    Validate a candidate Gemini API key before applying it.

    FAIL-SAFE:
    - The current working runtime client/key remains untouched unless the
      candidate successfully completes a real embedding request.
    - The API key is never returned or logged.
    """
    global _client, _runtime_credential_source

    candidate_key = api_key.strip()

    result = {
        "provider": "gemini",
        "configured": False,
        "healthy": False,
        "generation_model": settings.generation_model,
        "embedding_model": settings.embedding_model,
        "embedding_dimension": settings.embedding_dimension,
        "latency_ms": None,
        "error_code": None,
    }

    if not candidate_key:
        result["error_code"] = "provider_key_empty"
        return result

    import time
    t0 = time.perf_counter()

    try:
        candidate = genai.Client(api_key=candidate_key)

        model = settings.embedding_model

        config_kwargs = {
            "output_dimensionality": settings.embedding_dimension
        }

        if _model_requires_content_wrapping(model):
            prepared = _prepare_retrieval_text(
                "healthcheck",
                "RETRIEVAL_QUERY",
            )

            contents = [
                types.Content(
                    parts=[types.Part(text=prepared)]
                )
            ]
        else:
            contents = ["healthcheck"]

            if _model_uses_task_type(model):
                config_kwargs["task_type"] = "RETRIEVAL_QUERY"

        resp = await asyncio.wait_for(
            candidate.aio.models.embed_content(
                model=model,
                contents=contents,
                config=types.EmbedContentConfig(
                    **config_kwargs
                ),
            ),
            timeout=settings.request_timeout,
        )

        if len(resp.embeddings) != 1:
            result["error_code"] = "embedding_count_mismatch"
            result["latency_ms"] = int(
                (time.perf_counter() - t0) * 1000
            )
            return result

        vector = list(resp.embeddings[0].values)

        if len(vector) != settings.embedding_dimension:
            result["error_code"] = "dimension_mismatch"
            result["latency_ms"] = int(
                (time.perf_counter() - t0) * 1000
            )
            return result

    except asyncio.TimeoutError:
        result["error_code"] = "provider_validation_timeout"
        result["latency_ms"] = int(
            (time.perf_counter() - t0) * 1000
        )
        return result

    except Exception as e:
        logger.warning(
            "gemini.runtime_key_validation_failed "
            f"error_type={type(e).__name__}"
        )

        result["error_code"] = "provider_validation_failed"
        result["latency_ms"] = int(
            (time.perf_counter() - t0) * 1000
        )
        return result

    # Candidate has been verified. Only NOW replace the working runtime key.
    settings.gemini_api_key = candidate_key
    _client = candidate
    _runtime_credential_source = "dashboard"

    result["configured"] = True
    result["healthy"] = True
    result["latency_ms"] = int(
        (time.perf_counter() - t0) * 1000
    )

    logger.info("gemini.runtime_credential_updated")

    return result


async def test_provider() -> dict:
    """
    Backing implementation for POST /v1/provider/test. Never returns the
    API key. Performs one small real embedding call (if configured) to
    confirm the provider is actually reachable, not just "a key is present."
    """
    result = {
        "provider": "gemini",
        "configured": is_configured(),
        "healthy": False,
        "generation_model": settings.generation_model,
        "embedding_model": settings.embedding_model,
        "embedding_dimension": settings.embedding_dimension,
        "latency_ms": None,
        "error_code": None,
    }

    if not is_configured():
        result["error_code"] = "provider_not_configured"
        return result

    import time
    t0 = time.perf_counter()
    try:
        vector = await embed_text("healthcheck", task_type="RETRIEVAL_QUERY")
        if len(vector) != settings.embedding_dimension:
            result["error_code"] = "dimension_mismatch"
            result["latency_ms"] = int((time.perf_counter() - t0) * 1000)
            return result
        result["healthy"] = True
        result["latency_ms"] = int((time.perf_counter() - t0) * 1000)
    except GeminiError as e:
        result["error_code"] = e.error_code
        result["latency_ms"] = int((time.perf_counter() - t0) * 1000)

    return result
