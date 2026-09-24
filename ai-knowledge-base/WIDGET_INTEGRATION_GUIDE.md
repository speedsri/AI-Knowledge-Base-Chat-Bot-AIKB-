# AIKB Generic Widget Integration

The embeddable AIKB widget is optional and remains installed even when no website uses it.

## Safe inactive state

In `.env`:

CHAT_WIDGET_ENABLED=true
CHAT_WIDGET_ALLOWED_ORIGINS=
CHAT_WIDGET_MAX_ATTEMPTS=20
CHAT_WIDGET_WINDOW_MINUTES=5

An empty allowed-origin list means no external website can use the widget.

## Authorize a website

Example:

CHAT_WIDGET_ALLOWED_ORIGINS=https://example.com,https://www.example.com

Use origins only, not page URLs.

After changing `.env`, rebuild only AIKB:

docker compose -f docker/docker-compose.yml build app
docker compose -f docker/docker-compose.yml up -d --no-deps --force-recreate app

Never use:
- docker compose down
- docker compose down -v
- --remove-orphans
- docker system prune

## Embed snippet

Place before the website's closing body tag:

<script
    src="https://ai.dynamictecsl.site/assets/chat-widget.js"
    data-api="https://ai.dynamictecsl.site"
    data-title="Your Company AI Assistant"
    data-greeting="Hello. How can I help you today?"
    data-position="right"
    data-language="en-US">
</script>

## Features

- Text chat
- Voice input
- Spoken replies
- Mute/unmute
- RAG/Gemini answers
- Conversation logging
- Domain allow-list
- DB-backed rate limiting

Voice requires HTTPS, microphone permission, and browser speech support.

## Security

The browser never receives Gemini keys, RAG_INTERNAL_TOKEN, CHAT_GATEWAY_TOKEN, or database passwords.

Unauthorized origins receive:

{"ok":false,"error":"origin_not_allowed"}

## Remove from a website

Delete the embed script from that website and, if required, remove its origin from CHAT_WIDGET_ALLOWED_ORIGINS.

The AIKB widget remains installed for future use.
