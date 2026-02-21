<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import { AlertTriangle, BarChartHorizontalBig, CalendarPlus, NotebookPen, ShieldAlert } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import SummaryCards from '@/components/dashboard/SummaryCards.vue'
import EquityDrawdownStackedChart from '@/components/charts/EquityDrawdownStackedChart.vue'
import EmotionPieChart from '@/components/charts/EmotionPieChart.vue'
import SessionPerformanceBarChart from '@/components/charts/SessionPerformanceBarChart.vue'
import MonthlyHeatmap from '@/components/analytics/MonthlyHeatmap.vue'
import { asCurrency } from '@/utils/format'
import { useAccountStore } from '@/stores/accountStore'
import { useAnalyticsStore } from '@/stores/analyticsStore'

const analyticsStore = useAnalyticsStore()
const accountStore = useAccountStore()
const {
  summary,
  overview,
  equity,
  drawdown,
  drawdownSeries,
  rankings,
  behavioral,
  monthlyHeatmap,
  riskStatus,
  loading,
} = storeToRefs(analyticsStore)
const { accounts, selectedAccountId } = storeToRefs(accountStore)

const selectedScopeLabel = computed(() => {
  if (selectedAccountId.value === null) return 'All Accounts (Portfolio)'

  const account = accounts.value.find((item) => item.id === selectedAccountId.value)
  if (!account) return 'Selected Account'

  return `${account.name} - ${account.broker}`
})

const emotionSlices = computed(() => behavioral.value?.emotion_analytics?.breakdown ?? [])
const sessionRows = computed(() => rankings.value?.sessions ?? [])
const strategyRows = computed(() => rankings.value?.strategy_models ?? [])
const followedRules = computed(() => behavioral.value?.discipline_comparison?.followed_rules ?? null)
const brokeRules = computed(() => behavioral.value?.discipline_comparison?.broke_rules ?? null)

const hasRiskWarnings = computed(() => (riskStatus.value?.warnings?.length ?? 0) > 0)

const emotionChartRows = computed(() =>
  emotionSlices.value.map((item) => ({
    emotion: String((item as Record<string, unknown>).emotion ?? 'unknown'),
    total_trades: Number((item as Record<string, unknown>).total_trades ?? 0),
    total_profit: Number((item as Record<string, unknown>).total_profit ?? 0),
  }))
)

onMounted(async () => {
  await accountStore.fetchAccounts()
  await analyticsStore.fetchAnalytics()
})

watch(
  () => selectedAccountId.value,
  async () => {
    await analyticsStore.fetchAnalytics()
  }
)
</script>

<template>
  <Transition name="account-switch" mode="out-in">
    <div :key="selectedAccountId === null ? 'portfolio' : `account-${selectedAccountId}`" class="space-y-6 account-switch-surface">
    <section class="grid grid-premium xl:grid-cols-[1.1fr_2.9fr]">
      <GlassPanel>
        <div class="section-head">
          <h2 class="section-title">Quick Actions</h2>
        </div>
        <div class="panel mb-3 p-3">
          <p class="kicker-label">Account Scope</p>
          <p class="mt-1 text-sm font-semibold">{{ selectedScopeLabel }}</p>
        </div>
        <div class="space-y-2">
          <RouterLink to="/trades" class="btn btn-primary inline-flex w-full items-center gap-2 px-3 py-2 text-sm">
            <NotebookPen class="h-4 w-4" />
            New Trade Entry
          </RouterLink>
          <RouterLink to="/missed-trades" class="btn btn-ghost inline-flex w-full items-center gap-2 px-3 py-2 text-sm">
            <CalendarPlus class="h-4 w-4" />
            Log Missed Trade
          </RouterLink>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-3">
          <div class="panel p-3">
            <p class="kicker-label">Trades</p>
            <p class="mt-2 text-xl font-bold value-display">
              <AnimatedNumber :value="overview?.total_trades ?? 0" />
            </p>
          </div>
          <div class="panel p-3">
            <p class="kicker-label">Returns</p>
            <p class="mt-2 text-xl font-bold value-display positive">
              <AnimatedNumber :value="overview?.returns_percent ?? 0" :decimals="2" suffix="%" />
            </p>
          </div>
        </div>
      </GlassPanel>

      <div v-if="loading && !summary" class="grid grid-premium sm:grid-cols-2 xl:grid-cols-4">
        <SkeletonBlock v-for="row in 4" :key="`summary-skeleton-${row}`" height-class="h-28" rounded-class="rounded-2xl" />
      </div>
      <SummaryCards v-else :summary="summary" />
    </section>

    <GlassPanel v-if="hasRiskWarnings">
      <div class="section-head">
        <h2 class="section-title inline-flex items-center gap-2">
          <ShieldAlert class="h-5 w-5 text-[var(--danger)]" />
          Risk Status
        </h2>
      </div>
      <div class="flex flex-wrap gap-2">
        <span v-for="warning in riskStatus?.warnings ?? []" :key="warning" class="pill pill-negative">
          <AlertTriangle class="h-3.5 w-3.5" />
          {{ warning }}
        </span>
      </div>
    </GlassPanel>

    <section class="grid grid-premium xl:grid-cols-3">
      <GlassPanel class="xl:col-span-2">
        <div class="section-head">
          <h2 class="section-title">Equity Curve + Drawdown</h2>
          <p class="section-note">Auto-refreshes after every trade update</p>
        </div>
        <EquityDrawdownStackedChart
          :timestamps="equity?.equity_timestamps ?? []"
          :equity="equity?.equity_points ?? []"
          :drawdown="drawdownSeries"
        />
      </GlassPanel>

      <GlassPanel>
        <div class="section-head">
          <h2 class="section-title">Drawdown Snapshot</h2>
        </div>
        <div class="grid gap-3 text-sm">
          <div class="panel p-3">
            <p class="kicker-label">Max Drawdown</p>
            <p class="mt-1 text-xl font-semibold negative value-display">
              {{ asCurrency(drawdown?.max_drawdown ?? 0) }}
            </p>
          </div>
          <div class="panel p-3">
            <p class="kicker-label">Max Drawdown %</p>
            <p class="mt-1 text-xl font-semibold negative value-display">
              <AnimatedNumber :value="drawdown?.max_drawdown_percent ?? 0" :decimals="2" suffix="%" />
            </p>
          </div>
          <div class="panel p-3">
            <p class="kicker-label">Current Drawdown %</p>
            <p class="mt-1 text-xl font-semibold value-display" :class="(drawdown?.current_drawdown_percent ?? 0) > 10 ? 'negative' : 'muted'">
              <AnimatedNumber :value="drawdown?.current_drawdown_percent ?? 0" :decimals="2" suffix="%" />
            </p>
          </div>
        </div>
      </GlassPanel>
    </section>

    <section class="grid grid-premium xl:grid-cols-3">
      <GlassPanel>
        <div class="section-head">
          <h2 class="section-title">Discipline Comparison</h2>
        </div>
        <div class="grid gap-3 text-sm">
          <div class="panel p-3">
            <p class="kicker-label">Followed Rules Expectancy</p>
            <p class="mt-1 text-xl font-semibold positive value-display">
              <AnimatedNumber :value="Number(followedRules?.expectancy ?? 0)" :decimals="3" />
            </p>
          </div>
          <div class="panel p-3">
            <p class="kicker-label">Broke Rules Expectancy</p>
            <p class="mt-1 text-xl font-semibold value-display" :class="Number(brokeRules?.expectancy ?? 0) >= 0 ? 'positive' : 'negative'">
              <AnimatedNumber :value="Number(brokeRules?.expectancy ?? 0)" :decimals="3" />
            </p>
          </div>
          <p class="muted">{{ behavioral?.discipline_comparison?.insight?.when_follow_rules }}</p>
          <p class="muted">{{ behavioral?.discipline_comparison?.insight?.when_break_rules }}</p>
        </div>
      </GlassPanel>

      <GlassPanel>
        <div class="section-head">
          <h2 class="section-title">Emotion Breakdown</h2>
        </div>
        <EmotionPieChart :slices="emotionChartRows" />
      </GlassPanel>

      <GlassPanel>
        <div class="section-head">
          <h2 class="section-title">Session Performance</h2>
        </div>
        <SessionPerformanceBarChart :rows="sessionRows" />
      </GlassPanel>
    </section>

    <section class="grid grid-premium xl:grid-cols-[1.5fr_1.5fr]">
      <GlassPanel>
        <div class="section-head">
          <h2 class="section-title">Strategy Ranking</h2>
          <p class="section-note">Sorted by expectancy</p>
        </div>
        <div v-if="strategyRows.length === 0">
          <EmptyState title="No strategy data" description="Add trades to rank strategy models." :icon="BarChartHorizontalBig" />
        </div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Strategy Model</th>
                <th>Trades</th>
                <th>Win Rate</th>
                <th>Expectancy</th>
                <th>Total P&amp;L</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in strategyRows" :key="row.strategy_model || 'unknown'">
                <td class="font-semibold">{{ row.strategy_model || 'Unknown' }}</td>
                <td>{{ row.total_trades }}</td>
                <td>{{ Number(row.win_rate).toFixed(2) }}%</td>
                <td class="value-display" :class="Number(row.expectancy) >= 0 ? 'positive' : 'negative'">
                  {{ Number(row.expectancy).toFixed(3) }}
                </td>
                <td class="value-display" :class="Number(row.total_pnl) >= 0 ? 'positive' : 'negative'">
                  {{ asCurrency(Number(row.total_pnl)) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </GlassPanel>

      <GlassPanel>
        <div class="section-head">
          <h2 class="section-title">Monthly Heatmap</h2>
          <p class="section-note">Intensity tracks absolute daily P&amp;L</p>
        </div>
        <MonthlyHeatmap :months="monthlyHeatmap?.months ?? []" />
      </GlassPanel>
    </section>
    </div>
  </Transition>
</template>
