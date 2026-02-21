<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/uiStore'

interface SessionRow {
  session?: string
  total_pnl: number
}

const props = withDefaults(
  defineProps<{
    rows: SessionRow[]
    heightClass?: string
  }>(),
  {
    heightClass: 'h-[300px]',
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
  tooltip: {
    trigger: 'axis',
    backgroundColor: readVar('--panel', '#ffffff'),
    borderColor: readVar('--border', '#d4ddd5'),
    textStyle: { color: readVar('--text', '#18211b') },
  },
  grid: { left: 44, right: 16, top: 20, bottom: 34 },
  xAxis: {
    type: 'category',
    data: props.rows.map((row) => row.session || 'Unknown'),
    axisLabel: { color: readVar('--muted', '#647469') },
    axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
  },
  yAxis: {
    type: 'value',
    axisLabel: { color: readVar('--muted', '#647469') },
    axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
    splitLine: { lineStyle: { color: readVar('--chart-grid', 'rgba(100, 116, 105, 0.2)') } },
  },
  series: [
    {
      type: 'bar',
      barMaxWidth: 28,
      data: props.rows.map((row) => Number(row.total_pnl || 0)),
      itemStyle: {
        borderRadius: [7, 7, 0, 0],
        color: (params: { value: number }) =>
          Number(params.value) >= 0
            ? readVar('--chart-positive', '#179a56')
            : readVar('--chart-negative', '#d94646'),
      },
    },
  ],
}
})
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>
