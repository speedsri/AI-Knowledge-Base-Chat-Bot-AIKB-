# Rollback - Phase B

Rollback affects ONLY the dt-rag project. It never affects aikb_app,
aikb_mysql, docker-mattermost-1, docker-n8n-1, docker-db-1, docker-minio-1,
dt-admin4, or WhatsApp -- these are entirely separate systems that Phase B
never wrote to, joined a network with, or restarted.

## Standard rollback (keep data, just stop services)

```bash
cd /home/docker/dt-rag
docker compose -p dt-rag -f docker-compose.yml stop
```
Containers stop; the Qdrant volume (dt_rag_qdrant_data) is untouched.
Restart later with:
```bash
docker compose -p dt-rag -f docker-compose.yml --env-file .env up -d rag-qdrant rag-api
```

## Full rollback (remove containers, keep data)

```bash
docker compose -p dt-rag -f docker-compose.yml down
```
The -p dt-rag flag scopes this command entirely to this project -- it
cannot affect any container outside it, regardless of naming. The
dt_rag_qdrant_data volume survives this command (down without -v never
removes named volumes).

## Full rollback including data destruction

Only if you are certain no indexed content needs recovery (Qdrant is fully
rebuildable from MySQL document_versions via /v1/reindex replay -- see
ARCHITECTURE_PHASE_B.md -- so this is rarely actually destructive in the
sense of losing the source of truth):
```bash
docker compose -p dt-rag -f docker-compose.yml down -v
```

## Complete removal

```bash
docker compose -p dt-rag -f docker-compose.yml down -v
cd ..
rm -rf dt-rag
```

## What rollback never requires

- No restart of aikb_app, aikb_mysql, or any other existing container.
- No firewall changes beyond removing whatever rule was added for port 8500
  during a later hardening follow-up (Phase B as shipped adds none -- see
  INSTALL_PHASE_B.md's firewall note).
- No changes to Mattermost, n8n, or MinIO configuration -- Phase B never
  touched them.

## Verification after rollback

```bash
docker ps --format "table {{.Names}}\t{{.Status}}" | grep -E "aikb_app|aikb_mysql|mattermost|n8n|docker-db-1|minio"
```
Confirm all six protected containers show the same status they had before
Phase B was installed.

## Partial rollback

If only the crawler/ingestion foundation is a concern (e.g. an accidental
crawl target), no rollback of the whole project is needed -- simply do not
invoke docker compose -p dt-rag run rag-ingestion again, and/or remove any
indexed content for a specific knowledge_base_id via Qdrant's filter-delete
API using the knowledge_base_id payload field, without touching rag-api or
rag-qdrant's running state at all.
