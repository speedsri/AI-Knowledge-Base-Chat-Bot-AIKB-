# Complete Fresh Installation

## Components
- AIKB PHP/Apache/MySQL web application
- DT-RAG Python API
- Qdrant vector database
- AI provider integration
- Docker / Docker Compose

## 1. Extract
```bash
unzip aikb-complete-fresh-install-*.zip
cd aikb-complete-fresh-install-*
```

## 2. Configure and start DT-RAG
```bash
cd dt-rag
cp .env.example .env
nano .env
```

Fill required provider/API values, internal RAG token and Qdrant settings.

Start using the compose file included in `dt-rag/`, usually:
```bash
docker compose -f docker-compose.yml up -d --build
```

Verify its health endpoint before continuing.

## 3. Configure AIKB
```bash
cd ../ai-knowledge-base
cp .env.example .env
nano .env
```

Configure MySQL, security/session values, RAG URL/token and required provider settings.

`CHAT_WIDGET_ENABLED` remains the emergency server-level master switch.
Normal widget operation is managed from Admin -> System Settings.

## 4. Start AIKB
```bash
docker compose -f docker/docker-compose.yml build app
docker compose -f docker/docker-compose.yml up -d
```

## 5. Run migrations
```bash
docker compose -f docker/docker-compose.yml \
  run --rm --no-deps app \
  php scripts/migrate.php
```

## 6. Configure Admin -> System Settings
Configure:
- Public Base URL
- Public Chat ON/OFF
- Embedded Widget ON/OFF
- Allowed Widget Origins
- Widget Title / Greeting
- Position / Language
- Widget Voice ON/OFF
- Widget rate limits

The Public Base URL field does not create DNS, SSL, Cloudflare Tunnel or a
reverse proxy.

## 7. Widget example
```html
<script
  src="https://ai.example.com/assets/chat-widget.js"
  data-api="https://ai.example.com"
  data-title="AI Assistant"
  data-greeting="Hello. How can I help you today?"
  data-position="right"
  data-language="en-US"
  data-voice="true">
</script>
```

Allowed origins must be exact origins such as:
```text
https://example.com
https://www.example.com
```

## 8. Acceptance tests
- Admin login works.
- Public `/chat` is 200 when enabled.
- Public Chat OFF produces the disabled state.
- DT-RAG health is healthy.
- Known KB question is grounded.
- Unknown question does not invent data.
- Allowed widget origin succeeds.
- Unauthorized origin returns 403 `origin_not_allowed`.
- Widget OFF returns `widget_disabled`.
- Widget Voice OFF hides voice controls in the generated snippet and rejects
  forged voice-mode requests server-side.
