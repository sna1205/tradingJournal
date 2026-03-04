#!/usr/bin/env bash
set -euo pipefail

# Optional tools check (rg may not exist in CI)
HAS_RG=0
command -v rg >/dev/null 2>&1 && HAS_RG=1

# 1) Backend schema + tests
cd backend
php artisan migrate:fresh --seed
php artisan test
php artisan test --filter='ApiAuthOwnershipTest|AccountArchitectureTest|TradeValidationTest|MissedTradeImageQuotaTest'

php artisan route:list --path=api > /tmp/api-routes.txt
if [ "$HAS_RG" -eq 1 ]; then
  rg -n "api/auth/login|api/trades|api/analytics/dashboard-summary|api/trades/\{trade\}/images" /tmp/api-routes.txt
fi

# 2) Frontend build + tests
cd ../frontend

# CI should NEVER fall back; local can.
if [ "${CI:-}" = "true" ]; then
  npm ci
else
  if ! npm ci; then
    echo "npm ci failed (likely local file lock); falling back to npm install"
    npm install
  fi
fi

npm run build
npm run test

# Optional targeted checks (don’t fail if missing)
if npx --yes vitest --version >/dev/null 2>&1; then
  if [ -f "src/stores/tradeStore.idempotency.test.ts" ]; then
    npx vitest run \
      src/stores/tradeStore.idempotency.test.ts \
      src/stores/tradeStore.ifMatch.test.ts \
      src/services/editLockService.test.ts
  fi
fi

# Dead-code proof check (after deletions)
if [ "$HAS_RG" -eq 1 ]; then
  rg -n "InsightPanel|MonthlyHeatmap|BreakdownBarChart|DailyPnlBarChart|EquityCurveChart|EquityCurveLineChart|RadarPerformanceChart|PerformanceSnapshot|SummaryCards|StatCard|OptionalSection|RuleProgressHeader" src || true
fi
