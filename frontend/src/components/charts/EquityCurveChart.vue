<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  points: Array<{ date: string; equity: number }>
}>()

const option = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 36, right: 16, top: 20, bottom: 30 },
  xAxis: {
    type: 'category',
    data: props.points.map((point) => point.date),
    axisLine: { lineStyle: { color: '#334155' } },
    axisLabel: { color: '#94a3b8' },
  },
  yAxis: {
    type: 'value',
    axisLine: { lineStyle: { color: '#334155' } },
    splitLine: { lineStyle: { color: 'rgba(148, 163, 184, 0.12)' } },
    axisLabel: { color: '#94a3b8' },
  },
  series: [
    {
      data: props.points.map((point) => point.equity),
      type: 'line',
      smooth: true,
      symbol: 'none',
      lineStyle: { width: 3, color: '#22d3ee' },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: 'rgba(34, 211, 238, 0.45)' },
            { offset: 1, color: 'rgba(34, 211, 238, 0.02)' },
          ],
        },
      },
    },
  ],
}))
</script>

<template>
  <VChart :option="option" autoresize class="h-[320px]" />
</template>
