<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/uiStore'

const props = withDefaults(
  defineProps<{
    timestamps: string[]
    equity: number[]
    drawdown: number[]
    heightClass?: string
  }>(),
  {
    heightClass: 'h-[360px]',
  }
)

function readVar(name: string, fallback: string) {
  if (typeof window === 'undefined') return fallback
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

const uiStore = useUiStore()
const { theme } = storeToRefs(uiStore)

const normalized = computed(() => {
  const size = Math.min(props.timestamps.length, props.equity.length)
  const labels: string[] = []
  const values: number[] = []

  for (let i = 0; i < size; i += 1) {
    labels.push(formatDateLabel(props.timestamps[i] ?? ''))
    values.push(Number(props.equity[i] ?? 0))
  }

  return {
    labels,
    values,
    positiveSeries: values.map((value) => (value >= 0 ? value : null)),
    negativeSeries: values.map((value) => (value <= 0 ? value : null)),
  }
})

const yBounds = computed(() => {
  if (normalized.value.values.length === 0) {
    return { min: -500, max: 500 }
  }

  const minValue = Math.min(...normalized.value.values)
  const maxValue = Math.max(...normalized.value.values)
  const spread = Math.max(maxValue - minValue, 1)
  const padding = spread * 0.12

  return {
    min: roundCurrencyTick(minValue - padding),
    max: roundCurrencyTick(maxValue + padding),
  }
})

const option = computed(() => {
  void theme.value

  return {
  textStyle: {
    color: readVar('--text', '#e5ece8'),
    fontFamily: 'Manrope, sans-serif',
  },
  backgroundColor: 'transparent',
  animationDuration: 700,
  animationDurationUpdate: 450,
  tooltip: {
    trigger: 'axis',
    backgroundColor: readVar('--panel', '#0b1218'),
    borderColor: readVar('--border', '#24313b'),
    textStyle: { color: readVar('--text', '#e5ece8') },
    formatter: (raw: unknown) => {
      const params = Array.isArray(raw) ? raw : [raw]
      let value = 0
      let label = ''

      for (const entry of params as Array<Record<string, unknown>>) {
        if (!label && typeof entry.axisValueLabel === 'string') {
          label = entry.axisValueLabel
        }
        if (typeof entry.data === 'number') {
          value = entry.data
          break
        }
      }

      return `${label}<br/>Cumulative P&L: ${formatCurrency(value)}`
    },
  },
  grid: { left: 58, right: 24, top: 24, bottom: 40 },
  xAxis: {
    type: 'category',
    data: normalized.value.labels,
    boundaryGap: false,
    axisTick: { show: false },
    axisLabel: {
      color: readVar('--muted', '#83919f'),
      margin: 12,
    },
    axisLine: { lineStyle: { color: readVar('--chart-axis-line', 'rgba(132, 145, 159, 0.24)') } },
    splitLine: {
      show: true,
      lineStyle: {
        color: readVar('--chart-grid', 'rgba(132, 145, 159, 0.14)'),
        type: 'dashed',
      },
    },
  },
  yAxis: {
    type: 'value',
    min: yBounds.value.min,
    max: yBounds.value.max,
    axisTick: { show: false },
    axisLabel: {
      color: readVar('--muted', '#83919f'),
      formatter: (value: number) => compactCurrency(value),
      margin: 10,
    },
    axisLine: { show: false },
    splitLine: {
      lineStyle: {
        color: readVar('--chart-grid', 'rgba(132, 145, 159, 0.14)'),
        type: 'dashed',
      },
    },
  },
  series: [
    {
      name: 'Cumulative P&L (Positive)',
      type: 'line',
      data: normalized.value.positiveSeries,
      symbol: 'none',
      smooth: 0.35,
      lineStyle: {
        width: 2.8,
        color: readVar('--chart-positive', '#16c47f'),
      },
      areaStyle: {
        origin: 'auto',
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: readVar('--chart-positive-soft', 'rgba(22, 196, 127, 0.34)') },
            { offset: 1, color: readVar('--chart-positive-fade', 'rgba(22, 196, 127, 0.08)') },
          ],
        },
      },
      emphasis: { disabled: true },
      z: 3,
      markLine: {
        symbol: 'none',
        silent: true,
        label: { show: false },
        lineStyle: {
          color: readVar('--chart-axis-line', 'rgba(132, 145, 159, 0.24)'),
          type: 'dashed',
        },
        data: [{ yAxis: 0 }],
      },
    },
    {
      name: 'Cumulative P&L (Negative)',
      type: 'line',
      data: normalized.value.negativeSeries,
      symbol: 'none',
      smooth: 0.35,
      lineStyle: {
        width: 2.8,
        color: readVar('--chart-negative', '#ef4444'),
      },
      areaStyle: {
        origin: 'auto',
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: readVar('--chart-negative-soft', 'rgba(239, 68, 68, 0.28)') },
            { offset: 1, color: readVar('--chart-negative-fade', 'rgba(239, 68, 68, 0.06)') },
          ],
        },
      },
      emphasis: { disabled: true },
      z: 3,
    },
  ],
}
})

function formatDateLabel(value: string) {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
  })
}

function compactCurrency(value: number) {
  const absolute = Math.abs(value)
  const sign = value < 0 ? '-' : ''

  if (absolute >= 1000) {
    return `${sign}$${(absolute / 1000).toFixed(1)}k`
  }

  return `${sign}$${absolute.toFixed(0)}`
}

function formatCurrency(value: number) {
  const absolute = Math.abs(value)
  const sign = value < 0 ? '-' : ''
  return `${sign}$${absolute.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

function roundCurrencyTick(value: number) {
  const tick = 50
  return Math.round(value / tick) * tick
}
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>
