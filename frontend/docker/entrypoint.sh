#!/bin/sh
set -eu

: "${PORT:=80}"

if env | grep -q '^RAILWAY_'; then
  DEFAULT_API_UPSTREAM_URL="http://127.0.0.1:8000"
else
  DEFAULT_API_UPSTREAM_URL="http://api:8000"
fi

API_UPSTREAM_URL="${API_UPSTREAM_URL:-$DEFAULT_API_UPSTREAM_URL}"
API_UPSTREAM_URL="$(printf '%s' "$API_UPSTREAM_URL" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"

case "$API_UPSTREAM_URL" in
  http://*|https://*)
    ;;
  *'${{'*|*'}}'*|*'$'*|*'{'*|*'}'*)
    echo "WARN: API_UPSTREAM_URL appears unresolved ('$API_UPSTREAM_URL'); using $DEFAULT_API_UPSTREAM_URL" >&2
    API_UPSTREAM_URL="$DEFAULT_API_UPSTREAM_URL"
    ;;
  *://*)
    echo "WARN: API_UPSTREAM_URL has unsupported scheme ('$API_UPSTREAM_URL'); using $DEFAULT_API_UPSTREAM_URL" >&2
    API_UPSTREAM_URL="$DEFAULT_API_UPSTREAM_URL"
    ;;
  *)
    API_UPSTREAM_URL="http://$API_UPSTREAM_URL"
    ;;
esac

API_UPSTREAM_URL="${API_UPSTREAM_URL%/}"

if ! printf '%s' "$API_UPSTREAM_URL" | grep -Eq '^https?://[^[:space:]]+$'; then
  echo "WARN: API_UPSTREAM_URL is invalid ('$API_UPSTREAM_URL'); using $DEFAULT_API_UPSTREAM_URL" >&2
  API_UPSTREAM_URL="$DEFAULT_API_UPSTREAM_URL"
fi

export PORT API_UPSTREAM_URL

envsubst '${PORT} ${API_UPSTREAM_URL}' \
  < /etc/nginx/templates/default.conf.template \
  > /etc/nginx/conf.d/default.conf

exec nginx -g 'daemon off;'
