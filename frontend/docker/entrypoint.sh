#!/bin/sh
set -eu

: "${PORT:=80}"
: "${API_UPSTREAM_URL:=http://api:8000}"

API_UPSTREAM_URL="${API_UPSTREAM_URL%/}"
export PORT API_UPSTREAM_URL

envsubst '${PORT} ${API_UPSTREAM_URL}' \
  < /etc/nginx/templates/default.conf.template \
  > /etc/nginx/conf.d/default.conf

exec nginx -g 'daemon off;'
