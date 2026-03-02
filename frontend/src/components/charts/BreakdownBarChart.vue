<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import VChart from 'vue-echarts'
import { ensureChartsRegistered } from '@/components/charts/echartsSetup'
import { useUiStore } from '@/stores/uiStore'

ensureChartsRegistered()

const props = withDefaults(
  defineProps<{
    labels: string[]
    values: number[]
    color?: string
    rotateX?: number
    heightClass?: string
  }>(),
  {
    color: '',
    rotateX: 0,
    heightClass: 'h-[260px]',
  }
)

const uiStore = useUiStore()
const { theme } = storeToRefs(uiStore)

function readVar(name: string, fallback: string) {
  if (typeof window === 'undefined') return fallback
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

const option = computed(() => {
  void theme.value

  const barColor = props.color || readVar('--chart-cyan', '#0284c7')

  return {
  tooltip: { trigger: 'axis' },
  grid: { left: 36, right: 16, top: 16, bottom: 28 },
  xAxis: {
    type: 'category',
    data: props.labels,
    axisLabel: { color: readVar('--muted', '#647469'), rotate: props.rotateX },
    axisLine: { lineStyle: { color: readVar('--chart-axis-line', 'rgba(100, 116, 105, 0.28)') } },
  },
  yAxis: {
    type: 'value',
    axisLabel: { color: readVar('--muted', '#647469') },
    splitLine: { lineStyle: { color: readVar('--chart-grid', 'rgba(100, 116, 105, 0.2)') } },
  },
  series: [
    {
      type: 'bar',
      data: props.values,
      itemStyle: {
        borderRadius: [8, 8, 0, 0],
        color: barColor,
      },
    },
  ],
}
})
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>
