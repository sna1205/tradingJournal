<script setup lang="ts">
import { computed } from 'vue'
import {
  Activity,
  BadgeDollarSign,
  Sigma,
  TrendingUp,
  type LucideIcon,
} from 'lucide-vue-next'
import Card from '@/components/layout/Card.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import type { SummaryStats } from '@/types/trade'

interface SummaryCard {
  label: string
  value: number | null
  decimals: number
  prefix: string
  suffix: string
  sign: boolean
  tone: 'neutral' | 'positive' | 'negative'
  icon: LucideIcon
  highlightColor: string
}

const props = defineProps<{
  summary: SummaryStats | null
}>()

const cards = computed<SummaryCard[]>(() => {
  if (!props.summary) {
    return []
  }

  return [
    {
      label: 'Net P&L',
      value: props.summary.total_pnl,
      decimals: 2,
      prefix: '$',
      suffix: '',
      sign: true,
      tone: props.summary.total_pnl >= 0 ? 'positive' : 'negative',
      icon: BadgeDollarSign,
      highlightColor: props.summary.total_pnl >= 0 ? '#22C55E' : '#EF4444',
    },
    {
      label: 'Win Rate',
      value: props.summary.win_rate,
      decimals: 2,
      prefix: '',
      suffix: '%',
      sign: false,
      tone: props.summary.win_rate >= 50 ? 'positive' : 'negative',
      icon: TrendingUp,
      highlightColor: props.summary.win_rate >= 50 ? '#22C55E' : '#EF4444',
    },
    {
      label: 'Profit Factor',
      value: props.summary.profit_factor,
      decimals: 2,
      prefix: '',
      suffix: '',
      sign: false,
      tone: 'neutral',
      icon: Sigma,
      highlightColor: '#9CA3AF',
    },
    {
      label: 'Expectancy',
      value: props.summary.expectancy,
      decimals: 2,
      prefix: '$',
      suffix: '',
      sign: true,
      tone: props.summary.expectancy >= 0 ? 'positive' : 'negative',
      icon: Activity,
      highlightColor: props.summary.expectancy >= 0 ? '#22C55E' : '#EF4444',
    },
  ]
})
</script>

<template>
  <section class="grid grid-premium sm:grid-cols-2 xl:grid-cols-4">
    <Card
      v-for="card in cards"
      :key="card.label"
      :title="card.label"
      :icon="card.icon"
      :highlight-color="card.highlightColor"
    >
      <p
        class="value value-display"
        :class="{
          positive: card.tone === 'positive',
          negative: card.tone === 'negative',
        }"
      >
        <AnimatedNumber
          v-if="card.value !== null"
          :value="card.value"
          :decimals="card.decimals"
          :prefix="card.prefix"
          :suffix="card.suffix"
          :sign="card.sign"
        />
        <span v-else class="muted">-</span>
      </p>
    </Card>
  </section>
</template>
