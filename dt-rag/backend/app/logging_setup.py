"""
Structured JSON logging with mandatory secret scrubbing. Every log call
should go through `log_event()` rather than raw `logger.info(...)` with
f-strings, so correlation fields stay consistent and secrets can never
accidentally leak into a log line via string interpolation.
"""
import json
import logging
import sys
import time
from app.config import settings

_SENSITIVE_KEYS = {"gemini_api_key", "api_key", "authorization", "rag_internal_token", "token", "password"}


def _scrub(d: dict) -> dict:
    out = {}
    for k, v in d.items():
        if k.lower() in _SENSITIVE_KEYS:
            out[k] = "[REDACTED]"
        elif isinstance(v, dict):
            out[k] = _scrub(v)
        else:
            out[k] = v
    return out


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload = {
            "ts": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(record.created)),
            "level": record.levelname,
            "event": record.getMessage(),
        }
        if hasattr(record, "fields"):
            payload.update(_scrub(record.fields))
        return json.dumps(payload)


def configure_logging():
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    root = logging.getLogger()
    root.handlers = [handler]
    root.setLevel(getattr(logging, settings.log_level.upper(), logging.INFO))


def log_event(logger: logging.Logger, level: str, event: str, **fields):
    """
    Usage: log_event(logger, "info", "chat.request", request_id=rid,
                      knowledge_base_id=kb_id, latency_ms=latency)
    Never pass gemini_api_key, rag_internal_token, or full Authorization
    headers as fields -- they are scrubbed if you do, but don't rely on that
    as your only safeguard; keep secrets out of the call entirely.
    """
    getattr(logger, level)(event, extra={"fields": fields})
