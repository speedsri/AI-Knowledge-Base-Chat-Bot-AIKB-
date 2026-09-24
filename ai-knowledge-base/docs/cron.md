# Background Worker — Cron Setup

`scripts/worker.php` processes the `background_jobs` queue (document indexing,
embedding generation, and website crawling in later phases). It is designed
to run to completion quickly and exit — cron re-invokes it every minute
rather than it running as a long-lived daemon. A lock file prevents overlap
if one invocation is still finishing when the next one fires.

## Native host (cron installed directly on the server)

Edit the crontab for the user that owns the application files (commonly
`www-data`, or a dedicated `aikb` service user):

```bash
crontab -u www-data -e
```

Add this exact line:

```cron
* * * * * /usr/bin/php /var/www/scripts/worker.php >> /var/www/storage/logs/worker.log 2>&1
```

Field-by-field:

- `* * * * *` — every minute, every hour, every day, every month, every weekday.
- `/usr/bin/php` — absolute path to the PHP CLI binary (confirm with `which php`).
- `/var/www/scripts/worker.php` — absolute path to the worker script; adjust
  to match your actual deployment path.
- `>> /var/www/storage/logs/worker.log 2>&1` — appends stdout and stderr to a
  log file so failures are visible without relying on cron's mail delivery
  (which is often unconfigured on minimal servers).

Verify it's registered:

```bash
crontab -u www-data -l
```

## Inside the Docker Compose `app` container

If the app runs in Docker (see `docker/docker-compose.yml`), cron must run
*inside* that container, since cron on the host has no access to the
container's PHP runtime or filesystem. The Dockerfile installs `cron` and
copies a crontab file into `/etc/cron.d/` on build — see `docker/Dockerfile`
and `docker/crontab` for the concrete implementation. The container's
entrypoint starts `cron` in the foreground alongside the web server process.

The installed crontab entry inside the container is the same one shown
above, adjusted to the in-container path:

```cron
* * * * * www-data /usr/local/bin/php /var/www/html/scripts/worker.php >> /var/www/html/storage/logs/worker.log 2>&1
```

(Files under `/etc/cron.d/` require a specified user field — `www-data` —
which a plain user crontab does not.)

## Sanity check

After deploying, confirm jobs are actually being picked up:

```bash
tail -f storage/logs/worker.log
```

With no jobs queued yet (true until Phase 2 lands document/embedding jobs),
each run should print `No pending jobs.` once per minute and exit cleanly.
