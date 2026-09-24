# AI Knowledge Base & Voice Assistant — Phase 1 (Foundation)

This is Phase 1 of the multi-domain AI Knowledge Base project: authentication,
RBAC, database migrations, the background job queue/worker, Docker Compose,
and the base admin dashboard shell. Knowledge bases, documents, RAG, AI
providers, website import, and voice are **not yet implemented** — they land
in Phases 2–7 per the architecture plan.

## What's included

- PHP 8.2+ skeleton with a manual PSR-4 autoloader (works without `composer install`;
  `composer.json` is ready for when later phases add real dependencies)
- MySQL/MariaDB schema + migration runner (`scripts/migrate.php`)
- Authentication: login/logout, password hashing (bcrypt via `password_hash`),
  session management, CSRF protection, login rate limiting/lockout, password
  reset token flow (email delivery to be wired in a later phase)
- RBAC: `Administrator` / `Editor` / `Viewer` roles, permission-based middleware
- Background job queue + cron-driven worker (`scripts/worker.php`) with
  retry/exponential-backoff — see `docs/cron.md` for the exact crontab entry
- Docker Compose: PHP+Apache app container (with cron installed for the worker),
  MySQL 8, optional Redis (behind the `with-redis` profile — the app runs fine without it)
- Bootstrap 5 admin shell with the full navigation structure from the spec
  (unbuilt sections are shown disabled, labeled "soon")

## Quick start (Docker)

```bash
cp .env.example .env
# Edit .env: set APP_KEY, SECRETS_ENCRYPTION_KEY, DB_PASSWORD, DB_ROOT_PASSWORD
php -r "echo bin2hex(random_bytes(32));"                          # -> APP_KEY
php -r "echo sodium_bin2hex(sodium_crypto_secretbox_keygen());"   # -> SECRETS_ENCRYPTION_KEY

docker compose -f docker/docker-compose.yml up -d --build
# Migrations run automatically on container start (RUN_MIGRATIONS_ON_BOOT=true).

# Seed roles/permissions and an administrator account:
docker compose -f docker/docker-compose.yml exec app php database/seeders/seed.php
# (Set SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD env vars beforehand, or a random
#  password is generated and printed once — copy it immediately.)
```

Visit `http://localhost:8080/login`.

## Quick start (manual, no Docker)

Requires PHP 8.2+ with `pdo_mysql`, `sodium`, `mbstring`, `curl` extensions,
and a MySQL 8 / MariaDB 10.6+ server.

```bash
cp .env.example .env
# set DB_HOST=127.0.0.1 (or your DB host) and generate APP_KEY / SECRETS_ENCRYPTION_KEY as above

mysql -u root -e "CREATE DATABASE ai_kb CHARACTER SET utf8mb4;
                   CREATE USER 'ai_kb_app'@'localhost' IDENTIFIED BY 'change_me';
                   GRANT ALL PRIVILEGES ON ai_kb.* TO 'ai_kb_app'@'localhost';"

php scripts/migrate.php
SEED_ADMIN_EMAIL=admin@example.com SEED_ADMIN_PASSWORD='ChangeMe123!' php database/seeders/seed.php

php -S 127.0.0.1:8080 -t public public/index.php
```

Then set up the cron entry from `docs/cron.md` for `scripts/worker.php`.

## Verified working (manual integration test)

- Migrations apply cleanly and are idempotent (safe to re-run)
- Seeder creates roles/permissions and an administrator account
- Login rejects wrong passwords, accepts correct ones, sets a session
- Unauthenticated requests to `/admin` redirect to `/login`
- RBAC: a `Viewer` gets a 403 on `/admin/users`; an `Administrator` does not
- CSRF: a forged token on `POST /login` is rejected
- Background job queue: jobs are claimed, retried with backoff on failure,
  and marked permanently `failed` after `max_attempts`

## Next phases

See `architecture-plan.md` for the full phased roadmap (Phase 2: multi-KB +
documents + RAG; Phase 4: Dynamic Technologies website import; Phase 5:
Agriculture KB; Phase 6: voice; Phase 7: embeddable widget; Phase 8: hardening).
