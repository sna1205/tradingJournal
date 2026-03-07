# Vercel + Railway Deployment

Target:
- Web: Vercel (`apps/web`)
- API: Railway (`apps/api`)
- DB: Railway MySQL

## Required Repo Paths
- `apps/web/vercel.json`
- `infra/railway/api.json`
- `infra/railway/web.json`

## Vercel Setup
1. Import repo into Vercel.
2. Set **Root Directory** to `apps/web`.
3. Ensure env vars:
```env
VITE_API_BASE_URL=https://api.yourdomain.com/api
API_BASE_URL=https://api.yourdomain.com/api
```

`apps/web/api/[...path].js` and `apps/web/api/storage/[...path].js` handle `/api` and `/storage` proxying.

## Railway Setup
1. Create project with services: `API`, `WEB` (optional), and `MySQL`.
2. Use config-as-code:
- API: `infra/railway/api.json`
- WEB: `infra/railway/web.json`
3. Mount API volume at `/var/www/html/storage`.

## API Environment (Railway)
```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:REPLACE_ME
APP_URL=https://api.yourdomain.com
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
CORS_ALLOWED_ORIGINS=https://app.yourdomain.com,https://your-project.vercel.app
CORS_SUPPORTS_CREDENTIALS=true
```

## Verification
```bash
curl -f https://api.yourdomain.com/api/health
curl -f https://api.yourdomain.com/up
```
