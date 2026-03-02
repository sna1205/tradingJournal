<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/uiStore'

const props = defineProps<{
  points: Array<{ date: string; equity: number }>
}>()

const uiStore = useUiStore()
const { theme } = storeToRefs(uiStore)

function readVar(name: string, fallback: string) {
  if (typeof window === 'undefined') return fallback
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

const option = computed(() => {
  void theme.value

  return {
  tooltip: { trigger: 'axis' },
  grid: { left: 36, right: 16, top: 20, bottom: 30 },
  xAxis: {
    type: 'category',
    data: props.points.map((point) => point.date),
    axisLine: { lineStyle: { color: readVar('--chart-axis-line', 'rgba(100, 116, 105, 0.28)') } },
    axisLabel: { color: readVar('--muted', '#647469') },
  },
  yAxis: {
    type: 'value',
    axisLine: { lineStyle: { color: readVar('--chart-axis-line', 'rgba(100, 116, 105, 0.28)') } },
    splitLine: { lineStyle: { color: readVar('--chart-grid', 'rgba(100, 116, 105, 0.2)') } },
    axisLabel: { color: readVar('--muted', '#647469') },
  },
  series: [
    {
      data: props.points.map((point) => point.equity),
      type: 'line',
      smooth: true,
      symbol: 'none',
      lineStyle: { width: 3, color: readVar('--chart-cyan', '#0284c7') },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: readVar('--chart-cyan-soft', 'rgba(2, 132, 199, 0.26)') },
            { offset: 1, color: readVar('--chart-cyan-fade', 'rgba(2, 132, 199, 0.03)') },
          ],
        },
      },
    },
  ],
}
})
</script>

<template>
  <VChart :option="option" autoresize class="h-[320px]" />
</template>
