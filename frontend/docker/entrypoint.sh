#!/bin/sh
set -eu

: "${PORT:=80}"

if [ -z "${API_UPSTREAM_URL:-}" ]; then
  if env | grep -q '^RAILWAY_'; then
    API_UPSTREAM_URL="http://127.0.0.1:8000"
  else
    API_UPSTREAM_URL="http://api:8000"
  fi
fi

API_UPSTREAM_URL="${API_UPSTREAM_URL%/}"
export PORT API_UPSTREAM_URL

envsubst '${PORT} ${API_UPSTREAM_URL}' \
  < /etc/nginx/templates/default.conf.template \
  > /etc/nginx/conf.d/default.conf

exec nginx -g 'daemon off;'
