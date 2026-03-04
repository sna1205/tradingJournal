<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import VChart from 'vue-echarts'
import { ensurePieChartsRegistered } from '@/components/charts/echartsSetupPie'
import { useUiStore } from '@/stores/uiStore'

ensurePieChartsRegistered()

interface EmotionSlice {
  emotion: string
  total_trades: number
  total_profit?: number
}

const props = withDefaults(
  defineProps<{
    slices: EmotionSlice[]
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

const palette = ['#179a56', '#2f80ed', '#f0b74c', '#d94646', '#7c5cff', '#20b2aa', '#fb7185']

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
    trigger: 'item',
    backgroundColor: readVar('--panel', '#ffffff'),
    borderColor: readVar('--border', '#d4ddd5'),
    textStyle: { color: readVar('--text', '#18211b') },
  },
  legend: {
    bottom: 0,
    textStyle: { color: readVar('--muted', '#647469') },
  },
  series: [
    {
      name: 'Emotion',
      type: 'pie',
      radius: ['40%', '72%'],
      center: ['50%', '44%'],
      avoidLabelOverlap: true,
      itemStyle: {
        borderRadius: 8,
        borderColor: readVar('--panel', '#ffffff'),
        borderWidth: 2,
      },
      label: {
        show: true,
        formatter: '{b}: {d}%',
        color: readVar('--muted', '#647469'),
        fontSize: 11,
      },
      data: props.slices.map((slice, index) => ({
        name: slice.emotion,
        value: Number(slice.total_trades || 0),
        itemStyle: { color: palette[index % palette.length] },
      })),
    },
  ],
}
})
</script>

<template>
  <VChart :option="option" autoresize :class="heightClass" />
</template>
