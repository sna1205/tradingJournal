<script setup lang="ts">
import { computed } from 'vue'
import type { PerformanceProfile } from '@/stores/analyticsStore'

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

const option = computed(() => ({
  backgroundColor: 'transparent',
  animationDuration: 820,
  animationDurationUpdate: 520,
  animationEasing: 'cubicOut',
  tooltip: {
    backgroundColor: '#11161D',
    borderColor: '#1F2937',
    textStyle: { color: '#E5E7EB' },
  },
  radar: {
    radius: '66%',
    splitNumber: 4,
    axisName: { color: '#9CA3AF', fontSize: 11 },
    axisLine: { lineStyle: { color: '#1F2937' } },
    splitLine: { lineStyle: { color: 'rgba(31, 41, 55, 0.65)' } },
    splitArea: {
      areaStyle: {
        color: ['rgba(17, 22, 29, 0.55)', 'rgba(17, 22, 29, 0.78)'],
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
            color: '#22C55E',
            width: 2.5,
          },
          itemStyle: { color: '#22C55E' },
          areaStyle: {
            color: {
              type: 'linear',
              x: 0,
              y: 0,
              x2: 1,
              y2: 1,
              colorStops: [
                { offset: 0, color: 'rgba(34, 197, 94, 0.42)' },
                { offset: 1, color: 'rgba(34, 197, 94, 0.08)' },
              ],
            },
          },
        },
      ],
    },
  ],
}))
</script>

<template>
  <VChart :option="option" autoresize class="h-[320px]" />
</template>
