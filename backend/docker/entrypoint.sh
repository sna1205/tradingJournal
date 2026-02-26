#!/bin/sh
set -eu

if [ ! -f .env ] && [ -f .env.production.example ]; then
  cp .env.production.example .env
fi

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
