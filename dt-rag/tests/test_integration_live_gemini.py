"""
Optional integration test against the REAL Gemini API. Skipped automatically
unless a real GEMINI_API_KEY is present in the environment when running
pytest -- never runs in normal CI, never spends quota unexpectedly.

Run explicitly with:
    GEMINI_API_KEY=your-real-key pytest tests/test_integration_live_gemini.py -v

V3: extended per the Gemini embedding-2 patch to verify, against the REAL
API, the specific things that were broken/corrected in this patch:
  - two independent text inputs actually produce two DISTINCT embeddings
    (not the aggregation bug)
  - each returned vector has the configured EMBEDDING_DIMENSION
  - generation with the configured Gemini 3.8 (or whatever GENERATION_MODEL
    is currently configured) model succeeds

IMPORTANT: these tests were NOT executed with a real API key while
preparing this delivery -- no key was available/appropriate to use in that
environment. This is disclosed in TEST_PHASE_B.md. Do not trust a claim
that these passed unless they were actually run with a real key and the
output reviewed.
"""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest

pytestmark = pytest.mark.asyncio

_HAS_REAL_KEY = bool(os.environ.get("GEMINI_API_KEY")) and os.environ.get("GEMINI_API_KEY") != ""


@pytest.mark.skipif(not _HAS_REAL_KEY, reason="GEMINI_API_KEY not set -- skipping live integration test")
async def test_live_embedding_call_returns_correct_dimension():
    from app import gemini_client
    from app.config import settings
    gemini_client._client = None
    vector = await gemini_client.embed_text("integration test query", task_type="RETRIEVAL_QUERY")
    assert len(vector) == settings.embedding_dimension


@pytest.mark.skipif(not _HAS_REAL_KEY, reason="GEMINI_API_KEY not set -- skipping live integration test")
async def test_live_two_inputs_produce_two_distinct_embeddings():
    """
    The specific real-API regression test for the batching bug this patch
    fixes: two DIFFERENT texts must produce two DIFFERENT, independently
    meaningful embeddings -- not one aggregated embedding repeated, and not
    a count mismatch.
    """
    from app import gemini_client
    gemini_client._client = None
    vectors = await gemini_client.embed_texts(
        ["The sky is blue due to Rayleigh scattering.", "Paris is the capital of France."],
        task_type="RETRIEVAL_DOCUMENT",
    )
    assert len(vectors) == 2
    assert vectors[0] != vectors[1]  # must be genuinely distinct embeddings, not the same aggregated vector twice
    from app.config import settings
    assert len(vectors[0]) == settings.embedding_dimension
    assert len(vectors[1]) == settings.embedding_dimension


@pytest.mark.skipif(not _HAS_REAL_KEY, reason="GEMINI_API_KEY not set -- skipping live integration test")
async def test_live_generation_call_succeeds_with_configured_model():
    from app import gemini_client
    gemini_client._client = None
    answer, confidence = await gemini_client.generate_answer(
        model=gemini_client.settings.generation_model,
        system_prompt="You are a helpful assistant. Be extremely brief.",
        context_blocks=[{"text": "The sky is blue due to Rayleigh scattering.", "source_url": None}],
        history=[],
        user_query="Why is the sky blue?",
    )
    assert len(answer.strip()) > 0
    assert 0.0 <= confidence <= 1.0
