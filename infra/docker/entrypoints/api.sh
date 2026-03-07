#!/bin/sh
set -eu

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

# Laravel console bootstrap requires a valid absolute app URL.
# Fallback if Railway variable interpolation leaves APP_URL malformed.
APP_URL="${APP_URL:-http://localhost}"
case "$APP_URL" in
  http://*|https://*)
    ;;
  *)
    echo "WARN: APP_URL is invalid ('${APP_URL}'); falling back to http://localhost" >&2
    APP_URL="http://localhost"
    ;;
esac
export APP_URL

# Railway volume mounts can start empty; ensure Laravel writable paths exist.
mkdir -p \
  storage/app/public \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache >/dev/null 2>&1 || true

# Remove stale local caches that may reference dev-only providers.
rm -f bootstrap/cache/*.php

php artisan optimize:clear >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  (
    MIGRATION_TIMEOUT_SECONDS="${MIGRATION_TIMEOUT_SECONDS:-45}"
    if command -v timeout >/dev/null 2>&1; then
      if ! timeout "$MIGRATION_TIMEOUT_SECONDS" php artisan migrate --force; then
        echo "WARN: migrations failed or timed out." >&2
      fi
    else
      if ! php artisan migrate --force; then
        echo "WARN: migrations failed." >&2
      fi
    fi
  ) &
fi

php artisan storage:link >/dev/null 2>&1 || true
if ! php artisan config:cache; then
  echo "WARN: config:cache failed; continuing without cached config." >&2
  php artisan config:clear >/dev/null 2>&1 || true
fi

APP_PORT="${PORT:-8000}"
exec php artisan serve --host=0.0.0.0 --port="$APP_PORT"
