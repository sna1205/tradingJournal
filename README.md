# Trading Journal + Analytics

Full-stack trading journal and analytics dashboard.

## Stack
- Frontend: Vue 3 + TypeScript + Pinia + TailwindCSS + ECharts
- Backend: Laravel 11 REST API
- Database: MySQL 8 (Docker) / SQLite (local option)

## Project Structure
- `backend/` Laravel API
- `frontend/` Vue app
- `docker-compose.yml` MySQL + phpMyAdmin

## 1) Start MySQL
```bash
docker compose up -d
```

MySQL:
- Host: `127.0.0.1`
- Port: `3307`
- Database: `trading_journal`
- Username: `trading`
- Password: `veasna123`

## 2) Backend Setup
```bash
cd backend
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

API base URL:
- `http://localhost:8000/api`

Main endpoints:
- `GET /api/accounts`
- `POST /api/accounts`
- `GET /api/accounts/{id}`
- `PUT /api/accounts/{id}`
- `DELETE /api/accounts/{id}`
- `GET /api/accounts/{id}/equity`
- `GET /api/trades`
- `POST /api/trades`
- `GET /api/trades/{id}`
- `PUT /api/trades/{id}`
- `DELETE /api/trades/{id}`
- `GET /api/missed-trades`
- `POST /api/missed-trades`
- `GET /api/missed-trades/{id}`
- `PUT /api/missed-trades/{id}`
- `DELETE /api/missed-trades/{id}`
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

## 3) Frontend Setup
```bash
cd frontend
copy .env.example .env
npm install
npm run dev
```

Frontend URL:
- `http://localhost:5173`

## Notes
- No authentication is included by design.
- Vite proxies `/api` using `VITE_PROXY_TARGET` (see `frontend/.env.example`, default `http://localhost:8000`).
- Frontend API prefix is controlled by `VITE_API_BASE_URL` (default `/api`).
- Backend analytics starting balance is configurable via `ANALYTICS_STARTING_BALANCE` (see `backend/.env.example`).
- Trade creation now requires `account_id` and computes `account_balance_before_trade` from account `current_balance`.
- If local PHP is missing extensions, enable at least `mbstring`, `curl`, and `pdo_mysql`.
- If `mbstring` is not enabled in your PHP CLI/server, run backend dev server with mbstring loaded:
```bash
cd backend/public
php -d extension=mbstring -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```
