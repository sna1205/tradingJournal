# Railway Deployment

Deploy this repo as 3 Railway services:
- `Backend` (Laravel API, `/backend`)
- `Frontend` (Vite build + Nginx, `/frontend`)
- `MySQL` (Railway template database)

## Critical rules

1. Do not wrap Railway env values in quotes.
2. URL env values must be full URLs with scheme (`http://` or `https://`).
3. `${{Service.VAR}}` references are case-sensitive (`Backend` is different from `backend`).
4. Backend requires a mounted volume at `/var/www/html/storage`.

## 1) Create services

1. Create a Railway project.
2. Add service `Backend` from this repo:
   - Root Directory: `/backend`
   - Config as Code: `/backend/railway.json`
3. Add service `Frontend` from this repo:
   - Root Directory: `/frontend`
   - Config as Code: `/frontend/railway.json`
4. Add service `MySQL` from Railway templates.

## 2) Backend config

Set backend variables:

```env
APP_NAME=Trading Journal API
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:YOUR_REAL_KEY
APP_URL=https://<backend-domain>.up.railway.app

LOG_CHANNEL=stack
LOG_LEVEL=warning
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
RUN_MIGRATIONS=false

FILESYSTEM_DISK=public
TRADE_IMAGES_DISK=public

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
```

Attach a volume to backend:
- Service: `Backend`
- Mount path: `/var/www/html/storage`

Generate key locally:

```bash
cd backend
php artisan key:generate --show
```

## 3) Frontend config

Recommended stable option (avoid reference-resolution issues):

```env
API_UPSTREAM_URL=https://<backend-domain>.up.railway.app
```

`API_UPSTREAM_URL` is required on Railway and must be backend origin only (no `/api` suffix).

Alternative (private networking reference):

```env
API_UPSTREAM_URL=http://${{Backend.RAILWAY_PRIVATE_DOMAIN}}
```

## 4) Networking

1. Enable public domain for `Frontend`.
2. Enable public domain for `Backend` (recommended for direct checks).

## 5) Deploy order

1. Deploy `Backend`.
2. Deploy `Frontend`.

## 6) Verify

1. `https://<frontend-domain>/healthz` returns `ok`.
2. `https://<backend-domain>/up` returns healthy response.
3. `https://<frontend-domain>/api/health` returns backend JSON.

Run migrations once after backend is healthy:

```bash
php artisan migrate --force
```

## 7) Common failures

1. `Invalid URI: Scheme is malformed` (backend):
   - `APP_URL` is invalid or unresolved `${{...}}`.
2. `invalid URL prefix in /etc/nginx/conf.d/default.conf` (frontend):
   - `API_UPSTREAM_URL` is invalid or unresolved `${{...}}`.
3. Backend stuck unhealthy after adding volume:
   - volume path is wrong; must be `/var/www/html/storage`.
4. Auth/API calls return HTML or 404 from frontend:
   - `API_UPSTREAM_URL` incorrectly includes `/api`; set it to backend origin only.
