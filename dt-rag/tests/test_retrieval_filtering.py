"""
V2: rewritten for query_points() (search() no longer exists in
qdrant-client 1.19.0 -- confirmed by direct inspection of the installed
client). query_points() returns an object with a `.points` list; our
vector_store.search() wrapper already unwraps this, so these tests mock
query_points and assert on its call arguments and on the unwrapped result.
"""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "backend"))

import pytest
from unittest.mock import AsyncMock, MagicMock, patch

pytestmark = pytest.mark.asyncio


def _fake_query_response(points):
    resp = MagicMock()
    resp.points = points
    return resp


async def test_search_filters_by_knowledge_base_id():
    from app import vector_store

    fake_client = AsyncMock()
    fake_client.query_points = AsyncMock(return_value=_fake_query_response([]))

    with patch("app.vector_store.get_client", return_value=fake_client):
        await vector_store.search([0.1, 0.2], knowledge_base_id=42, top_k=5, score_threshold=0.7)

    call_kwargs = fake_client.query_points.call_args.kwargs
    query_filter = call_kwargs["query_filter"]
    conditions = query_filter.must
    kb_conditions = [c for c in conditions if getattr(c, "key", None) == "knowledge_base_id"]
    assert len(kb_conditions) == 1
    assert kb_conditions[0].match.value == 42
    assert call_kwargs["query"] == [0.1, 0.2]


async def test_search_excludes_unpublished_by_default():
    from app import vector_store

    fake_client = AsyncMock()
    fake_client.query_points = AsyncMock(return_value=_fake_query_response([]))

    with patch("app.vector_store.get_client", return_value=fake_client):
        await vector_store.search([0.1, 0.2], top_k=5, score_threshold=0.7)

    call_kwargs = fake_client.query_points.call_args.kwargs
    conditions = call_kwargs["query_filter"].must
    published_conditions = [c for c in conditions if getattr(c, "key", None) == "is_published"]
    assert len(published_conditions) == 1
    assert published_conditions[0].match.value is True


async def test_search_can_include_unpublished_when_explicitly_requested():
    from app import vector_store

    fake_client = AsyncMock()
    fake_client.query_points = AsyncMock(return_value=_fake_query_response([]))

    with patch("app.vector_store.get_client", return_value=fake_client):
        await vector_store.search([0.1, 0.2], top_k=5, score_threshold=0.7, include_unpublished=True)

    call_kwargs = fake_client.query_points.call_args.kwargs
    conditions = call_kwargs["query_filter"].must if call_kwargs["query_filter"] else []
    published_conditions = [c for c in conditions if getattr(c, "key", None) == "is_published"]
    assert len(published_conditions) == 0


async def test_search_returns_unwrapped_points_list():
    from app import vector_store

    fake_point = MagicMock()
    fake_point.score = 0.9
    fake_client = AsyncMock()
    fake_client.query_points = AsyncMock(return_value=_fake_query_response([fake_point]))

    with patch("app.vector_store.get_client", return_value=fake_client):
        results = await vector_store.search([0.1, 0.2], top_k=5, score_threshold=0.7)

    assert results == [fake_point]  # .points was correctly unwrapped, not the whole response object


async def test_search_wraps_qdrant_exception_in_vector_store_error():
    from app import vector_store

    fake_client = AsyncMock()
    fake_client.query_points = AsyncMock(side_effect=RuntimeError("connection refused"))

    with patch("app.vector_store.get_client", return_value=fake_client):
        with pytest.raises(vector_store.VectorStoreError):
            await vector_store.search([0.1, 0.2], top_k=5, score_threshold=0.7)


async def test_search_does_not_call_removed_search_method():
    """Explicit regression guard: .search() does not exist on the pinned
    qdrant-client version at all -- if vector_store.py ever regresses to
    calling it, this proves the client object itself has no such attribute."""
    from qdrant_client import AsyncQdrantClient
    assert not hasattr(AsyncQdrantClient, "search")
    assert hasattr(AsyncQdrantClient, "query_points")
