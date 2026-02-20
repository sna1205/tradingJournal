<script setup lang="ts">
import { computed } from 'vue'

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

const option = computed(() => ({
  backgroundColor: 'transparent',
  animationDuration: 700,
  animationDurationUpdate: 450,
  animationEasing: 'cubicOut',
  tooltip: {
    trigger: 'axis',
    backgroundColor: '#11161D',
    borderColor: '#1F2937',
    textStyle: { color: '#E5E7EB' },
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

      return `${row.date}<br/>Trades: ${row.total_trades}<br/>P&L: ${signed}`
    },
  },
  grid: { left: 42, right: 18, top: 16, bottom: 42 },
  xAxis: {
    type: 'category',
    data: props.rows.map((row) => row.date),
    axisLabel: { color: '#9CA3AF', rotate: 35 },
    axisLine: { lineStyle: { color: '#1F2937' } },
  },
  yAxis: {
    type: 'value',
    axisLabel: {
      color: '#9CA3AF',
      formatter: (val: number) => `${val}`,
    },
    axisLine: { lineStyle: { color: '#1F2937' } },
    splitLine: { lineStyle: { color: 'rgba(31, 41, 55, 0.55)' } },
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
                { offset: 0, color: 'rgba(34, 197, 94, 0.95)' },
                { offset: 1, color: 'rgba(21, 128, 61, 0.75)' },
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
              { offset: 0, color: 'rgba(239, 68, 68, 0.95)' },
              { offset: 1, color: 'rgba(153, 27, 27, 0.78)' },
            ],
          }
        },
      },
    },
  ],
}))
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>
