#!/bin/sh
set -e

# Ensure application/cron log storage is writable by www-data before cron starts.
mkdir -p /var/www/html/storage/logs

touch     /var/www/html/storage/logs/worker.log     /var/www/html/storage/logs/provider-sync.log

chown -R www-data:www-data /var/www/html/storage/logs
chmod 0775 /var/www/html/storage/logs

# Run pending migrations on container start (idempotent — safe to re-run).
if [ "${RUN_MIGRATIONS_ON_BOOT:-true}" = "true" ]; then
    php /var/www/html/scripts/migrate.php || echo "Migration step failed — check DB connectivity."
fi

# Apache in the foreground keeps the container alive and lets Docker manage it.
exec apache2-foreground
