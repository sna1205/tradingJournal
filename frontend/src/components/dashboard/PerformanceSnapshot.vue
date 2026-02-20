<script setup lang="ts">
import type { SummaryStats } from '@/types/trade'
import { asCurrency, asSignedCurrency } from '@/utils/format'

defineProps<{
  summary: SummaryStats | null
  bestSymbol: { symbol: string; pnl: number } | null
}>()
</script>

<template>
  <div>
    <h2 class="text-lg font-bold">Performance Snapshot</h2>
    <p class="mt-2 text-sm text-slate-300">
      Closed trades: <span class="font-semibold">{{ summary?.closed_trades ?? 0 }}</span>
    </p>
    <p class="mt-1 text-sm text-slate-300">
      Best symbol:
      <span class="font-semibold">{{ bestSymbol?.symbol ?? '-' }}</span>
      <span class="ml-2 text-xs text-cyan-300">{{ bestSymbol ? asSignedCurrency(bestSymbol.pnl) : '' }}</span>
    </p>
    <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
      <div class="rounded-lg border border-emerald-300/20 bg-emerald-300/10 p-3">
        <p class="text-slate-300">Gross Profit</p>
        <p class="mt-1 font-semibold text-emerald-300">{{ asCurrency(summary?.gross_profit) }}</p>
      </div>
      <div class="rounded-lg border border-rose-300/20 bg-rose-300/10 p-3">
        <p class="text-slate-300">Gross Loss</p>
        <p class="mt-1 font-semibold text-rose-300">{{ asCurrency(summary?.gross_loss) }}</p>
      </div>
      <div class="rounded-lg border border-slate-700/80 bg-slate-900/50 p-3">
        <p class="text-slate-300">Avg Win</p>
        <p class="mt-1 font-semibold">{{ asCurrency(summary?.avg_win) }}</p>
      </div>
      <div class="rounded-lg border border-slate-700/80 bg-slate-900/50 p-3">
        <p class="text-slate-300">Avg Loss</p>
        <p class="mt-1 font-semibold">{{ asCurrency(summary?.avg_loss) }}</p>
      </div>
    </div>
  </div>
</template>
