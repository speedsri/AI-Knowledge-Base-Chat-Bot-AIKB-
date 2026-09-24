# AI Knowledge Base - Fresh Installation

This package is intended for a clean server deployment.

## Recommended platform

- Ubuntu 22.04/24.04 or comparable Linux server
- Docker Engine
- Docker Compose plugin
- DNS/HTTPS reverse proxy or Cloudflare Tunnel for production
- Outbound HTTPS access for configured AI providers

## 1. Extract source

```bash
unzip ai-knowledge-base-fresh-install-*.zip
cd ai-knowledge-base-fresh-install-*
```

## 2. Create environment file

```bash
cp .env.example .env
nano .env
```

Never commit `.env` to GitHub.

Configure the database passwords, application secret/encryption values,
RAG/internal authentication values, and AI provider credentials required
by your deployment.

`CHAT_WIDGET_ENABLED` remains the emergency server-level widget master
switch. Normal widget activation, allowed origins, URLs, rate limits and
voice availability are managed from Admin -> System Settings after setup.

## 3. Build and start AIKB

```bash
docker compose -f docker/docker-compose.yml build app
docker compose -f docker/docker-compose.yml up -d
```

If installing on a server that already hosts unrelated containers, do
not use broad Docker cleanup commands.

## 4. Run database migrations

```bash
docker compose -f docker/docker-compose.yml \
  run --rm --no-deps app \
  php scripts/migrate.php
```

If the container entrypoint already runs migrations automatically, a
second migration run should report that nothing remains to migrate.

## 5. Verify containers

```bash
docker compose -f docker/docker-compose.yml ps
```

Confirm the application and MySQL containers are healthy/running.

## 6. Open the application

Typical routes:

- `/login` - administrator login
- `/admin` - administration dashboard
- `/admin/system-settings` - public URL/chat/widget controls
- `/chat` - public browser chat
- `/assets/chat-widget.js` - embeddable widget asset
- `/widget/message` - widget message endpoint

## 7. Configure Admin -> System Settings

Set:

- Public Base URL
- Public Chat ON/OFF
- Embedded Widget ON/OFF
- Allowed Widget Origins
- Widget title
- Greeting
- Position
- Language
- Widget Voice ON/OFF
- Widget rate-limit values

The Public Base URL field does not create DNS, SSL, Cloudflare or reverse
proxy configuration. Configure those separately on the server/network.

## 8. Widget example

Generate the final snippet from Admin -> System Settings.

Example:

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

Use exact allowed origins such as:

```text
https://example.com
https://www.example.com
```

Do not include page paths.

## 9. Security checks

- Use HTTPS.
- Keep `.env` private.
- Never put Gemini/API keys in browser JavaScript.
- Keep database and internal RAG services private where possible.
- Test an allowed widget origin and a rejected origin.
- Change the initial admin password.
- Back up MySQL and vector data before production upgrades.
