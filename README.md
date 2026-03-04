# Trading Journal + Analytics

Full-stack trading journal built with:
- Frontend: Vue 3 + TypeScript + Pinia + Tailwind + ECharts
- Backend: Laravel 11 REST API
- Database: SQLite (local), MySQL 8 (production)

## Project Structure
- `frontend/` Vue app
- `backend/` Laravel API
- `docker-compose.yml` local development stack
- `docker-compose.prod.yml` production-ready container stack

## Local Development

Local development uses one database only: SQLite.

### 1) Backend setup
```bash
cd backend
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

`backend/.env.example` is enforced to:
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=database/database.sqlite`
- `SESSION_DRIVER=file`

Seeded local login (after `migrate:fresh --seed`):
- Email: `demo@tradingjournal.local`
- Password: `password123`

API URL:
- `http://127.0.0.1:8000`
- `http://127.0.0.1:8000/api`

Health checks:
- `GET /up`
- `GET /api/health`

Main endpoints:
- `GET /api/accounts`
- `POST /api/accounts`
- `GET /api/accounts/{id}`
- `PUT /api/accounts/{id}`
- `DELETE /api/accounts/{id}`
- `GET /api/accounts/{id}/equity`
- `GET /api/accounts/{id}/analytics`
- `GET /api/trades`
- `POST /api/trades`
- `GET /api/trades/{id}`
- `PUT /api/trades/{id}`
- `DELETE /api/trades/{id}`
- `POST /api/trades/{id}/images`
- `DELETE /api/trade-images/{id}`
- `GET /api/missed-trades`
- `POST /api/missed-trades`
- `GET /api/missed-trades/{id}`
- `PUT /api/missed-trades/{id}`
- `DELETE /api/missed-trades/{id}`
- `POST /api/missed-trades/{id}/images`
- `DELETE /api/missed-trade-images/{id}`
- `GET /api/analytics/overview`
- `GET /api/analytics/daily`
- `GET /api/analytics/performance-profile`
- `GET /api/analytics/equity`
- `GET /api/analytics/drawdown`
- `GET /api/analytics/streaks`
- `GET /api/analytics/metrics`
- `GET /api/analytics/behavioral`
- `GET /api/analytics/rankings`
- `GET /api/analytics/monthly-heatmap`
- `GET /api/analytics/risk-status`
- `GET /api/analytics/accounts`
- `GET /api/analytics/portfolio`
- `GET /api/portfolio/analytics`

Query filters:
- `account_id` (single account scope)
- `account_ids` (comma-separated portfolio subset)

If your local PHP misses `mbstring` (Windows common), run:
```bash
cd backend/public
php -d extension=mbstring -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

### 2) Frontend
```bash
cd frontend
copy .env.example .env
npm install
npm run dev
```

Frontend URL:
- `http://127.0.0.1:5173`

If login returns `422`, the API is up and database is reachable; it means invalid credentials. Register again or use the correct local account.

---

## Production Deployment (Docker)

### 1) Prepare environment
```bash
copy .env.prod.example .env
```

Edit `.env`:
- set `APP_KEY` (generate with `php -d extension=mbstring backend\\artisan key:generate --show`)
- set `APP_URL`
- set secure DB passwords

Optional host env vars:
- `APP_KEY`
- `APP_URL`
- `DB_PASSWORD`
- `DB_ROOT_PASSWORD`
- `WEB_PORT` (default `80`)
- `RUN_MIGRATIONS` (`true` by default)

### 2) Build and run
```bash
docker compose --env-file .env -f docker-compose.prod.yml up -d --build
```

Services:
- `web` (Nginx static frontend + `/api` proxy) on `${WEB_PORT:-80}`
- `api` (Laravel app on internal port `8000`)
- `db` (MySQL 8.4)

### 3) Verify
```bash
curl http://localhost/up
curl http://localhost/api/health
```

### 4) Update deployment
```bash
git pull
docker compose --env-file .env -f docker-compose.prod.yml up -d --build
```

## Notes
- Authentication is cookie-based Sanctum SPA auth (session + CSRF). Bearer tokens are not used in the default flow.
- Vite proxies `/api` using `VITE_PROXY_TARGET` in dev.
- Frontend API prefix is controlled by `VITE_API_BASE_URL` (default `/api`).
- Vercel deployments require `API_BASE_URL` set to your backend API root (example: `https://api.your-domain.com/api`) so `/api/*` can be proxied by `api/[...path].js`.

## Railway Deployment
- Use the repo guide: `docs/railway-deployment.md`
- Deploy as 3 Railway services: `frontend`, `backend`, and `MySQL`
