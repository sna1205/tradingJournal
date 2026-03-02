<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/uiStore'

const props = withDefaults(
  defineProps<{
    points: Array<{ date: string; equity: number }>
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

const option = computed(() => {
  void theme.value

  return {
  textStyle: {
    color: readVar('--text', '#18211b'),
    fontFamily: 'Manrope, sans-serif',
  },
  backgroundColor: 'transparent',
  animationDuration: 750,
  animationDurationUpdate: 500,
  animationEasing: 'cubicOut',
  tooltip: {
    trigger: 'axis',
    backgroundColor: readVar('--panel', '#ffffff'),
    borderColor: readVar('--border', '#d4ddd5'),
    textStyle: {
      color: readVar('--text', '#18211b'),
    },
  },
  grid: { left: 42, right: 18, top: 20, bottom: 32 },
  xAxis: {
    type: 'category',
    data: props.points.map((point) => point.date),
    axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
    axisLabel: { color: readVar('--muted', '#647469') },
  },
  yAxis: {
    type: 'value',
    axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
    splitLine: { lineStyle: { color: readVar('--chart-grid', 'rgba(100, 116, 105, 0.2)') } },
    axisLabel: { color: readVar('--muted', '#647469') },
  },
  series: [
    {
      data: props.points.map((point) => point.equity),
      type: 'line',
      smooth: true,
      symbol: 'none',
      lineStyle: { width: 3, color: readVar('--chart-positive', '#179a56') },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: readVar('--chart-positive-soft', 'rgba(23, 154, 86, 0.34)') },
            { offset: 1, color: readVar('--chart-positive-fade', 'rgba(23, 154, 86, 0.08)') },
          ],
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
