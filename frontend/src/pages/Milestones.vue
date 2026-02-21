<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { Award, CheckCircle2, Flag } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import api from '@/services/api'
import { useAnalyticsStore, type AnalyticsOverview } from '@/stores/analyticsStore'
import { asCurrency } from '@/utils/format'

interface MilestoneItem {
  id: string
  title: string
  description: string
  current: number
  target: number
  formatter: (value: number) => string
  accent: string
}

const analyticsStore = useAnalyticsStore()
const { overview } = storeToRefs(analyticsStore)
const loading = ref(false)
const monthOverview = ref<AnalyticsOverview | null>(null)

const currentMonthLabel = computed(() =>
  new Date().toLocaleString('en-US', { month: 'long', year: 'numeric' })
)

const milestones = computed<MilestoneItem[]>(() => {
  const totalTrades = overview.value?.total_trades ?? 0
  const totalProfit = overview.value?.total_profit ?? 0
  const monthWinRate = monthOverview.value?.win_rate ?? 0

  return [
    {
      id: 'trades-100',
      title: '100 Trades Completed',
      description: 'Build execution consistency through completed trade volume.',
      current: totalTrades,
      target: 100,
      formatter: (value) => `${Math.round(value)} trades`,
      accent: '#22C55E',
    },
    {
      id: 'profit-10k',
      title: '$10,000 Total Profit',
      description: 'Track cumulative gross profit across all recorded trades.',
      current: totalProfit,
      target: 10000,
      formatter: (value) => asCurrency(value),
      accent: '#38BDF8',
    },
    {
      id: 'win-rate-80',
      title: '80% Win Rate Month',
      description: `Measure ${currentMonthLabel.value} win-rate quality against elite consistency.`,
      current: monthWinRate,
      target: 80,
      formatter: (value) => `${value.toFixed(2)}%`,
      accent: '#F59E0B',
    },
  ]
})

const completedMilestones = computed(() =>
  milestones.value.filter((item) => item.current >= item.target)
)

function progressPercent(item: MilestoneItem) {
  if (item.target <= 0) return 0
  return Math.max(0, Math.min(100, (item.current / item.target) * 100))
}

async function fetchMonthOverview() {
  const now = new Date()
  const year = now.getFullYear()
  const month = now.getMonth()
  const startDate = new Date(year, month, 1).toISOString().slice(0, 10)
  const endDate = new Date(year, month + 1, 0).toISOString().slice(0, 10)

  const { data } = await api.get<AnalyticsOverview>('/analytics/overview', {
    params: {
      date_from: startDate,
      date_to: endDate,
    },
  })

  monthOverview.value = {
    ...data,
    total_trades: Number(data.total_trades || 0),
    win_rate: Number(data.win_rate || 0),
    total_profit: Number(data.total_profit || 0),
    total_loss: Number(data.total_loss || 0),
    profit_factor: data.profit_factor === null ? null : Number(data.profit_factor),
    returns_percent: Number(data.returns_percent || 0),
  }
}

onMounted(async () => {
  loading.value = true
  try {
    await Promise.all([analyticsStore.fetchAnalytics(), fetchMonthOverview()])
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <GlassPanel>
      <div class="section-head">
        <div>
          <h2 class="text-xl font-bold">Milestones</h2>
          <p class="section-note">Progress toward key trading discipline and performance targets.</p>
        </div>
        <span class="pill">
          {{ loading ? 'Refreshing...' : currentMonthLabel }}
        </span>
      </div>

      <div v-if="loading && !overview" class="grid grid-premium md:grid-cols-3">
        <SkeletonBlock v-for="row in 3" :key="`milestone-skeleton-${row}`" height-class="h-44" rounded-class="rounded-2xl" />
      </div>

      <EmptyState
        v-else-if="!overview"
        title="No milestone data yet"
        description="Add trades to start tracking milestone progress."
      />

      <div v-else class="grid grid-premium md:grid-cols-3">
        <article
          v-for="item in milestones"
          :key="item.id"
          class="panel p-4"
        >
          <div class="mb-3 flex items-start justify-between gap-3">
            <div>
              <h3 class="text-sm font-semibold">{{ item.title }}</h3>
              <p class="mt-1 text-xs muted">{{ item.description }}</p>
            </div>
            <Flag class="mt-0.5 h-4 w-4 muted" />
          </div>

          <div class="mb-2 flex items-center justify-between text-xs muted">
            <span>
              <AnimatedNumber :value="item.current" :formatter="(value) => item.formatter(value)" />
            </span>
            <span>Target: {{ item.formatter(item.target) }}</span>
          </div>

          <div class="h-2.5 overflow-hidden rounded-full bg-[var(--panel-soft)]">
            <div
              class="h-full rounded-full transition-all duration-300 ease-out"
              :style="{
                width: `${progressPercent(item)}%`,
                background: `linear-gradient(90deg, ${item.accent}AA, ${item.accent})`,
              }"
            />
          </div>

          <p class="mt-2 text-right text-xs font-semibold muted">
            <AnimatedNumber :value="progressPercent(item)" :decimals="1" suffix="%" />
          </p>
        </article>
      </div>
    </GlassPanel>

    <GlassPanel>
      <div class="mb-4 flex items-center gap-2">
        <Award class="h-5 w-5 text-[var(--primary)]" />
        <h3 class="text-lg font-bold">Completed Badges</h3>
      </div>

      <div v-if="completedMilestones.length > 0" class="flex flex-wrap gap-3">
        <div
          v-for="item in completedMilestones"
          :key="`badge-${item.id}`"
          class="inline-flex items-center gap-2 rounded-2xl border px-4 py-2 text-sm font-semibold"
          :style="{
            borderColor: 'color-mix(in srgb, var(--primary) 48%, var(--border) 52%)',
            color: 'color-mix(in srgb, var(--primary) 80%, var(--text) 20%)',
            background: 'color-mix(in srgb, var(--primary-soft) 72%, var(--panel) 28%)',
          }"
        >
          <CheckCircle2 class="h-4 w-4" />
          {{ item.title }}
        </div>
      </div>
      <p v-else class="text-sm muted">
        No milestone completed yet. Keep executing your setup rules.
      </p>
    </GlassPanel>
  </div>
</template>
