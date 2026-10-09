#!/bin/sh
#
# Container entrypoint for Koyeb / docker-compose.
# Runs idempotent first-boot preparation, then hands PID 1 to supervisor.
#
set -e

APP_DIR=/var/www/html
cd "$APP_DIR"

log() {
    echo "[entrypoint] $*"
}

# ---------------------------------------------------------------------------
# 1. Writable runtime directories (a mounted volume may be empty on first boot)
# ---------------------------------------------------------------------------
mkdir -p \
    storage/app/google \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------------
# 2. Application key
#    Prefer APP_KEY from the platform secret store. If absent, generate one so
#    the container still boots (sessions/encryption will reset on redeploy).
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    log "APP_KEY is not set; generating an ephemeral key for this instance."
    php artisan key:generate --force --no-interaction || true
fi

# ---------------------------------------------------------------------------
# 3. Config / route caching for production performance.
#    Cached config bakes in env values, so only do this once env is final.
# ---------------------------------------------------------------------------
if [ "${APP_ENV:-production}" = "production" ]; then
    log "Caching configuration and routes."
    php artisan config:cache --no-interaction || true
    php artisan route:cache --no-interaction || true
    php artisan view:cache --no-interaction || true
fi

# ---------------------------------------------------------------------------
# 4. Database migrations
#    Retries because managed Postgres (Neon/Supabase) may still be waking up.
# ---------------------------------------------------------------------------
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    log "Running migrations."
    attempt=1
    max_attempts="${MIGRATION_MAX_ATTEMPTS:-5}"
    until php artisan migrate --force --no-interaction; do
        if [ "$attempt" -ge "$max_attempts" ]; then
            log "Migrations failed after ${attempt} attempts; starting server anyway."
            break
        fi
        log "Migration attempt ${attempt} failed; retrying in 5s."
        attempt=$((attempt + 1))
        sleep 5
    done
fi

# ---------------------------------------------------------------------------
# 5. Hand off to supervisor (nginx + php-fpm + queue worker)
# ---------------------------------------------------------------------------
log "Starting supervisor."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
