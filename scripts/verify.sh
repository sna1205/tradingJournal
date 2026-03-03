#!/usr/bin/env bash
set -euo pipefail

# 1) Backend schema + seed integrity
cd backend
php artisan migrate:fresh --seed

# 2) Backend full test suite
php artisan test

# 3) Backend smoke subset for critical flows
php artisan test --filter='ApiAuthOwnershipTest|AccountArchitectureTest|TradeValidationTest|MissedTradeImageQuotaTest'

# 4) Route reachability sanity
php artisan route:list --path=api > /tmp/api-routes.txt
rg -n "api/auth/login|api/trades|api/analytics/dashboard-summary|api/trades/\{trade\}/images" /tmp/api-routes.txt

# 5) Frontend build + tests
cd ../frontend
if ! npm ci; then
  echo "npm ci failed (likely local file lock); falling back to npm install"
  npm install
fi
npm run build

# CI-default test command
npm run test

# Existing unit fallback
npm run test:unit

# 6) Optional targeted vitest checks already present in repo
npx vitest run src/stores/tradeStore.idempotency.test.ts src/stores/tradeStore.ifMatch.test.ts src/services/editLockService.test.ts

# 7) Dead-code proof check (after deletions)
rg -n "InsightPanel|MonthlyHeatmap|BreakdownBarChart|DailyPnlBarChart|EquityCurveChart|EquityCurveLineChart|RadarPerformanceChart|PerformanceSnapshot|SummaryCards|StatCard|OptionalSection|RuleProgressHeader" src || true
