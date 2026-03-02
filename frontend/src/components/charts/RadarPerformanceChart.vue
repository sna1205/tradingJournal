<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import VChart from 'vue-echarts'
import { ensureChartsRegistered } from '@/components/charts/echartsSetup'
import type { PerformanceProfile } from '@/stores/analyticsStore'
import { useUiStore } from '@/stores/uiStore'

ensureChartsRegistered()

const props = defineProps<{
  profile: PerformanceProfile | null
}>()

function clamp(value: number, max: number) {
  return Math.max(0, Math.min(value, max))
}

const normalized = computed(() => {
  const profile = props.profile
  if (!profile) {
    return [0, 0, 0, 0, 0]
  }

  return [
    clamp(profile.win_rate, 100),
    clamp(profile.avg_rr * 20, 100), // 5 RR => 100
    clamp((profile.profit_factor ?? 0) * 20, 100), // PF 5 => 100
    clamp(profile.consistency_score, 100),
    clamp((profile.recovery_factor ?? 0) * 20, 100), // RF 5 => 100
  ]
})

function readVar(name: string, fallback: string) {
  if (typeof window === 'undefined') return fallback
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

const uiStore = useUiStore()
const { theme } = storeToRefs(uiStore)

const option = computed<Record<string, unknown>>(() => {
  void theme.value

  return {
  textStyle: {
    color: readVar('--text', '#18211b'),
    fontFamily: 'Manrope, sans-serif',
  },
  backgroundColor: 'transparent',
  animationDuration: 820,
  animationDurationUpdate: 520,
  animationEasing: 'cubicOut',
  tooltip: {
    backgroundColor: readVar('--panel', '#ffffff'),
    borderColor: readVar('--border', '#d4ddd5'),
    textStyle: { color: readVar('--text', '#18211b') },
  },
  radar: {
    radius: '66%',
    splitNumber: 4,
    axisName: { color: readVar('--muted', '#647469'), fontSize: 11 },
    axisLine: { lineStyle: { color: readVar('--border', '#d4ddd5') } },
    splitLine: { lineStyle: { color: readVar('--chart-grid', 'rgba(100, 116, 105, 0.2)') } },
    splitArea: {
      areaStyle: {
        color: [
          readVar('--chart-radar-split-a', 'rgba(255, 255, 255, 0.44)'),
          readVar('--chart-radar-split-b', 'rgba(232, 237, 229, 0.58)'),
        ],
      },
    },
    indicator: [
      { name: 'Win Rate', max: 100 },
      { name: 'Avg RR', max: 100 },
      { name: 'Profit Factor', max: 100 },
      { name: 'Consistency', max: 100 },
      { name: 'Recovery', max: 100 },
    ],
  },
  series: [
    {
      type: 'radar',
      data: [
        {
          value: normalized.value,
          name: 'Performance',
          symbol: 'circle',
          symbolSize: 5,
          lineStyle: {
            color: readVar('--primary', '#179a56'),
            width: 2.5,
          },
          itemStyle: { color: readVar('--primary', '#179a56') },
          areaStyle: {
            color: {
              type: 'linear',
              x: 0,
              y: 0,
              x2: 1,
              y2: 1,
              colorStops: [
                { offset: 0, color: readVar('--chart-positive-soft', 'rgba(23, 154, 86, 0.34)') },
                { offset: 1, color: readVar('--chart-positive-fade', 'rgba(23, 154, 86, 0.08)') },
              ],
            },
          },
        },
      ],
    },
  ],
}
})
</script>

<template>
  <VChart :option="option" autoresize class="h-[320px]" />
</template>
