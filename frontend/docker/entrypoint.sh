#!/bin/sh
set -eu

: "${PORT:=80}"

IS_RAILWAY=0
if env | grep -q '^RAILWAY_'; then
  IS_RAILWAY=1
  DEFAULT_API_UPSTREAM_URL=""
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

# Nginx config appends "/api/" for API routes, so upstream should be backend origin.
# Normalize common misconfiguration values like "https://backend.../api".
if printf '%s' "$API_UPSTREAM_URL" | grep -Eq '/api$'; then
  echo "WARN: API_UPSTREAM_URL should not include '/api'; normalizing '$API_UPSTREAM_URL' to backend origin." >&2
  API_UPSTREAM_URL="${API_UPSTREAM_URL%/api}"
fi

if ! printf '%s' "$API_UPSTREAM_URL" | grep -Eq '^https?://[^[:space:]]+$'; then
  echo "WARN: API_UPSTREAM_URL is invalid ('$API_UPSTREAM_URL'); using $DEFAULT_API_UPSTREAM_URL" >&2
  API_UPSTREAM_URL="$DEFAULT_API_UPSTREAM_URL"
fi

if [ "$IS_RAILWAY" -eq 1 ] && [ -z "$API_UPSTREAM_URL" ]; then
  echo "ERROR: API_UPSTREAM_URL is required on Railway and must point to backend origin (no trailing /api)." >&2
  exit 1
fi

export PORT API_UPSTREAM_URL

envsubst '${PORT} ${API_UPSTREAM_URL}' \
  < /etc/nginx/templates/default.conf.template \
  > /etc/nginx/conf.d/default.conf

exec nginx -g 'daemon off;'
