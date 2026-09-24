"""
V2 addition (item 11): a trusted server-to-server mechanism so AIKB (the
control plane, which owns system_prompt/temperature/top_k_retrieval/
similarity_threshold/escalation_keywords in MySQL) can push the EFFECTIVE
RAG configuration to rag-api, without rag-api ever reading/writing MySQL
directly and without any of this ever reaching a browser.

This is push-based synchronization: AIKB calls POST /v1/config/sync
whenever an admin changes RAG Settings in its own UI. rag-api stores the
synced values in-process (same ephemeral-is-acceptable pattern already
established for app/jobs.py -- see ARCHITECTURE_PHASE_B.md for why that
precedent applies here too: if rag-api restarts, it falls back to safe
built-in defaults until AIKB syncs again, which is an acceptable gap for
Phase B and does not violate the control-plane/execution-plane boundary
since MySQL remains the actual source of truth regardless of whether
rag-api currently has a fresh copy).

Not implemented: rag-api never calls AIKB to pull this data (no reverse
dependency), and no public/browser-facing endpoint exposes any of this --
only the existing /v1/* internal-token-gated surface.
"""
import threading

_lock = threading.Lock()

_DEFAULTS = {
    "system_prompt": (
        "You are Dynamic Technologies' assistant. Answer only using the "
        "retrieved reference data provided to you. If it does not contain "
        "enough information, say so plainly rather than guessing."
    ),
    "temperature": 0.4,
    "top_k_retrieval": None,   # None = fall back to settings.top_k_default
    "similarity_threshold": None,  # None = fall back to settings.similarity_threshold_default
    "escalation_keywords": [],  # intentionally empty until AIKB syncs real values -- NOT hardcoded permanently silent, see note below
}

_current = dict(_DEFAULTS)


def sync_config(**fields) -> dict:
    """Called by /v1/config/sync. Only known keys are accepted; unknown
    keys are ignored rather than silently stored, to keep this a well-
    defined contract rather than an arbitrary bag of values."""
    with _lock:
        for key in _DEFAULTS:
            if key in fields and fields[key] is not None:
                _current[key] = fields[key]
        return dict(_current)


def get_config() -> dict:
    with _lock:
        return dict(_current)


def reset_to_defaults() -> dict:
    """Used by tests; also a reasonable operational escape hatch."""
    with _lock:
        _current.clear()
        _current.update(_DEFAULTS)
        return dict(_current)
