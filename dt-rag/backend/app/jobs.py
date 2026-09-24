"""
In-process, ephemeral ingestion job tracker.

PHASE C ADDITION: jobs now retain `pages` -- the prepared, per-page crawl
results (canonical_url, title, normalized_content, content_hash,
success/error) -- so AIKB can pull them via GET /v1/ingestion/result/{job_id}
and import them into MySQL itself. This does NOT change the architectural
rule that the crawler never writes to Qdrant -- these are held in memory
here only until AIKB retrieves and imports them.

BOUNDED RETENTION (required by the Phase C brief, "memory cannot grow
forever"):
  - At most MAX_JOBS_RETAINED jobs are kept; the oldest (by created_at)
    are evicted once the cap is exceeded.
  - A job's full `pages` payload (potentially the bulk of its memory
    footprint) is purged after PAGE_RESULT_TTL_SECONDS, leaving only the
    summary counters (pages_crawled, documents_changed, etc.) -- so a job
    AIKB never came back to collect doesn't hold its crawled text forever.

V2 FIX (Blocker 4): cleanup now runs opportunistically from get_job() and
job_count() as well as create_job() -- not just create_job(). The V1
version only ran cleanup on create_job(), so a page payload could remain
in memory well past PAGE_RESULT_TTL_SECONDS if no new job happened to be
created in the meantime (e.g. a quiet period with no new crawls started,
while an admin might still be polling GET /v1/ingestion/result/{job_id}
for an old job). Running cleanup on every read path closes that gap
without introducing a background thread/process.
"""
import threading
import uuid
from datetime import datetime, timezone
from enum import Enum

MAX_JOBS_RETAINED = 50
PAGE_RESULT_TTL_SECONDS = 3600  # 1 hour


class JobStatus(str, Enum):
    PENDING = "pending"
    RUNNING = "running"
    DONE = "done"
    FAILED = "failed"


_jobs: dict[str, dict] = {}
_lock = threading.Lock()


def _now() -> datetime:
    return datetime.now(timezone.utc)


def _cleanup_locked():
    """Must be called while holding _lock."""
    now = _now()

    # 1. Purge stale `pages` payloads (keep summary fields).
    for job in _jobs.values():
        if job.get("pages") is None:
            continue
        created_at = datetime.fromisoformat(job["created_at"])
        if (now - created_at).total_seconds() > PAGE_RESULT_TTL_SECONDS:
            job["pages"] = None
            job["pages_purged"] = True

    # 2. Evict oldest jobs beyond the retention cap.
    if len(_jobs) > MAX_JOBS_RETAINED:
        ordered = sorted(_jobs.items(), key=lambda kv: kv[1]["created_at"])
        excess = len(_jobs) - MAX_JOBS_RETAINED
        for job_id, _job in ordered[:excess]:
            del _jobs[job_id]


def create_job(external_job_id: str | None = None) -> str:
    job_id = str(uuid.uuid4())
    with _lock:
        _jobs[job_id] = {
            "job_id": job_id,
            "external_job_id": external_job_id,
            "status": JobStatus.PENDING.value,
            "pages_crawled": 0,
            "documents_changed": 0,
            "documents_unchanged": 0,
            "documents_failed": 0,
            "error": None,
            "pages": None,          # populated on completion -- see main.py::_run_crawl_job
            "pages_purged": False,  # true once the TTL-based purge above has run on this job
            "created_at": _now().isoformat(),
            "updated_at": _now().isoformat(),
        }
        # Cleanup runs AFTER insertion so the retention cap is enforced
        # against the true post-insert count, not the pre-insert count
        # (running it before insertion allowed the job total to exceed
        # MAX_JOBS_RETAINED by exactly one until the *next* call -- a real
        # off-by-one caught by tests/test_ingestion_result.py during
        # actual execution of this patch, not by inspection).
        _cleanup_locked()
    return job_id


def update_job(job_id: str, **fields):
    with _lock:
        if job_id in _jobs:
            _jobs[job_id].update(fields)
            _jobs[job_id]["updated_at"] = _now().isoformat()


def get_job(job_id: str) -> dict | None:
    with _lock:
        _cleanup_locked()
        return dict(_jobs[job_id]) if job_id in _jobs else None


def job_count() -> int:
    with _lock:
        _cleanup_locked()
        return len(_jobs)
