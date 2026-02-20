<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { asSignedCurrency } from '@/utils/format'

interface DailyAnalyticsRow {
  date: string
  total_trades: number | string
  profit_loss: number | string
}

interface DayCell {
  key: string
  day: number
  date: string
  trades: number
  pnl: number
}

interface MonthGrid {
  key: string
  label: string
  weeks: Array<Array<DayCell | null>>
}

const loading = ref(false)
const rows = ref<DailyAnalyticsRow[]>([])

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

const normalizedRows = computed(() =>
  rows.value.map((row) => ({
    date: row.date,
    trades: Number(row.total_trades ?? 0),
    pnl: Number(row.profit_loss ?? 0),
  }))
)

const rowMap = computed(() => {
  const map = new Map<string, { trades: number; pnl: number }>()
  for (const row of normalizedRows.value) {
    map.set(row.date, {
      trades: row.trades,
      pnl: row.pnl,
    })
  }

  return map
})

const maxAbsPnl = computed(() => {
  let max = 0
  for (const row of normalizedRows.value) {
    max = Math.max(max, Math.abs(row.pnl))
  }

  return max
})

const currentMonth = computed<MonthGrid>(() => {
  const now = new Date()
  return buildMonthGrid(now.getFullYear(), now.getMonth())
})

async function fetchDailyAnalytics() {
  loading.value = true
  try {
    const { data } = await api.get<DailyAnalyticsRow[]>('/analytics/daily')
    rows.value = data
  } finally {
    loading.value = false
  }
}

function buildMonthGrid(year: number, month: number): MonthGrid {
  const monthName = new Date(year, month, 1).toLocaleString('en-US', {
    month: 'long',
    year: 'numeric',
  })
  const firstDay = new Date(year, month, 1)
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const mondayOffset = (firstDay.getDay() + 6) % 7

  const cells: Array<DayCell | null> = []
  for (let i = 0; i < mondayOffset; i += 1) {
    cells.push(null)
  }

  for (let day = 1; day <= daysInMonth; day += 1) {
    const yyyy = year.toString().padStart(4, '0')
    const mm = `${month + 1}`.padStart(2, '0')
    const dd = `${day}`.padStart(2, '0')
    const date = `${yyyy}-${mm}-${dd}`
    const metrics = rowMap.value.get(date)

    cells.push({
      key: `${date}-cell`,
      day,
      date,
      trades: metrics?.trades ?? 0,
      pnl: metrics?.pnl ?? 0,
    })
  }

  while (cells.length % 7 !== 0) {
    cells.push(null)
  }

  const weeks: Array<Array<DayCell | null>> = []
  for (let i = 0; i < cells.length; i += 7) {
    weeks.push(cells.slice(i, i + 7))
  }

  return {
    key: `${year}-${month + 1}`,
    label: monthName,
    weeks,
  }
}

function cellStyle(cell: DayCell | null) {
  if (!cell || cell.trades === 0) {
    return {
      backgroundColor: '#0F131A',
      borderColor: '#1F2937',
    }
  }

  const baseIntensity = maxAbsPnl.value > 0 ? Math.min(Math.abs(cell.pnl) / maxAbsPnl.value, 1) : 0
  const alpha = 0.24 + baseIntensity * 0.56

  if (cell.pnl > 0) {
    return {
      backgroundColor: `rgba(34, 197, 94, ${alpha})`,
      borderColor: 'rgba(34, 197, 94, 0.7)',
    }
  }

  if (cell.pnl < 0) {
    return {
      backgroundColor: `rgba(239, 68, 68, ${alpha})`,
      borderColor: 'rgba(239, 68, 68, 0.7)',
    }
  }

  return {
    backgroundColor: 'rgba(156, 163, 175, 0.2)',
    borderColor: '#4B5563',
  }
}

function tooltipText(cell: DayCell | null): string {
  if (!cell) return ''

  const dateLabel = toLocalDate(cell.date).toLocaleDateString('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  })

  if (cell.trades === 0) {
    return `${dateLabel}\nNo trades`
  }

  return `${dateLabel}\nTrades: ${cell.trades}\nP&L: ${asSignedCurrency(cell.pnl)}`
}

function toLocalDate(value: string): Date {
  const parts = value.split('-')
  const year = Number(parts[0] ?? NaN)
  const month = Number(parts[1] ?? NaN)
  const day = Number(parts[2] ?? NaN)

  const safeYear = Number.isFinite(year) ? year : 1970
  const safeMonth = Number.isFinite(month) ? month : 1
  const safeDay = Number.isFinite(day) ? day : 1

  return new Date(safeYear, safeMonth - 1, safeDay)
}

onMounted(async () => {
  await fetchDailyAnalytics()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3 text-xs text-slate-400">
      <span class="rounded-full border border-slate-700 px-2 py-1">No trade</span>
      <span class="rounded-full border border-emerald-500/70 bg-emerald-500/20 px-2 py-1">Profit</span>
      <span class="rounded-full border border-rose-500/70 bg-rose-500/20 px-2 py-1">Loss</span>
      <span v-if="loading" class="text-slate-500">Loading heatmap...</span>
    </div>

    <div class="grid grid-premium">
      <article class="rounded-2xl border border-slate-800 bg-slate-950/40 p-4">
        <h4 class="mb-3 text-sm font-semibold tracking-wide text-slate-200">{{ currentMonth.label }}</h4>
        <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[10px] uppercase tracking-wide text-slate-500">
          <span v-for="day in weekdays" :key="`${currentMonth.key}-${day}`">{{ day }}</span>
        </div>

        <div class="space-y-1">
          <div
            v-for="(week, weekIndex) in currentMonth.weeks"
            :key="`${currentMonth.key}-week-${weekIndex}`"
            class="grid grid-cols-7 gap-1"
          >
            <div
              v-for="(cell, dayIndex) in week"
              :key="cell ? cell.key : `${currentMonth.key}-empty-${weekIndex}-${dayIndex}`"
              class="group relative min-h-[58px] rounded-xl border p-1.5 transition-all duration-200 ease-in-out"
              :style="cellStyle(cell)"
              :title="tooltipText(cell)"
            >
              <template v-if="cell">
                <p class="text-[11px] font-semibold text-slate-100">{{ cell.day }}</p>
                <p v-if="cell.trades > 0" class="mt-1 text-[10px] text-slate-100/90">{{ cell.trades }} trades</p>
                <p
                  v-if="cell.trades > 0"
                  class="text-[10px] font-semibold"
                  :class="cell.pnl >= 0 ? 'text-emerald-100' : 'text-rose-100'"
                >
                  {{ asSignedCurrency(cell.pnl) }}
                </p>
              </template>

              <div
                v-if="cell"
                class="pointer-events-none absolute left-1/2 top-0 z-20 hidden w-44 -translate-x-1/2 -translate-y-[105%] rounded-lg border border-slate-700 bg-slate-900 px-2.5 py-2 text-[11px] text-slate-200 shadow-lg group-hover:block"
              >
                <p class="font-semibold">{{ toLocalDate(cell.date).toLocaleDateString() }}</p>
                <p class="text-slate-300">Trades: {{ cell.trades }}</p>
                <p :class="cell.pnl >= 0 ? 'text-emerald-300' : 'text-rose-300'">P&L: {{ asSignedCurrency(cell.pnl) }}</p>
              </div>
            </div>
          </div>
        </div>
      </article>
    </div>
  </div>
</template>
