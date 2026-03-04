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

const props = defineProps<{
  monthKey?: string
}>()

const loading = ref(false)
const rows = ref<DailyAnalyticsRow[]>([])

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

const normalizedRows = computed(() =>
  rows.value.map((row) => ({
    date: normalizeDateKey(row.date),
    trades: Number(row.total_trades ?? 0),
    pnl: Number(row.profit_loss ?? 0),
  }))
)

const rowMap = computed(() => {
  const map = new Map<string, { trades: number; pnl: number }>()
  for (const row of normalizedRows.value) {
    const previous = map.get(row.date)
    map.set(row.date, {
      trades: (previous?.trades ?? 0) + row.trades,
      pnl: Number(((previous?.pnl ?? 0) + row.pnl).toFixed(2)),
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

const activeMonthDate = computed(() => parseMonthKey(props.monthKey) ?? new Date())

const currentMonth = computed<MonthGrid>(() =>
  buildMonthGrid(activeMonthDate.value.getFullYear(), activeMonthDate.value.getMonth())
)

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
      backgroundColor: 'var(--panel)',
      borderColor: 'var(--border)',
    }
  }

  const baseIntensity = maxAbsPnl.value > 0 ? Math.min(Math.abs(cell.pnl) / maxAbsPnl.value, 1) : 0
  const alpha = 0.24 + baseIntensity * 0.56

  if (cell.pnl > 0) {
    return {
      backgroundColor: `rgba(34, 197, 94, ${alpha * 0.78})`,
      borderColor: 'rgba(34, 197, 94, 0.48)',
    }
  }

  if (cell.pnl < 0) {
    return {
      backgroundColor: `rgba(239, 68, 68, ${alpha * 0.72})`,
      borderColor: 'rgba(239, 68, 68, 0.42)',
    }
  }

  return {
    backgroundColor: 'var(--panel-soft)',
    borderColor: 'var(--border)',
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
    return `${dateLabel}\nNo executions`
  }

  return `${dateLabel}\nExecutions: ${cell.trades}\nP&L: ${asSignedCurrency(cell.pnl)}`
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

function normalizeDateKey(value: string): string {
  const directMatch = value.match(/^(\d{4}-\d{2}-\d{2})/)
  if (directMatch?.[1]) {
    return directMatch[1]
  }

  const parsed = new Date(value)
  if (!Number.isNaN(parsed.getTime())) {
    const year = parsed.getUTCFullYear().toString().padStart(4, '0')
    const month = `${parsed.getUTCMonth() + 1}`.padStart(2, '0')
    const day = `${parsed.getUTCDate()}`.padStart(2, '0')
    return `${year}-${month}-${day}`
  }

  return value
}

function parseMonthKey(value: string | undefined): Date | null {
  if (!value) return null
  const matched = value.match(/^(\d{4})-(\d{2})$/)
  if (!matched) return null

  const year = Number(matched[1])
  const month = Number(matched[2])
  if (!Number.isInteger(year) || !Number.isInteger(month) || month < 1 || month > 12) {
    return null
  }

  return new Date(year, month - 1, 1)
}

onMounted(async () => {
  await fetchDailyAnalytics()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3 text-xs">
      <span class="pill">No execution</span>
      <span class="pill pill-positive">Profit</span>
      <span class="pill pill-negative">Loss</span>
      <span v-if="loading" class="muted">Loading heatmap...</span>
    </div>

    <div class="grid grid-premium">
      <article class="panel p-4">
        <h4 class="mb-3 text-sm font-semibold tracking-wide">{{ currentMonth.label }}</h4>
        <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[10px] uppercase tracking-wide muted">
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
                <p class="text-[11px] font-semibold">{{ cell.day }}</p>
                <p v-if="cell.trades > 0" class="mt-1 text-[10px]">{{ cell.trades }} executions</p>
                <p v-if="cell.trades > 0" class="text-[10px] font-semibold" :class="cell.pnl >= 0 ? 'positive' : 'negative'">
                  {{ asSignedCurrency(cell.pnl) }}
                </p>
              </template>

              <div
                v-if="cell"
                class="pointer-events-none absolute left-1/2 top-0 z-20 hidden w-44 -translate-x-1/2 -translate-y-[105%] rounded-lg border px-2.5 py-2 text-[11px] shadow-lg group-hover:block"
                style="border-color: var(--border); background: var(--panel); color: var(--text)"
              >
                <p class="font-semibold">{{ toLocalDate(cell.date).toLocaleDateString() }}</p>
                <p class="muted">Executions: {{ cell.trades }}</p>
                <p :class="cell.pnl >= 0 ? 'positive' : 'negative'">P&amp;L: {{ asSignedCurrency(cell.pnl) }}</p>
              </div>
            </div>
          </div>
        </div>
      </article>
    </div>
  </div>
</template>
