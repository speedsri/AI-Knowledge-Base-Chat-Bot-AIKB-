"""
V2 correction: FastAPI's default behavior for a missing required Header(...)
parameter is HTTP 422 (a validation error), not 401. That's misleading for
an auth failure -- a missing Authorization header is an authentication
problem, not a malformed-request problem. This version makes the header
optional at the FastAPI level and raises 401 explicitly for both the
missing and malformed cases, so callers can rely on 401 meaning "you did
not authenticate" consistently.
"""
import hmac
from fastapi import HTTPException, Header
from typing import Optional
from app.config import settings


def verify_internal_token(authorization: Optional[str] = Header(None)) -> bool:
    """
    Every /v1/* endpoint requires: Authorization: Bearer <RAG_INTERNAL_TOKEN>

    - Missing header -> 401 (V2 fix: was a generic FastAPI 422 before).
    - Malformed header (no "Bearer " prefix) -> 401.
    - Present but wrong token -> 403 (distinguishes "you didn't try" from
      "you tried and failed", useful for operator debugging without
      weakening security -- a 403 already implies the caller reached this
      check with *some* credential).
    Comparison uses hmac.compare_digest -- constant-time, so response
    timing cannot be used to guess the token character-by-character. The
    token itself is never echoed back in any response or log line.
    """
    if authorization is None:
        raise HTTPException(401, "Missing Authorization header")
    if not authorization.startswith("Bearer "):
        raise HTTPException(401, "Malformed Authorization header")
    token = authorization.split(" ", 1)[1]
    if not hmac.compare_digest(token, settings.rag_internal_token):
        raise HTTPException(403, "Invalid internal token")
    return True
