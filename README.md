# Trading Journal + Analytics

Full-stack trading journal and analytics dashboard.

## Stack
- Frontend: Vue 3 + TypeScript + Pinia + TailwindCSS + ECharts
- Backend: Laravel 11 REST API
- Database: MySQL 8

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
- Port: `3306`
- Database: `trading_journal`
- Username: `trading`
- Password: `trading123`

phpMyAdmin:
- URL: `http://localhost:8080`
- User: `root`
- Password: `root123`

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
- `GET /api/trades`
- `POST /api/trades`
- `PUT /api/trades/{id}`
- `DELETE /api/trades/{id}`
- `GET /api/analytics/summary`
- `GET /api/analytics/equity-curve`
- `GET /api/analytics/performance-by-symbol`
- `GET /api/analytics/performance-by-setup`
- `GET /api/analytics/pnl-by-weekday`

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
- Vite dev server proxies `/api` to `http://localhost:8000`.
- If local PHP is missing extensions, enable at least `mbstring`, `curl`, and `pdo_mysql`.
