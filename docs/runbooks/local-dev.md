# Local Development Runbook

## Prerequisites
- Node.js
- PHP + Composer

## Run API
```bash
cd apps/api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

If Vite runs on `5174` instead of `5173`, ensure API env includes both ports:
`CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173,http://localhost:5174,http://127.0.0.1:5174`
`SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:5174,127.0.0.1:5174,localhost,127.0.0.1`

## Run Web
```bash
cd apps/web
cp .env.example .env
npm install
npm run dev
```

## Verify
```bash
curl -f http://127.0.0.1:8000/api/health
```
