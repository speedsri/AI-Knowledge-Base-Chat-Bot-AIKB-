# Installing Phase B

This creates a NEW, fully isolated Docker Compose project (dt-rag) on
192.168.1.220. It does not touch, restart, or recreate any existing
container. Read this entire document before running anything.

## What this does NOT do

- Does NOT run docker compose down against any existing project.
- Does NOT use --remove-orphans anywhere.
- Does NOT run docker system prune, docker volume prune, or
  docker network prune.
- Does NOT touch aikb_app, aikb_mysql, docker-mattermost-1,
  docker-n8n-1, docker-db-1, or docker-minio-1, their volumes, or their
  configuration, in any way.
- Does NOT modify any existing Compose project's identity or network.

## Prerequisites (read-only checks first)

```bash
docker ps --format "table {{.Names}}\t{{.Status}}" | grep -E "aikb_app|aikb_mysql|mattermost|n8n|docker-db-1|minio"

ss -ltn | grep ':8500 ' && echo "WARNING: port already in use" || echo "OK: port free"

docker network ls | grep dt_rag_net && echo "NOTE: network already exists" || echo "OK: clean starting point"
```

## Step 1 - Create the project directory

```bash
sudo mkdir -p /home/docker/dt-rag
sudo chown $USER:$USER /home/docker/dt-rag
cd /home/docker/dt-rag
```
(Extract this delivery's contents into this directory.)

## Step 2 - Configure

```bash
cp .env.example .env
chmod 600 .env
nano .env
```
Fill in at minimum: RAG_INTERNAL_TOKEN (openssl rand -hex 32),
GEMINI_API_KEY. Leave the model names as shipped unless you have
re-verified newer ones are current (see ARCHITECTURE_PHASE_B.md).

Record RAG_INTERNAL_TOKEN somewhere safe -- AIKB and dt-admin4 (.215) will
both need this exact value in their own server-side configuration in a
later phase.

## Step 3 - Validate the Compose file (read-only, no side effects)

```bash
docker compose -p dt-rag -f docker-compose.yml --env-file .env config
```
Review the output. Fix .env or docker-compose.yml first if needed -- this
command starts nothing.

## Step 4 - Build images

```bash
docker compose -p dt-rag -f docker-compose.yml --env-file .env build
```

## Step 5 - Bring up Qdrant alone first

```bash
docker compose -p dt-rag -f docker-compose.yml --env-file .env up -d rag-qdrant
docker compose -p dt-rag -f docker-compose.yml logs rag-qdrant
```
Wait for the healthcheck:
```bash
docker inspect --format='{{.State.Health.Status}}' dt-rag-qdrant
```
Expect "healthy" within about 30-60 seconds.

## Step 6 - Bring up rag-api

```bash
docker compose -p dt-rag -f docker-compose.yml --env-file .env up -d rag-api
docker compose -p dt-rag -f docker-compose.yml logs -f rag-api
```
Expect a log line "rag-api ready" with the configured model names.

## Step 7 - Verify

```bash
curl -s http://localhost:8500/health | python3 -m json.tool
curl -s http://192.168.1.220:8500/health | python3 -m json.tool
```
Expect status ok, dependencies qdrant=ok, gemini=configured (or
not_configured if GEMINI_API_KEY was left blank -- the app still runs,
only /v1/chat and /v1/reindex will fail until it's set).

Then test the provider:
```bash
curl -s -X POST http://192.168.1.220:8500/v1/provider/test \
  -H "Authorization: Bearer <your RAG_INTERNAL_TOKEN>"
```

## Step 8 - Run the test suite (recommended before any real use)

See TEST_PHASE_B.md.

## Step 9 - (Optional, manual, foundation-only) Test the crawler

Do not point this at dynamictecsl.site or any other production site
without deliberate review -- this is a foundation capability, not a
scheduled or automatic feature.

```bash
docker compose -p dt-rag -f docker-compose.yml --env-file .env \
  run --rm rag-ingestion crawl --kb-id 1 --url https://example.com --max-pages 3 --dry-run
```
--dry-run fetches and prints pages without indexing anything into Qdrant --
use this first. Remove --dry-run only once you've reviewed the output and
are certain indexing is wanted.

## Firewall note (read before considering this "done")

rag-api currently binds to 192.168.1.220:8500 on the LAN-wide interface --
it is NOT yet firewalled to specific caller IPs, because the firewall
manager authoritative on .220 was never confirmed in earlier phases. Before
relying on this in any production capacity, identify the actual firewall
system in use:
```bash
systemctl is-active ufw 2>/dev/null
systemctl is-active firewalld 2>/dev/null
command -v nft >/dev/null && sudo nft list ruleset | head -50
```
Then write the appropriate rule to restrict inbound :8500 to only the
intended callers (.215 and AIKB's own network path). Do not run a ufw
command if firewalld or raw nftables is what's actually active.

## V2 Corrections to This Runbook

- **Step 3 (`docker compose config`)**: this specific validation was **not**
  executed against the real `docker compose` binary while preparing this
  delivery (no Docker daemon in that sandbox) — only YAML-parsed for
  structural validity. **Actually run this command yourself** before
  proceeding to Step 4; do not skip it on the assumption it was already
  confirmed.
- **Qdrant image tag**: pinned to `qdrant/qdrant:v1.19.0`. This exact patch
  tag's existence was inferred from related Docker Hub evidence, not
  directly confirmed against the full tag list. **Verify it exists**
  (`https://hub.docker.com/r/qdrant/qdrant/tags`) before pulling; if it
  doesn't, pin to the closest confirmed `v1.19.x` patch release instead.
- **No more Qdrant healthcheck wait**: Step 5 ("wait for the healthcheck to
  pass") is no longer applicable — `rag-qdrant` has no container-level
  healthcheck in V2 (see `CHANGELOG.md` item 7). Instead, proceed directly
  to bringing up `rag-api` (Step 6); its own startup retry loop handles
  waiting for Qdrant to become reachable, and its logs will show retry
  attempts if Qdrant isn't ready yet.
- **RAG_INTERNAL_TOKEN is now a hard requirement**: `docker compose up
  rag-api` will fail immediately (container exits non-zero) if this value
  in `.env` is missing, blank, or under 32 characters — this is intentional
  (see `SECURITY_PHASE_B.md`), not a bug to work around.
