<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    values: number[]
    width?: number
    height?: number
    positive?: boolean
  }>(),
  {
    width: 180,
    height: 52,
    positive: true,
  }
)

const polylinePoints = computed(() => {
  const values = props.values.length > 0 ? props.values : [0, 0]
  const min = Math.min(...values)
  const max = Math.max(...values)
  const range = max - min || 1
  const step = values.length > 1 ? props.width / (values.length - 1) : props.width

  return values
    .map((value, index) => {
      const x = Math.round(index * step * 100) / 100
      const normalized = (value - min) / range
      const y = Math.round((props.height - normalized * props.height) * 100) / 100
      return `${x},${y}`
    })
    .join(' ')
})
</script>

<template>
  <svg :viewBox="`0 0 ${width} ${height}`" class="mini-sparkline" preserveAspectRatio="none" aria-hidden="true">
    <polyline
      :points="polylinePoints"
      fill="none"
      :stroke="positive ? 'var(--primary)' : 'var(--danger)'"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
  </svg>
</template>

