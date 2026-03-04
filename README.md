# Trading Journal Monorepo

Full-stack trading journal with:
- Web: Vue 3 + TypeScript + Pinia + Vite
- API: Laravel 11
- Deployment: Vercel (`apps/web`) + Railway (`apps/api`)

## Repository Layout
- `apps/web` frontend app + Vercel serverless proxy (`apps/web/api`)
- `apps/api` Laravel API app
- `infra/docker` compose files, Dockerfiles, nginx, entrypoints
- `infra/railway` Railway config-as-code
- `infra/env/.env.prod.example` prod compose env template
- `scripts/ci` verification and repo guard scripts
- `scripts/security` security tooling
- `docs` architecture/deployment/runbooks

## Local Development

### API
```bash
cd apps/api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

API health:
- `GET /api/health` (primary)
- `GET /up` (secondary)

### Web
```bash
cd apps/web
cp .env.example .env
npm install
npm run dev
```

## Docker (Local)
```bash
docker compose -f infra/docker/compose/dev.yml up -d --build
```

## Docker (Production-like)
```bash
cp infra/env/.env.prod.example .env
docker compose --env-file .env -f infra/docker/compose/prod.yml up -d --build
```

## Deploy
- Vercel:
  - Root Directory: `apps/web`
  - Config: `apps/web/vercel.json`
- Railway:
  - Config as code: `infra/railway/api.json` and `infra/railway/web.json`

Detailed runbooks:
- `docs/deployment/vercel-railway.md`
- `docs/deployment/railway.md`

## Verification
```bash
bash scripts/ci/verify.sh
bash scripts/ci/check-root-clutter.sh
```
