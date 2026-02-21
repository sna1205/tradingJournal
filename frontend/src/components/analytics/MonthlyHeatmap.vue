<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import BaseSelect from '@/components/form/BaseSelect.vue'

interface HeatmapDay {
  close_date: string
  number_of_trades: number
  total_profit: number
  average_r: number
  win_rate: number
  intensity: number
}

interface HeatmapMonth {
  month: string
  label: string
  days: HeatmapDay[]
}

const props = defineProps<{
  months: HeatmapMonth[]
}>()

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

type HeatmapCell = {
  key: string
  day?: HeatmapDay
  label?: number
  padding?: boolean
}

function monthKeyFromDate(date: Date) {
  return `${date.getFullYear()}-${`${date.getMonth() + 1}`.padStart(2, '0')}`
}

function monthLabelFromKey(monthKey: string) {
  const [yearRaw, monthRaw] = monthKey.split('-')
  const year = Number(yearRaw || 1970)
  const monthNumber = Number(monthRaw || 1)

  return new Date(year, monthNumber - 1, 1).toLocaleString('en-US', {
    month: 'long',
    year: 'numeric',
  })
}

const currentMonthKey = monthKeyFromDate(new Date())
const selectedMonth = ref(currentMonthKey)

const monthMap = computed(() => new Map(props.months.map((month) => [month.month, month])))

const monthOptions = computed(() => {
  const keys = new Set<string>([currentMonthKey, ...props.months.map((month) => month.month)])
  return [...keys]
    .sort((a, b) => b.localeCompare(a))
    .map((key) => ({
      value: key,
      label: monthMap.value.get(key)?.label ?? monthLabelFromKey(key),
    }))
})

watch(
  monthOptions,
  (options) => {
    if (options.length === 0) {
      selectedMonth.value = currentMonthKey
      return
    }

    const hasSelected = options.some((option) => option.value === selectedMonth.value)
    if (!hasSelected) {
      selectedMonth.value = currentMonthKey
    }
  },
  { immediate: true }
)

const activeMonth = computed<HeatmapMonth>(() => {
  const selected = monthMap.value.get(selectedMonth.value)
  if (selected) return selected

  return {
    month: selectedMonth.value,
    label: monthLabelFromKey(selectedMonth.value),
    days: [],
  }
})

const prepared = computed(() => {
  const month = activeMonth.value
  const dayMap = new Map<string, HeatmapDay>()
  for (const day of month.days) {
    dayMap.set(day.close_date, day)
  }

  const [yearRaw, monthRaw] = month.month.split('-')
  const year = Number(yearRaw || 1970)
  const monthNumber = Number(monthRaw || 1)
  const safeYear = Number.isFinite(year) ? year : 1970
  const safeMonth = Number.isFinite(monthNumber) ? monthNumber : 1
  const daysInMonth = new Date(safeYear, safeMonth, 0).getDate()
  const firstDay = new Date(safeYear, safeMonth - 1, 1).getDay()
  const cells: HeatmapCell[] = []

  for (let i = 0; i < firstDay; i += 1) {
    cells.push({ key: `${month.month}-empty-${i}`, padding: true })
  }

  for (let day = 1; day <= daysInMonth; day += 1) {
    const date = `${safeYear}-${`${safeMonth}`.padStart(2, '0')}-${`${day}`.padStart(2, '0')}`
    cells.push({
      key: `${month.month}-${day}`,
      day: dayMap.get(date),
      label: day,
    })
  }

  return {
    ...month,
    cells,
  }
})

function cellStyle(cell: HeatmapCell) {
  if (cell.padding) {
    return {
      backgroundColor: 'transparent',
      borderColor: 'transparent',
    }
  }

  const day = cell.day
  if (!day) {
    return {
      backgroundColor: 'var(--panel)',
      borderColor: 'var(--border)',
    }
  }

  if (day.number_of_trades === 0) {
    return {
      backgroundColor: 'var(--panel)',
      borderColor: 'var(--border)',
    }
  }

  const alpha = 0.22 + (day.intensity || 0) * 0.62

  if (day.total_profit >= 0) {
    return {
      backgroundColor: `rgba(23, 154, 86, ${alpha})`,
      borderColor: 'rgba(23, 154, 86, 0.52)',
    }
  }

  return {
    backgroundColor: `rgba(217, 70, 70, ${alpha})`,
    borderColor: 'rgba(217, 70, 70, 0.5)',
  }
}
</script>

<template>
  <article class="panel p-4">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-sm font-semibold">{{ prepared.label }}</h4>
      <div class="heatmap-month-select">
        <BaseSelect
          v-model="selectedMonth"
          label="Month"
          size="sm"
          :options="monthOptions"
        />
      </div>
    </div>

    <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[10px] uppercase tracking-wide muted">
      <span v-for="day in weekdayLabels" :key="`${prepared.month}-${day}`">{{ day }}</span>
    </div>

    <div class="grid grid-cols-7 gap-1">
      <div
        v-for="cell in prepared.cells"
        :key="cell.key"
        class="min-h-[56px] rounded-lg border p-1.5 text-[11px]"
        :style="cellStyle(cell)"
        :title="cell.day
          ? `${cell.day.close_date} | Trades: ${cell.day.number_of_trades} | PnL: ${cell.day.total_profit}`
          : ''"
      >
        <template v-if="!cell.padding">
          <p class="font-semibold muted">{{ cell.label }}</p>
          <template v-if="cell.day">
            <p class="mt-1">{{ cell.day.number_of_trades }}T</p>
            <p class="font-semibold">{{ cell.day.total_profit >= 0 ? '+' : '' }}{{ cell.day.total_profit.toFixed(0) }}</p>
          </template>
        </template>
      </div>
    </div>
  </article>

  <p v-if="prepared.days.length === 0" class="text-xs muted">
    No closed trades for this month.
  </p>

</template>
