"""
Deterministic chunking + hashing.

V2 hardening (found while testing this patch, worth fixing regardless):
tiktoken's cl100k_base encoding is not bundled in the package -- it is
downloaded on first use from an external blob endpoint
(openaipublic.blob.core.windows.net). If that endpoint is unreachable at
container startup (firewalled egress, transient outage, etc.), the ORIGINAL
module-level `tiktoken.get_encoding(...)` call would crash on import,
taking down the entire application merely because tokenizer data couldn't
be fetched. This version lazy-loads the encoder on first actual use and
falls back to a simple character-based token estimator if the real
tokenizer can't be loaded, logging a clear warning rather than crashing.
The fallback is only an approximation (roughly 4 chars/token, a standard
English-text heuristic) -- chunk boundaries will be less precise than with
the real tokenizer, but ingestion keeps working instead of failing outright.

Operational recommendation (see INSTALL_PHASE_B.md): pre-warm the tiktoken
cache during the Docker image build (a RUN step that calls
tiktoken.get_encoding('cl100k_base') once, with build-time network access)
so this fallback is never actually needed in normal operation, and
container startup doesn't depend on reaching an external endpoint at all.
"""
import hashlib
import logging

logger = logging.getLogger("dt-rag.chunking")

_enc = None
_enc_load_failed = False


def _get_encoder():
    global _enc, _enc_load_failed
    if _enc is not None or _enc_load_failed:
        return _enc
    try:
        import tiktoken
        _enc = tiktoken.get_encoding("cl100k_base")
    except Exception as e:
        logger.warning(
            f"chunking.tiktoken_unavailable falling back to character-based token estimate: {type(e).__name__}"
        )
        _enc_load_failed = True
    return _enc


def sha256_hex(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _encode(text: str) -> list:
    enc = _get_encoder()
    if enc is not None:
        return enc.encode(text)
    # Fallback: treat each ~4-character run as one "token" for the purpose
    # of chunk-size estimation only. Not used for anything billed or exact.
    return list(range(0, len(text), 4))


def _decode(tokens: list, text: str, max_tokens: int) -> str:
    enc = _get_encoder()
    if enc is not None:
        return enc.decode(tokens)
    # Fallback decode: reconstruct the corresponding character slice.
    start_char = tokens[0] if tokens else 0
    end_char = min(len(text), start_char + max_tokens * 4)
    return text[start_char:end_char]


def chunk_text(text: str, max_tokens: int = 400, overlap_tokens: int = 60) -> list[str]:
    if max_tokens <= overlap_tokens:
        raise ValueError("max_tokens must be greater than overlap_tokens")

    enc = _get_encoder()
    if enc is not None:
        tokens = enc.encode(text)
        if len(tokens) <= max_tokens:
            return [text] if text.strip() else []
        chunks = []
        start = 0
        while start < len(tokens):
            end = min(start + max_tokens, len(tokens))
            chunk = enc.decode(tokens[start:end])
            if chunk.strip():
                chunks.append(chunk)
            if end == len(tokens):
                break
            start = end - overlap_tokens
        return chunks

    # Fallback path: simple character-window chunking (~4 chars/token).
    if not text.strip():
        return []
    max_chars = max_tokens * 4
    overlap_chars = overlap_tokens * 4
    if len(text) <= max_chars:
        return [text]
    chunks = []
    start = 0
    while start < len(text):
        end = min(start + max_chars, len(text))
        chunk = text[start:end]
        if chunk.strip():
            chunks.append(chunk)
        if end == len(text):
            break
        start = end - overlap_chars
    return chunks
