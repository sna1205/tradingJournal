<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import {
  BarChartHorizontalBig,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  LayoutGrid,
  ListChecks,
  Plus,
  ShieldAlert,
  Wallet,
} from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import EquityDrawdownStackedChart from '@/components/charts/EquityDrawdownStackedChart.vue'
import EmotionPieChart from '@/components/charts/EmotionPieChart.vue'
import SessionPerformanceBarChart from '@/components/charts/SessionPerformanceBarChart.vue'
import CalendarHeatmap from '@/components/analytics/CalendarHeatmap.vue'
import DatePopoverField from '@/components/form/DatePopoverField.vue'
import BaseSelect from '@/components/form/BaseSelect.vue'
import api from '@/services/api'
import { queryLocalTrades, shouldUseLocalFallback } from '@/services/localFallback'
import { asCurrency, asSignedCurrency } from '@/utils/format'
import { useAccountStore } from '@/stores/accountStore'
import { useAnalyticsStore } from '@/stores/analyticsStore'
import type { Paginated, Trade } from '@/types/trade'

type DashboardTab = 'overview' | 'calendar'
type RangePreset = '30d' | 'custom'

const analyticsStore = useAnalyticsStore()
const accountStore = useAccountStore()
const {
  summary,
  overview,
  metrics,
  equity,
  drawdown,
  drawdownSeries,
  streaks,
  rankings,
  behavioral,
  loading,
} = storeToRefs(analyticsStore)
const { accounts, selectedAccount, selectedAccountId } = storeToRefs(accountStore)

const activeTab = ref<DashboardTab>('overview')
const rangePreset = ref<RangePreset>('30d')
const initialRangeEnd = getTodayIso()
const calendarMonthKey = ref(monthKeyFromDate(new Date()))
const customDateFrom = ref(shiftIsoDate(initialRangeEnd, -29))
const customDateTo = ref(initialRangeEnd)
const recentTrades = ref<Trade[]>([])
const recentTradesLoading = ref(false)
let recentTradesRefreshHandle: number | null = null

const totalStartingBalance = computed(() =>
  accounts.value.reduce((sum, account) => sum + toNumber(account.starting_balance), 0)
)

const totalCurrentBalance = computed(() =>
  accounts.value.reduce((sum, account) => sum + toNumber(account.current_balance), 0)
)

const scopeBalance = computed(() => {
  if (selectedAccountId.value === null) {
    if (accounts.value.length === 0) return toNumber(drawdown.value?.current_equity)
    return totalCurrentBalance.value
  }

  return toNumber(selectedAccount.value?.current_balance)
})

const scopeNetPnl = computed(() => {
  if (selectedAccountId.value === null) {
    if (accounts.value.length === 0) return toNumber(summary.value?.total_pnl)
    return totalCurrentBalance.value - totalStartingBalance.value
  }

  const current = toNumber(selectedAccount.value?.current_balance)
  const starting = toNumber(selectedAccount.value?.starting_balance)
  return current - starting
})

const scopeLabel = computed(() => {
  if (selectedAccountId.value === null) return 'All Accounts'
  if (!selectedAccount.value) return 'Selected Account'
  return selectedAccount.value.name
})

const scopeMetaLabel = computed(() => {
  if (selectedAccountId.value === null) {
    return `${accounts.value.length} accounts`
  }

  if (!selectedAccount.value) return 'Account'
  return `${selectedAccount.value.broker} | ${selectedAccount.value.account_type}`
})

const accountScopeOptions = computed(() => [
  {
    label: 'All Accounts (Portfolio)',
    value: '',
    subtitle: 'Aggregate analytics',
    badge: 'portfolio',
  },
  ...accounts.value.map((account) => ({
    label: account.name,
    value: String(account.id),
    subtitle: `${account.broker} - ${account.currency} ${Number(account.current_balance).toLocaleString()}${account.is_active ? '' : ' - inactive'}`,
    badge: account.account_type,
  })),
])

const selectedAccountScopeModel = computed({
  get: () => (selectedAccountId.value === null ? '' : String(selectedAccountId.value)),
  set: (value: string) => {
    if (!value) {
      accountStore.setSelectedAccountId(null)
      return
    }

    accountStore.setSelectedAccountId(Number(value))
  },
})

const activeRangeFilters = computed(() => {
  if (rangePreset.value === '30d') {
    const end = getTodayIso()
    const start = shiftIsoDate(end, -29)
    return {
      date_from: start,
      date_to: end,
    }
  }

  return sortDateRange(customDateFrom.value, customDateTo.value)
})

const periodTradeCount = computed(() => toNumber(overview.value?.total_trades))
const calendarMonthOptions = computed(() => {
  const anchor = new Date()
  const options: Array<{ label: string; value: string }> = []

  for (let offset = 12; offset >= -36; offset -= 1) {
    const date = new Date(anchor.getFullYear(), anchor.getMonth() + offset, 1)
    const value = monthKeyFromDate(date)
    options.push({
      value,
      label: formatMonthLabelFromKey(value),
    })
  }

  return options
})
const calendarMonthOptionValues = computed(() => new Set(calendarMonthOptions.value.map((item) => item.value)))
const netPnl = computed(() => toNumber(summary.value?.total_pnl))
const winRate = computed(() => toNumber(overview.value?.win_rate))
const avgR = computed(() => toNumber(metrics.value?.avg_r || metrics.value?.average_r))
const expectancy = computed(() => toNumber(metrics.value?.expectancy || summary.value?.expectancy))
const profitFactor = computed<number | null>(() => {
  const value = metrics.value?.profit_factor
  if (value === undefined || value === null) return null
  return Number(value)
})
const longestWinStreak = computed(() => toNumber(streaks.value?.longest_win_streak))
const maxDrawdown = computed(() => toNumber(drawdown.value?.max_drawdown))
const avgLoss = computed(() => Math.abs(toNumber(metrics.value?.average_loss)))
const currentDrawdownPct = computed(() => toNumber(drawdown.value?.current_drawdown_percent))
const avgPnlPerExecution = computed(() => {
  const trades = periodTradeCount.value
  if (trades <= 0) return 0
  return netPnl.value / trades
})
const emotionSlices = computed(() => behavioral.value?.emotion_analytics?.breakdown ?? [])
const sessionRows = computed(() => rankings.value?.sessions ?? [])
const strategyRows = computed(() => rankings.value?.strategy_models ?? [])
const followedRules = computed(() => behavioral.value?.discipline_comparison?.followed_rules ?? null)
const brokeRules = computed(() => behavioral.value?.discipline_comparison?.broke_rules ?? null)
const emotionChartRows = computed(() =>
  emotionSlices.value.map((item) => ({
    emotion: String((item as Record<string, unknown>).emotion ?? 'unknown'),
    total_trades: Number((item as Record<string, unknown>).total_trades ?? 0),
    total_profit: Number((item as Record<string, unknown>).total_profit ?? 0),
  }))
)

const reviewCandidates = computed(() => recentTrades.value.filter((trade) => needsReview(trade)))
const nextReviewTrade = computed(() => reviewCandidates.value[0] ?? null)
const recentTradeRows = computed(() => recentTrades.value.slice(0, 6))

onMounted(async () => {
  await accountStore.fetchAccounts()
  await refreshDashboardData()
  startRecentTradesAutoRefresh()
  window.addEventListener('focus', handleVisibilityOrFocus)
  document.addEventListener('visibilitychange', handleVisibilityOrFocus)
})

onBeforeUnmount(() => {
  stopRecentTradesAutoRefresh()
  window.removeEventListener('focus', handleVisibilityOrFocus)
  document.removeEventListener('visibilitychange', handleVisibilityOrFocus)
})

watch(
  [
    () => selectedAccountId.value,
    () => rangePreset.value,
    () => customDateFrom.value,
    () => customDateTo.value,
  ],
  async () => {
    await refreshDashboardData()
  }
)

watch(
  () => calendarMonthOptions.value,
  (options) => {
    if (options.length === 0) return
    if (calendarMonthOptionValues.value.has(calendarMonthKey.value)) return
    calendarMonthKey.value = options[0]?.value ?? monthKeyFromDate(new Date())
  },
  { immediate: true }
)

async function refreshDashboardData() {
  const filters = activeRangeFilters.value
  await Promise.allSettled([
    analyticsStore.fetchAnalytics(filters),
    fetchRecentTrades(filters),
  ])
}

async function fetchRecentTrades(filters: { date_from?: string; date_to?: string } = {}) {
  recentTradesLoading.value = true
  try {
    const params: Record<string, number | string> = {
      page: 1,
      per_page: 40,
    }

    if (selectedAccountId.value !== null) {
      params.account_id = selectedAccountId.value
    }

    if (filters.date_from) {
      params.date_from = filters.date_from
    }

    if (filters.date_to) {
      params.date_to = filters.date_to
    }

    const { data } = await api.get<Paginated<Trade>>('/trades', { params })
    let rows = data.data ?? []

    if (selectedAccountId.value !== null) {
      rows = rows.filter((trade) => trade.account_id === selectedAccountId.value)
    }

    rows.sort((left, right) => {
      const leftTime = new Date(left.date).getTime()
      const rightTime = new Date(right.date).getTime()
      return rightTime - leftTime
    })

    recentTrades.value = rows
  } catch (error) {
    if (!shouldUseLocalFallback(error)) {
      throw error
    }

    const local = queryLocalTrades({
      page: 1,
      per_page: 40,
      account_id: selectedAccountId.value ?? undefined,
      date_from: filters.date_from,
      date_to: filters.date_to,
    })
    recentTrades.value = local.data
  } finally {
    recentTradesLoading.value = false
  }
}

function startRecentTradesAutoRefresh() {
  stopRecentTradesAutoRefresh()
  recentTradesRefreshHandle = window.setInterval(() => {
    void fetchRecentTrades(activeRangeFilters.value)
  }, 45000)
}

function stopRecentTradesAutoRefresh() {
  if (recentTradesRefreshHandle === null) return
  window.clearInterval(recentTradesRefreshHandle)
  recentTradesRefreshHandle = null
}

function handleVisibilityOrFocus() {
  if (document.visibilityState && document.visibilityState !== 'visible') return
  void fetchRecentTrades(activeRangeFilters.value)
}

function getTodayIso() {
  return toIsoDate(new Date())
}

function toIsoDate(value: Date) {
  const year = value.getFullYear()
  const month = `${value.getMonth() + 1}`.padStart(2, '0')
  const day = `${value.getDate()}`.padStart(2, '0')
  return `${year}-${month}-${day}`
}

function shiftIsoDate(isoDate: string, dayDelta: number) {
  const parsed = new Date(`${isoDate}T00:00:00`)
  if (Number.isNaN(parsed.getTime())) return isoDate
  parsed.setDate(parsed.getDate() + dayDelta)
  return toIsoDate(parsed)
}

function monthKeyFromDate(value: Date) {
  return `${value.getFullYear()}-${`${value.getMonth() + 1}`.padStart(2, '0')}`
}

function shiftMonthKey(monthKey: string, monthDelta: number) {
  const matched = monthKey.match(/^(\d{4})-(\d{2})$/)
  if (!matched) return monthKeyFromDate(new Date())

  const year = Number(matched[1])
  const monthIndex = Number(matched[2]) - 1
  const target = new Date(year, monthIndex + monthDelta, 1)
  return monthKeyFromDate(target)
}

function formatMonthLabelFromKey(monthKey: string) {
  const matched = monthKey.match(/^(\d{4})-(\d{2})$/)
  if (!matched) return monthKey
  const year = Number(matched[1])
  const month = Number(matched[2])
  const parsed = new Date(year, month - 1, 1)
  if (Number.isNaN(parsed.getTime())) return monthKey
  return parsed.toLocaleDateString('en-US', {
    month: 'long',
    year: 'numeric',
  })
}

function selectPreviousCalendarMonth() {
  const previousKey = shiftMonthKey(calendarMonthKey.value, -1)
  if (!calendarMonthOptionValues.value.has(previousKey)) return
  calendarMonthKey.value = previousKey
}

function selectNextCalendarMonth() {
  const nextKey = shiftMonthKey(calendarMonthKey.value, 1)
  if (!calendarMonthOptionValues.value.has(nextKey)) return
  calendarMonthKey.value = nextKey
}

function sortDateRange(dateFrom: string, dateTo: string) {
  if (dateFrom && dateTo && dateFrom > dateTo) {
    return {
      date_from: dateTo,
      date_to: dateFrom,
    }
  }

  return {
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
  }
}

function toNumber(value: unknown): number {
  const parsed = Number(value ?? 0)
  return Number.isFinite(parsed) ? parsed : 0
}

function needsReview(trade: Trade): boolean {
  const note = (trade.notes ?? '').trim()
  const imageCount = toNumber(trade.images_count ?? trade.images?.length ?? 0)
  return note.length < 8 || imageCount === 0
}

function tradePnlClass(value: number): string {
  if (value > 0) return 'positive'
  if (value < 0) return 'negative'
  return 'muted'
}

function shortDate(value: string): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return parsed.toLocaleDateString('en-US', {
    month: 'short',
    day: '2-digit',
    year: 'numeric',
  })
}
</script>

<template>
  <Transition name="account-switch" mode="out-in">
    <div :key="selectedAccountId === null ? 'portfolio' : `account-${selectedAccountId}`" class="space-y-4 dashboard-overview-shell dashboard-minimal">
      <section class="overview-top-row">
        <div class="overview-heading-block">
          <h1 class="overview-heading-title">Overview</h1>
        </div>

        <div class="overview-controls-wrap">
          <div class="overview-tab-switch">
            <button class="overview-tab-btn" :class="{ active: activeTab === 'overview' }" @click="activeTab = 'overview'">
              Overview
            </button>
            <button class="overview-tab-btn" :class="{ active: activeTab === 'calendar' }" @click="activeTab = 'calendar'">
              Calendar
            </button>
          </div>

          <div class="overview-range-group">
            <button class="btn overview-range-btn" :class="{ active: rangePreset === '30d' }" @click="rangePreset = '30d'">
              <CalendarDays class="h-4 w-4" />
              30 Days
            </button>
            <button class="btn overview-range-btn" :class="{ active: rangePreset === 'custom' }" @click="rangePreset = 'custom'">
              <LayoutGrid class="h-4 w-4" />
              Custom
            </button>
          </div>

          <div v-if="rangePreset === 'custom'" class="overview-custom-range">
            <DatePopoverField v-model="customDateFrom" size="sm" placeholder="From" />
            <DatePopoverField v-model="customDateTo" size="sm" placeholder="To" />
          </div>
        </div>
      </section>

      <section class="panel overview-account-strip">
        <div class="overview-account-left">
          <span class="overview-account-icon">
            <Wallet class="h-4 w-4" />
          </span>

          <div class="overview-account-copy">
            <strong>{{ scopeLabel }}</strong>
            <span>{{ scopeMetaLabel }}</span>
          </div>

          <div class="overview-account-metrics">
            <p class="overview-account-stat">
              <span>Balance</span>
              <strong class="value-display">{{ asCurrency(scopeBalance) }}</strong>
            </p>
            <p class="overview-account-stat">
              <span>P/L</span>
              <strong class="value-display" :class="tradePnlClass(scopeNetPnl)">{{ asSignedCurrency(scopeNetPnl) }}</strong>
            </p>
          </div>
        </div>

        <div class="overview-account-right">
          <div class="overview-account-selector">
            <BaseSelect
              v-model="selectedAccountScopeModel"
              label="View Account"
              searchable
              search-placeholder="Search account..."
              :options="accountScopeOptions"
              size="sm"
            />
          </div>
          <RouterLink to="/accounts" class="overview-account-link">
            View accounts
            <ChevronRight class="h-4 w-4" />
          </RouterLink>
        </div>
      </section>

      <template v-if="activeTab === 'overview'">
        <section v-if="loading && !summary" class="grid grid-premium lg:grid-cols-4">
          <SkeletonBlock v-for="index in 4" :key="`dashboard-kpi-skeleton-${index}`" height-class="h-52" rounded-class="rounded-2xl" />
        </section>

        <section v-else class="overview-kpi-grid">
          <GlassPanel class="overview-kpi-card overview-kpi-card-performance">
            <header class="overview-kpi-head">
              <span class="overview-kpi-icon">
                <BarChartHorizontalBig class="h-4 w-4" />
              </span>
              <h2>Performance</h2>
            </header>
            <p class="overview-kpi-value value-display" :class="tradePnlClass(netPnl)">
              <AnimatedNumber :value="netPnl" :decimals="2" prefix="$" :sign="true" />
            </p>
            <p class="overview-kpi-caption">Net P/L</p>
            <div class="overview-kpi-split">
              <article>
                <strong class="value-display" :class="tradePnlClass(expectancy)">
                  <AnimatedNumber :value="expectancy" :decimals="2" prefix="$" :sign="true" />
                </strong>
                <span>Expectancy</span>
              </article>
              <article>
                <strong class="value-display" :class="profitFactor !== null && profitFactor < 1 ? 'negative' : ''">
                  {{ profitFactor === null ? '-' : Number(profitFactor).toFixed(2) }}
                </strong>
                <span>Profit Factor</span>
              </article>
            </div>
          </GlassPanel>

          <GlassPanel class="overview-kpi-card overview-kpi-card-consistency">
            <header class="overview-kpi-head">
              <span class="overview-kpi-icon">
                <ListChecks class="h-4 w-4" />
              </span>
              <h2>Consistency</h2>
            </header>
            <p class="overview-kpi-value value-display">
              <AnimatedNumber :value="winRate" :decimals="1" suffix="%" />
            </p>
            <p class="overview-kpi-caption">Win Rate</p>
            <div class="overview-kpi-split">
              <article>
                <strong class="value-display">
                  <AnimatedNumber :value="avgR" :decimals="2" suffix="R" />
                </strong>
                <span>Avg R:R</span>
              </article>
              <article>
                <strong class="value-display">
                  <AnimatedNumber :value="longestWinStreak" />
                </strong>
                <span>Longest Win Streak</span>
              </article>
            </div>
          </GlassPanel>

          <GlassPanel class="overview-kpi-card overview-kpi-card-risk">
            <header class="overview-kpi-head">
              <span class="overview-kpi-icon">
                <ShieldAlert class="h-4 w-4" />
              </span>
              <h2>Risk</h2>
            </header>
            <p class="overview-kpi-value value-display">{{ asCurrency(maxDrawdown) }}</p>
            <p class="overview-kpi-caption">Max Drawdown</p>
            <div class="overview-kpi-split">
              <article>
                <strong class="value-display negative">{{ asCurrency(avgLoss) }}</strong>
                <span>Avg Loss</span>
              </article>
              <article>
                <strong class="value-display" :class="currentDrawdownPct > 10 ? 'negative' : 'muted'">
                  <AnimatedNumber :value="currentDrawdownPct" :decimals="2" suffix="%" />
                </strong>
                <span>Current DD</span>
              </article>
            </div>
          </GlassPanel>

          <GlassPanel class="overview-kpi-card overview-kpi-card-execution">
            <header class="overview-kpi-head">
              <span class="overview-kpi-icon">
                <Wallet class="h-4 w-4" />
              </span>
              <h2>Execution</h2>
            </header>
            <p class="overview-kpi-value value-display">
              <AnimatedNumber :value="periodTradeCount" />
            </p>
            <p class="overview-kpi-caption">Closed Executions</p>
            <div class="overview-kpi-split">
              <article>
                <strong class="value-display" :class="tradePnlClass(avgPnlPerExecution)">
                  <AnimatedNumber :value="avgPnlPerExecution" :decimals="2" prefix="$" :sign="true" />
                </strong>
                <span>Avg P/L Per Execution</span>
              </article>
              <article>
                <strong class="value-display">
                  <AnimatedNumber :value="avgR" :decimals="2" suffix="R" />
                </strong>
                <span>Average R</span>
              </article>
            </div>
          </GlassPanel>
        </section>

        <section class="grid grid-premium">
          <GlassPanel>
            <div class="section-head">
              <h2 class="section-title">Cumulative P&amp;L</h2>
              <p class="section-note">Your equity curve - is your edge compounding over time?</p>
            </div>
            <EquityDrawdownStackedChart
              :timestamps="equity?.equity_timestamps ?? []"
              :equity="equity?.equity_points ?? []"
              :drawdown="drawdownSeries"
            />
          </GlassPanel>
        </section>

        <section class="grid grid-premium xl:grid-cols-2">
          <GlassPanel>
            <div class="section-head">
              <h2 class="section-title">Emotional Impact</h2>
            </div>
            <EmotionPieChart :slices="emotionChartRows" />
          </GlassPanel>

          <GlassPanel>
            <div class="section-head">
              <h2 class="section-title">Session Edge</h2>
            </div>
            <SessionPerformanceBarChart :rows="sessionRows" />
          </GlassPanel>
        </section>

        <section class="grid grid-premium xl:grid-cols-2">
          <GlassPanel>
            <div class="section-head">
              <h2 class="section-title">Discipline Impact</h2>
            </div>
            <div class="grid gap-3 text-sm">
              <div class="panel p-3">
                <p class="kicker-label">Followed Rules Expectancy</p>
                <p class="mt-1 text-xl font-semibold positive value-display">
                  <AnimatedNumber :value="Number(followedRules?.expectancy ?? 0)" :decimals="3" />
                </p>
              </div>
              <div class="panel p-3">
                <p class="kicker-label">Broken Rules Expectancy</p>
                <p
                  class="mt-1 text-xl font-semibold value-display"
                  :class="Number(brokeRules?.expectancy ?? 0) >= 0 ? 'positive' : 'negative'"
                >
                  <AnimatedNumber :value="Number(brokeRules?.expectancy ?? 0)" :decimals="3" />
                </p>
              </div>
              <p class="muted">{{ behavioral?.discipline_comparison?.insight?.when_follow_rules }}</p>
              <p class="muted">{{ behavioral?.discipline_comparison?.insight?.when_break_rules }}</p>
            </div>
          </GlassPanel>

          <GlassPanel>
            <div class="section-head">
              <h2 class="section-title">Risk Summary</h2>
            </div>
            <div class="grid gap-3 text-sm">
              <div class="panel p-3">
                <p class="kicker-label">Max Drawdown</p>
                <p class="mt-1 text-xl font-semibold negative value-display">
                  {{ asCurrency(maxDrawdown) }}
                </p>
              </div>
              <div class="panel p-3">
                <p class="kicker-label">Current Drawdown %</p>
                <p
                  class="mt-1 text-xl font-semibold value-display"
                  :class="currentDrawdownPct > 10 ? 'negative' : 'muted'"
                >
                  <AnimatedNumber :value="currentDrawdownPct" :decimals="2" suffix="%" />
                </p>
              </div>
            </div>
          </GlassPanel>
        </section>

        <section class="grid grid-premium">
          <GlassPanel>
            <div class="section-head">
              <h2 class="section-title">Strategy Leaderboard</h2>
              <p class="section-note">Ranked by expectancy</p>
            </div>
            <div v-if="strategyRows.length === 0">
              <EmptyState title="No strategy data" description="Add executions to rank strategy models." :icon="BarChartHorizontalBig" />
            </div>
            <div v-else class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Strategy Model</th>
                    <th>Executions</th>
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
        </section>
      </template>

      <template v-else>
        <section class="overview-calendar-grid">
          <GlassPanel class="overview-calendar-panel">
            <div class="section-head">
              <h2 class="section-title">Calendar Overview</h2>
              <div class="overview-calendar-controls">
                <button type="button" class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="selectPreviousCalendarMonth">
                  <ChevronLeft class="h-4 w-4" />
                  Previous Month
                </button>
                <div class="overview-calendar-month-select">
                  <BaseSelect
                    v-model="calendarMonthKey"
                    label="Month"
                    size="sm"
                    :options="calendarMonthOptions"
                  />
                </div>
                <button type="button" class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="selectNextCalendarMonth">
                  Next Month
                  <ChevronRight class="h-4 w-4" />
                </button>
              </div>
            </div>
            <CalendarHeatmap :month-key="calendarMonthKey" />
          </GlassPanel>

          <aside class="overview-calendar-side">
            <GlassPanel>
              <div class="section-head">
                <h2 class="section-title">To Review</h2>
              </div>

              <div v-if="recentTradesLoading">
                <SkeletonBlock height-class="h-20" rounded-class="rounded-2xl" />
              </div>

              <div v-else-if="nextReviewTrade" class="overview-review-card">
                <p class="overview-review-symbol">{{ nextReviewTrade.pair }}</p>
                <p class="muted">{{ shortDate(nextReviewTrade.date) }}</p>
                <RouterLink to="/trades" class="btn btn-ghost mt-3 inline-flex items-center gap-2 px-3 py-2 text-sm">
                  Review
                </RouterLink>
              </div>

              <EmptyState v-else title="Nothing pending" description="All recent executions are reviewed." :icon="ShieldAlert" />
            </GlassPanel>

            <GlassPanel>
              <div class="section-head">
                <h2 class="section-title">Recent Executions</h2>
              </div>

              <div v-if="recentTradesLoading" class="grid gap-2">
                <SkeletonBlock v-for="index in 4" :key="`recent-execution-skeleton-${index}`" height-class="h-12" rounded-class="rounded-xl" />
              </div>

              <ul v-else-if="recentTradeRows.length > 0" class="overview-recent-list">
                <li v-for="trade in recentTradeRows" :key="trade.id" class="overview-recent-row">
                  <div>
                    <p class="overview-recent-symbol">{{ trade.pair }}</p>
                    <p class="muted">{{ shortDate(trade.date) }}</p>
                  </div>
                  <p class="value-display" :class="tradePnlClass(Number(trade.profit_loss))">
                    {{ asSignedCurrency(trade.profit_loss) }}
                  </p>
                </li>
              </ul>

              <EmptyState v-else title="No executions yet" description="Create a new execution to populate recent activity." :icon="BarChartHorizontalBig" />
            </GlassPanel>
          </aside>

          <RouterLink class="overview-calendar-fab" to="/trades/new" title="New execution">
            <Plus class="h-7 w-7" />
          </RouterLink>
        </section>
      </template>
    </div>
  </Transition>
</template>
