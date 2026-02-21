<script setup lang="ts">
import { computed } from 'vue'

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

const option = computed(() => ({
  textStyle: {
    color: readVar('--text', '#18211b'),
    fontFamily: 'Manrope, sans-serif',
  },
  backgroundColor: 'transparent',
  tooltip: {
    trigger: 'axis',
    backgroundColor: readVar('--panel', '#ffffff'),
    borderColor: readVar('--border', '#d4ddd5'),
    textStyle: { color: readVar('--text', '#18211b') },
  },
  legend: {
    top: 2,
    textStyle: { color: readVar('--muted', '#647469') },
  },
  grid: { left: 48, right: 24, top: 38, bottom: 34 },
  xAxis: {
    type: 'category',
    data: props.timestamps,
    axisLabel: { color: readVar('--muted', '#647469') },
    axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
  },
  yAxis: [
    {
      type: 'value',
      name: 'Equity',
      axisLabel: { color: readVar('--muted', '#647469') },
      axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
      splitLine: { lineStyle: { color: 'rgba(100, 116, 105, 0.2)' } },
    },
    {
      type: 'value',
      name: 'Drawdown',
      axisLabel: { color: readVar('--muted', '#647469') },
      axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
      splitLine: { show: false },
    },
  ],
  series: [
    {
      name: 'Equity',
      type: 'line',
      yAxisIndex: 0,
      smooth: true,
      symbol: 'none',
      data: props.equity,
      lineStyle: { width: 2.6, color: readVar('--primary', '#179a56') },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: 'rgba(34, 197, 94, 0.34)' },
            { offset: 1, color: 'rgba(34, 197, 94, 0.04)' },
          ],
        },
      },
    },
    {
      name: 'Drawdown',
      type: 'bar',
      yAxisIndex: 1,
      barWidth: 12,
      data: props.drawdown,
      itemStyle: {
        borderRadius: [4, 4, 0, 0],
        color: 'rgba(217, 70, 70, 0.68)',
      },
    },
  ],
}))
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>

