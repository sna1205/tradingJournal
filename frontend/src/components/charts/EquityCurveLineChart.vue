<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    points: Array<{ date: string; equity: number }>
    heightClass?: string
  }>(),
  {
    heightClass: 'h-[320px]',
  }
)

const option = computed(() => ({
  backgroundColor: 'transparent',
  animationDuration: 750,
  animationDurationUpdate: 500,
  animationEasing: 'cubicOut',
  tooltip: {
    trigger: 'axis',
    backgroundColor: '#11161D',
    borderColor: '#1F2937',
    textStyle: { color: '#E5E7EB' },
  },
  grid: { left: 42, right: 18, top: 20, bottom: 32 },
  xAxis: {
    type: 'category',
    data: props.points.map((point) => point.date),
    axisLine: { lineStyle: { color: '#1F2937' } },
    axisLabel: { color: '#9CA3AF' },
  },
  yAxis: {
    type: 'value',
    axisLine: { lineStyle: { color: '#1F2937' } },
    splitLine: { lineStyle: { color: 'rgba(31, 41, 55, 0.55)' } },
    axisLabel: { color: '#9CA3AF' },
  },
  series: [
    {
      data: props.points.map((point) => point.equity),
      type: 'line',
      smooth: true,
      symbol: 'none',
      lineStyle: { width: 3, color: '#22C55E' },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: 'rgba(34, 197, 94, 0.45)' },
            { offset: 1, color: 'rgba(34, 197, 94, 0.03)' },
          ],
        },
      },
    },
  ],
}))
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>
