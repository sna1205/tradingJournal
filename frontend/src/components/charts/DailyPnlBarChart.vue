<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import VChart from 'vue-echarts'
import { ensureChartsRegistered } from '@/components/charts/echartsSetup'
import { useUiStore } from '@/stores/uiStore'

ensureChartsRegistered()

interface DailyPoint {
  date: string
  total_trades: number
  profit_loss: number
}

const props = withDefaults(
  defineProps<{
    rows: DailyPoint[]
    heightClass?: string
  }>(),
  {
    heightClass: 'h-[320px]',
  }
)

function readVar(name: string, fallback: string) {
  if (typeof window === 'undefined') return fallback
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

const uiStore = useUiStore()
const { theme } = storeToRefs(uiStore)

const option = computed<Record<string, unknown>>(() => {
  void theme.value

  return {
  textStyle: {
    color: readVar('--text', '#18211b'),
    fontFamily: 'Manrope, sans-serif',
  },
  backgroundColor: 'transparent',
  animationDuration: 700,
  animationDurationUpdate: 450,
  animationEasing: 'cubicOut',
  tooltip: {
    trigger: 'axis',
    backgroundColor: readVar('--panel', '#ffffff'),
    borderColor: readVar('--border', '#d4ddd5'),
    textStyle: { color: readVar('--text', '#18211b') },
    formatter: (params: any[]) => {
      const first = params?.[0]
      const index = first?.dataIndex ?? 0
      const row = props.rows[index]
      if (!row) return ''

      const pnl = Number(row.profit_loss || 0)
      const signed = `${pnl >= 0 ? '+' : ''}${new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
      }).format(pnl)}`

      return `${row.date}<br/>Executions: ${row.total_trades}<br/>P&L: ${signed}`
    },
  },
  grid: { left: 42, right: 18, top: 16, bottom: 42 },
  xAxis: {
    type: 'category',
    data: props.rows.map((row) => row.date),
    axisLabel: { color: readVar('--muted', '#647469'), rotate: 35 },
    axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
  },
  yAxis: {
    type: 'value',
    axisLabel: {
      color: readVar('--muted', '#647469'),
      formatter: (val: number) => `${val}`,
    },
    axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
    splitLine: { lineStyle: { color: readVar('--chart-grid', 'rgba(100, 116, 105, 0.2)') } },
  },
  series: [
    {
      type: 'bar',
      data: props.rows.map((row) => Number(row.profit_loss || 0)),
      barMaxWidth: 24,
      itemStyle: {
        borderRadius: [8, 8, 0, 0],
        color: (params: any) => {
          const value = Number(params?.value ?? 0)
          if (value >= 0) {
            return {
              type: 'linear',
              x: 0,
              y: 0,
              x2: 0,
              y2: 1,
              colorStops: [
                { offset: 0, color: readVar('--chart-positive', '#179a56') },
                { offset: 1, color: readVar('--chart-positive-soft', 'rgba(23, 154, 86, 0.34)') },
              ],
            }
          }

          return {
            type: 'linear',
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
              { offset: 0, color: readVar('--chart-negative', '#d94646') },
              { offset: 1, color: readVar('--chart-negative-soft', 'rgba(217, 70, 70, 0.28)') },
            ],
          }
        },
      },
    },
  ],
}
})
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>
