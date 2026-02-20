<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    labels: string[]
    values: number[]
    color?: string
    rotateX?: number
    heightClass?: string
  }>(),
  {
    color: '#38bdf8',
    rotateX: 0,
    heightClass: 'h-[260px]',
  }
)

const option = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 36, right: 16, top: 16, bottom: 28 },
  xAxis: {
    type: 'category',
    data: props.labels,
    axisLabel: { color: '#94a3b8', rotate: props.rotateX },
    axisLine: { lineStyle: { color: '#334155' } },
  },
  yAxis: {
    type: 'value',
    axisLabel: { color: '#94a3b8' },
    splitLine: { lineStyle: { color: 'rgba(148, 163, 184, 0.12)' } },
  },
  series: [
    {
      type: 'bar',
      data: props.values,
      itemStyle: {
        borderRadius: [8, 8, 0, 0],
        color: props.color,
      },
    },
  ],
}))
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>
