# Deployment Guide (Start to Finish)

This guide walks through a full production deployment from an empty cloud setup to a verified live app.

Target architecture:
- Frontend: Vercel (`https://app.yourdomain.com`)
- API: Railway Laravel service (`https://api.yourdomain.com`)
- Database: Railway MySQL service

Default auth mode in this repo:
- Bearer token mode (`Authorization: Bearer <token>`)
- Cookie/Sanctum SPA mode is optional and documented at the end

## Step 0: What you need before starting

1. Accounts
- Vercel account
- Railway account
- Domain/DNS provider access

2. Local tools
```bash
node -v
npm -v
php -v
composer -V
git --version
```

3. Optional CLI tools
```bash
npm i -g vercel
npm i -g @railway/cli
```

4. Required repo files already present
- `vercel.json` (root)
- `backend/railway.json`
- `frontend/railway.json` (used only if you deploy frontend on Railway)

## Step 1: Decide your production URLs

Use these names consistently:
- Frontend URL: `https://app.yourdomain.com`
- API URL: `https://api.yourdomain.com`

You will use these exact values in DNS, Railway env vars, Vercel env vars, and CORS.

## Step 2: Create DNS records

In your DNS provider:

1. Create `app` subdomain and point it to Vercel (via Vercel domain instructions).
2. Create `api` subdomain and point it to Railway (CNAME to Railway provided target).

Do not continue until both records are created.

## Step 3: Prepare Railway project

1. Create a new Railway project.
2. Add `MySQL` service from Railway template.
3. Add `Backend` service from this repo:
- Root directory: `backend`
- Config as code: `backend/railway.json`
4. Attach a volume to `Backend` service:
- Mount path must be: `/var/www/html/storage`

Why this matters:
- `backend/railway.json` requires the mount path.
- Without this path, uploads and storage symlink behavior can fail.

## Step 4: Generate Laravel `APP_KEY`

From repo root:

```bash
cd backend
php artisan key:generate --show
```

Copy the full output (`base64:...`) and keep it ready for Railway env vars.

## Step 5: Set Railway backend environment variables

In Railway -> `Backend` service -> Variables, set:

```env
APP_NAME=Trading Journal API
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:REPLACE_WITH_REAL_KEY
APP_URL=https://api.yourdomain.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
RUN_MIGRATIONS=false

FILESYSTEM_DISK=public
TRADE_IMAGES_DISK=public

CORS_ALLOWED_ORIGINS=https://app.yourdomain.com,https://your-project.vercel.app
CORS_ALLOWED_ORIGIN_PATTERNS=^https://your-project-.*\.vercel\.app$
CORS_SUPPORTS_CREDENTIALS=false
SANCTUM_STATEFUL_API=false
```

Important rules:
- Do not wrap Railway env values in quotes.
- `APP_URL` must be a complete URL including `https://`.
- Service references are case-sensitive (`MySQL` must match service name exactly).

## Step 6: Deploy backend and run migrations

1. Trigger a Railway deploy for `Backend`.
2. Wait until health check is green.
3. Run migrations once:

Using Railway CLI:
```bash
railway login
railway link
railway service Backend
railway run php artisan migrate --force
```

Or run the migration command from Railway dashboard shell.

4. Clear stale config cache after env changes:

```bash
railway run php artisan optimize:clear
```

## Step 7: Verify backend before frontend

Run these checks:

```bash
curl https://api.yourdomain.com/up
curl https://api.yourdomain.com/api/health
```

Expected:
- `/up` returns healthy response
- `/api/health` returns JSON health payload

If these fail, fix backend before touching Vercel.

## Step 8: Create and configure Vercel project

1. Import this repository into Vercel.
2. Root-level `vercel.json` already defines:
- `installCommand`: `cd frontend && npm ci`
- `buildCommand`: `cd frontend && npm run build`
- `outputDirectory`: `frontend/dist`
3. In Vercel project -> Environment Variables, add:

```env
VITE_API_BASE_URL=https://api.yourdomain.com/api
VITE_API_WITH_CREDENTIALS=0
VITE_ENABLE_VISUAL_ROUTES=0
```

4. Deploy production in Vercel.

CLI option:
```bash
vercel
vercel --prod
```

## Step 9: Connect custom frontend domain

In Vercel project domains:
1. Add `app.yourdomain.com`.
2. Follow Vercel DNS instructions until domain status is valid.

Keep `https://your-project.vercel.app` accessible as backup during initial rollout.

## Step 10: Configure upload storage strategy

Choose one option.

### Option A (recommended): S3/R2 object storage

Set Railway backend vars:

```env
TRADE_IMAGES_DISK=s3
AWS_ACCESS_KEY_ID=REPLACE_ME
AWS_SECRET_ACCESS_KEY=REPLACE_ME
AWS_BUCKET=REPLACE_ME
AWS_DEFAULT_REGION=auto
AWS_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com
AWS_URL=https://<public-bucket-or-cdn-url>
AWS_USE_PATH_STYLE_ENDPOINT=false
```

Then redeploy backend and test image upload.

### Option B: Railway volume + `public` disk

Set:

```env
TRADE_IMAGES_DISK=public
FILESYSTEM_DISK=public
```

Confirm:
- Volume still mounted at `/var/www/html/storage`
- Storage symlink exists (entrypoint runs it automatically)

Manual check command:
```bash
railway run php artisan storage:link
```

## Step 11: End-to-end production verification checklist

1. API health checks:
```bash
curl https://api.yourdomain.com/up
curl https://api.yourdomain.com/api/health
```

2. CORS preflight:
```bash
curl -i -X OPTIONS https://api.yourdomain.com/api/health \
  -H "Origin: https://app.yourdomain.com" \
  -H "Access-Control-Request-Method: GET"
```

3. Frontend loads:
- Open `https://app.yourdomain.com`

4. Auth flow:
- Login succeeds
- Authenticated API calls succeed

5. Upload flow:
- Trade image upload works
- Missed-trade image upload works
- Returned URLs render correctly

6. Migration status:
```bash
railway run php artisan migrate:status
```

7. Final cache clear (safe after env changes):
```bash
railway run php artisan optimize:clear
```

## Step 12: Post-deploy rollback plan

If the latest release is broken:

1. Frontend rollback
- In Vercel, redeploy previous successful deployment.

2. Backend rollback
- In Railway, roll back to previous successful deployment.

3. Database caution
- Do not run destructive down-migrations in production unless you have tested rollback SQL and have backup.

## Step 13: Common issues and fixes

1. CORS blocked
- Add exact domain to `CORS_ALLOWED_ORIGINS`.
- Keep preview regex in `CORS_ALLOWED_ORIGIN_PATTERNS`.
- Run `railway run php artisan optimize:clear`.

2. `No application encryption key has been specified`
- Generate key with `php artisan key:generate --show`.
- Set `APP_KEY` in Railway and redeploy.

3. DB connection failures
- Recheck `DB_*` values and `MySQL` service reference names.
- Ensure Backend and MySQL are in same Railway project.

4. Upload or `/storage` issues (public disk)
- Confirm volume mount path exactly `/var/www/html/storage`.
- Run `railway run php artisan storage:link`.
- Prefer S3/R2 for long-term production reliability.

5. Frontend can load but API calls fail
- Verify `VITE_API_BASE_URL` is exactly `https://api.yourdomain.com/api`.
- Verify backend CORS allows `https://app.yourdomain.com`.

## Step 14: Optional cookie-based Sanctum mode

Only enable this if you intentionally want cookie auth instead of bearer tokens.

Backend vars:

```env
CORS_SUPPORTS_CREDENTIALS=true
SANCTUM_STATEFUL_DOMAINS=app.yourdomain.com,your-project.vercel.app,your-project-git-main-yourteam.vercel.app,localhost,localhost:5173,127.0.0.1,127.0.0.1:5173
SANCTUM_STATEFUL_API=true
SESSION_DOMAIN=.yourdomain.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

Frontend var:

```env
VITE_API_WITH_CREDENTIALS=1
```

Login flow must request CSRF cookie first:

```ts
await axios.get('https://api.yourdomain.com/sanctum/csrf-cookie', { withCredentials: true })
```

---

Deployment is complete when:
- `https://app.yourdomain.com` works
- `https://api.yourdomain.com/api/health` works
- Login, authenticated requests, and image uploads all pass
