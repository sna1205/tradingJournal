# Railway Deployment

This project deploys cleanly to Railway as 3 services:
- `backend` (Laravel API via Dockerfile)
- `frontend` (Vite static build + Nginx proxy via Dockerfile)
- `MySQL` (Railway database service)

## 1) Create services from this repo

1. Create a new Railway project.
2. Add a service from GitHub for `backend` and set:
   - Root Directory: `/backend`
   - Config as Code path: `/backend/railway.json`
3. Add a second service from the same repo for `frontend` and set:
   - Root Directory: `/frontend`
   - Config as Code path: `/frontend/railway.json`
4. Add a `MySQL` service from the Railway template list.

## 2) Configure backend variables

Set these variables on the `backend` service:

```env
APP_NAME=Trading Journal API
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...generated-with-php-artisan-key-generate-show
APP_URL=https://izledger.up.railway.app

LOG_CHANNEL=stack
LOG_LEVEL=warning
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

RUN_MIGRATIONS=false
FILESYSTEM_DISK=public
TRADE_IMAGES_DISK=public
```

Generate `APP_KEY` locally with:

```bash
cd backend
php artisan key:generate --show
```

Attach a Railway volume to `backend` at `/var/www/html/storage` (required by backend `railway.json`).

## 3) Configure frontend variables

Set this on the `frontend` service:

```env
API_UPSTREAM_URL=http://${{backend.RAILWAY_PRIVATE_DOMAIN}}
```

This keeps browser requests same-origin (`/api`, `/storage`) while Nginx proxies privately to the backend service.
If your Railway service names differ, update the `${{service.VAR}}` references to match your actual names.

## 4) Networking

1. Enable a public domain for `frontend`.
2. Optionally enable a public domain for `backend` (useful for direct `/up` checks), but it is not required for app traffic.

## 5) Deploy and verify

After deploy, validate:

- Frontend homepage loads from the frontend public domain.
- `GET /healthz` on frontend returns `ok`.
- `GET /api/health` through frontend returns JSON.
- Image upload/read paths under `/storage/...` work.

If `RUN_MIGRATIONS=false`, run migrations once from backend service shell:

```bash
php artisan migrate --force
```

## 6) Backend Failure Checklist

If backend deployment fails, verify these first:

1. Service root directory is exactly `/backend`.
2. Dockerfile is used (no custom start command overriding Docker `ENTRYPOINT`).
3. Backend port is `8000` when Railway prompts for "port your app is listening on".
4. A backend volume is attached at `/var/www/html/storage`.
5. `APP_KEY` is set and starts with `base64:`.
6. `APP_URL` is a real absolute URL (example: `https://your-frontend-domain.com`), not an unresolved `${{...}}` template.
7. DB variables point to the Railway MySQL service:
   - `DB_HOST=${{MySQL.MYSQLHOST}}`
   - `DB_PORT=${{MySQL.MYSQLPORT}}`
   - `DB_DATABASE=${{MySQL.MYSQLDATABASE}}`
   - `DB_USERNAME=${{MySQL.MYSQLUSER}}`
   - `DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}`
8. Railway service references are case-sensitive (for example `Backend` vs `backend`).
9. Keep `RUN_MIGRATIONS=false` for first successful boot. Run migrations manually from Railway shell, then enable it only if you want auto-migrate on each deploy.
