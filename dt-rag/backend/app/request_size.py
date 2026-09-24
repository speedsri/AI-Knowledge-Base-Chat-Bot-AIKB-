"""
V2 addition: real, enforced request-body size protection (item 8). Prior
docs claimed this existed; it did not. This is a genuine ASGI middleware
that rejects oversized bodies with HTTP 413 BEFORE the body is fully read
where possible (fast path: Content-Length header check), and enforces the
same bound while streaming for chunked/unknown-length bodies (slow path),
aborting the read without ever logging the oversized content.
"""
import logging
from starlette.types import ASGIApp, Receive, Scope, Send
from starlette.responses import PlainTextResponse
from app.config import settings

logger = logging.getLogger("dt-rag.request_size")


class _BodyTooLarge(Exception):
    pass


class RequestSizeLimitMiddleware:
    def __init__(self, app: ASGIApp, max_bytes: int | None = None):
        self.app = app
        # V2 fix: NOT captured at construction time. `settings.max_request_body_bytes`
        # is read fresh on every request below (self._explicit_override, if given,
        # still takes precedence -- used only by callers that want a fixed value
        # regardless of live config, which no current caller does).
        self._explicit_override = max_bytes

    async def __call__(self, scope: Scope, receive: Receive, send: Send):
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        max_bytes = self._explicit_override or settings.max_request_body_bytes

        headers = dict(scope.get("headers", []))
        content_length = headers.get(b"content-length")
        if content_length is not None:
            try:
                declared_size = int(content_length)
            except ValueError:
                declared_size = None
            if declared_size is not None and declared_size > max_bytes:
                logger.warning(f"request_size.rejected_by_content_length declared_bytes={declared_size}")
                response = PlainTextResponse("Request body too large", status_code=413)
                await response(scope, receive, send)
                return

        total_received = 0

        async def limited_receive():
            nonlocal total_received
            message = await receive()
            if message["type"] == "http.request":
                body = message.get("body", b"")
                total_received += len(body)
                if total_received > max_bytes:
                    raise _BodyTooLarge()
            return message

        try:
            await self.app(scope, limited_receive, send)
        except _BodyTooLarge:
            logger.warning("request_size.rejected_streaming_body")
            response = PlainTextResponse("Request body too large", status_code=413)
            await response(scope, receive, send)
