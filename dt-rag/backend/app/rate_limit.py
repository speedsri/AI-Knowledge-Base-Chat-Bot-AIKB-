"""
In-process token bucket, per source IP. No Redis, no Postgres — appropriate
for a single rag-api replica with a small known caller set (AIKB on .220,
dt-admin4 on .215). See ARCHITECTURE_PHASE_B.md for the reasoning.
"""
import time
import threading
from collections import defaultdict
from fastapi import HTTPException, Request
from app.config import settings

_buckets: dict[str, list[float]] = defaultdict(list)
_lock = threading.Lock()


def check_rate_limit(request: Request):
    client_ip = request.client.host if request.client else "unknown"
    now = time.time()
    window_start = now - 60
    with _lock:
        timestamps = _buckets[client_ip]
        timestamps[:] = [t for t in timestamps if t > window_start]
        if len(timestamps) >= settings.rate_limit_per_minute:
            raise HTTPException(429, "Rate limit exceeded. Please slow down.")
        timestamps.append(now)
