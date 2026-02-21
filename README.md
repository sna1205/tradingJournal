# Trading Journal + Analytics

Full-stack trading journal built with:
- Frontend: Vue 3 + TypeScript + Pinia + Tailwind + ECharts
- Backend: Laravel 11 REST API
- Database: MySQL 8

## Project Structure
- `frontend/` Vue app
- `backend/` Laravel API
- `docker-compose.yml` local development stack
- `docker-compose.prod.yml` production-ready container stack

## Local Development

### 1) Start infrastructure
```bash
docker compose up -d mysql
```

MySQL (dev):
- Host: `127.0.0.1`
- Port: `3307`
- Database: `trading_journal`
- Username: `trading`
- Password: `veasna123`

### 2) Backend
```bash
cd backend
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

API URL:
- `http://127.0.0.1:8000`
- `http://127.0.0.1:8000/api`

Health checks:
- `GET /up`
- `GET /api/health`

If your local PHP misses `mbstring` (Windows common), run:
```bash
cd backend/public
php -d extension=mbstring -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

### 3) Frontend
```bash
cd frontend
copy .env.example .env
npm install
npm run dev
```

Frontend URL:
- `http://127.0.0.1:5173`

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
docker compose -f docker-compose.prod.yml up -d --build
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
docker compose -f docker-compose.prod.yml up -d --build
```

---

## Notes
- No auth is implemented by design.
- Frontend expects API on `/api` in production.
- Production stack is optimized for single-host deployment.
