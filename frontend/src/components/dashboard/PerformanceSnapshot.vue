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
    <p class="mt-2 text-sm muted">
      Closed trades: <span class="font-semibold">{{ summary?.closed_trades ?? 0 }}</span>
    </p>
    <p class="mt-1 text-sm muted">
      Best symbol:
      <span class="font-semibold">{{ bestSymbol?.symbol ?? '-' }}</span>
      <span
        class="ml-2 text-xs"
        :style="{ color: 'color-mix(in srgb, var(--chart-cyan) 82%, var(--text) 18%)' }"
      >
        {{ bestSymbol ? asSignedCurrency(bestSymbol.pnl) : '' }}
      </span>
    </p>
    <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
      <div
        class="rounded-lg border p-3"
        :style="{
          borderColor: 'color-mix(in srgb, var(--primary) 40%, var(--border) 60%)',
          background: 'color-mix(in srgb, var(--primary-soft) 76%, var(--panel) 24%)',
        }"
      >
        <p class="muted">Gross Profit</p>
        <p class="mt-1 font-semibold positive">{{ asCurrency(summary?.gross_profit) }}</p>
      </div>
      <div
        class="rounded-lg border p-3"
        :style="{
          borderColor: 'color-mix(in srgb, var(--danger) 40%, var(--border) 60%)',
          background: 'color-mix(in srgb, var(--danger-soft) 78%, var(--panel) 22%)',
        }"
      >
        <p class="muted">Gross Loss</p>
        <p class="mt-1 font-semibold negative">{{ asCurrency(summary?.gross_loss) }}</p>
      </div>
      <div class="rounded-lg border p-3" :style="{ borderColor: 'var(--border)', background: 'var(--panel-soft)' }">
        <p class="muted">Avg Win</p>
        <p class="mt-1 font-semibold">{{ asCurrency(summary?.avg_win) }}</p>
      </div>
      <div class="rounded-lg border p-3" :style="{ borderColor: 'var(--border)', background: 'var(--panel-soft)' }">
        <p class="muted">Avg Loss</p>
        <p class="mt-1 font-semibold">{{ asCurrency(summary?.avg_loss) }}</p>
      </div>
    </div>
  </div>
</template>
