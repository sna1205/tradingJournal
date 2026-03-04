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
