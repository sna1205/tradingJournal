# Railway Deployment

Services:
- `API` from monorepo root using `infra/railway/api.json`
- `WEB` from monorepo root using `infra/railway/web.json` (optional if Vercel hosts web)
- `MySQL`

## Critical Rules
1. Build context is monorepo root.
2. API health check is `/api/health`.
3. API service must mount a volume at `/var/www/html/storage`.

## Config-as-Code Paths
- API: `/infra/railway/api.json`
- WEB: `/infra/railway/web.json`

## Build Inputs
- API Dockerfile: `infra/docker/images/api.Dockerfile`
- WEB Dockerfile: `infra/docker/images/web.Dockerfile`

## Quick Verify
```bash
curl -f https://<api-domain>/api/health
curl -f https://<web-domain>/healthz
```
