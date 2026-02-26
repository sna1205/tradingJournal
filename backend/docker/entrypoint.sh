#!/bin/sh
set -eu

if [ ! -f .env ] && [ -f .env.production.example ]; then
  cp .env.production.example .env
fi

php artisan optimize:clear >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force
fi

php artisan storage:link >/dev/null 2>&1 || true
php artisan config:cache

APP_PORT="${PORT:-8000}"
exec php artisan serve --host=0.0.0.0 --port="$APP_PORT"
