<script setup lang="ts">
import { onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { BarChartHorizontalBig } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import SummaryCards from '@/components/dashboard/SummaryCards.vue'
import CalendarHeatmap from '@/components/analytics/CalendarHeatmap.vue'
import EquityCurveLineChart from '@/components/charts/EquityCurveLineChart.vue'
import DailyPnlBarChart from '@/components/charts/DailyPnlBarChart.vue'
import RadarPerformanceChart from '@/components/charts/RadarPerformanceChart.vue'
import { useAnalyticsStore } from '@/stores/analyticsStore'

const analyticsStore = useAnalyticsStore()
const { summary, overview, dailyStats, performanceProfile, equityCurve, loading } = storeToRefs(analyticsStore)

onMounted(async () => {
  await analyticsStore.fetchAnalytics()
})
</script>

<template>
  <div class="space-y-6">
    <div v-if="loading && !summary" class="grid grid-premium sm:grid-cols-2 xl:grid-cols-4">
      <SkeletonBlock v-for="row in 4" :key="`summary-skeleton-${row}`" height-class="h-28" rounded-class="rounded-2xl" />
    </div>
    <SummaryCards v-else :summary="summary" />

    <GlassPanel v-if="!loading || dailyStats.length > 0">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="text-lg font-bold">Trading Calendar Heatmap</h2>
        <p class="text-sm text-slate-400">Daily trades and P&L intensity</p>
      </div>
      <CalendarHeatmap />
    </GlassPanel>

    <EmptyState
      v-else
      title="No analytics data yet"
      description="Add trades to unlock live dashboard insights."
      :icon="BarChartHorizontalBig"
    />

    <section class="grid grid-premium xl:grid-cols-3">
      <GlassPanel class="xl:col-span-2">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-lg font-bold">Equity Curve</h2>
          <p class="text-sm text-slate-400">{{ loading ? 'Refreshing...' : 'Live analytics snapshot' }}</p>
        </div>
        <EquityCurveLineChart :points="equityCurve" />
      </GlassPanel>

      <GlassPanel>
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-lg font-bold">Radar Performance</h2>
          <p class="text-sm text-slate-400">Normalized profile view</p>
        </div>
        <RadarPerformanceChart :profile="performanceProfile" />
      </GlassPanel>
    </section>

    <section class="grid grid-premium md:grid-cols-2">
      <GlassPanel>
        <h3 class="mb-3 text-base font-bold">Daily P&amp;L Bar Chart</h3>
        <DailyPnlBarChart :rows="dailyStats" />
      </GlassPanel>
      <GlassPanel>
        <h3 class="mb-3 text-base font-bold">Overview</h3>
        <div class="grid gap-3 text-sm">
          <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-3">
            <p class="text-slate-400">Total Trades</p>
            <p class="mt-1 text-xl font-semibold text-slate-100">
              <AnimatedNumber :value="overview?.total_trades ?? 0" />
            </p>
          </div>
          <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-3">
            <p class="text-slate-400">Returns %</p>
            <p class="mt-1 text-xl font-semibold text-emerald-400">
              <AnimatedNumber :value="overview?.returns_percent ?? 0" :decimals="2" suffix="%" />
            </p>
          </div>
          <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-3">
            <p class="text-slate-400">Profit Factor</p>
            <p class="mt-1 text-xl font-semibold text-slate-100">
              <AnimatedNumber v-if="overview?.profit_factor !== null" :value="overview?.profit_factor ?? 0" :decimals="2" />
              <span v-else>-</span>
            </p>
          </div>
        </div>
      </GlassPanel>
    </section>
  </div>
</template>
