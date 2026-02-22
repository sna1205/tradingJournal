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
import { queryLocalMissedTrades, queryLocalTrades, shouldUseLocalFallback } from '@/services/localFallback'
import { asCurrency, asSignedCurrency } from '@/utils/format'
import { useAccountStore } from '@/stores/accountStore'
import { useAnalyticsStore } from '@/stores/analyticsStore'
import { useSyncStatusStore } from '@/stores/syncStatusStore'
import {
  isLiveAccountType,
  isPropAccountType,
  type AccountChallengeStatusPayload,
} from '@/types/account'
import type { MissedTrade, Paginated, Trade } from '@/types/trade'

type DashboardTab = 'overview' | 'chart' | 'calendar'
type RangePreset = '30d' | 'custom'
type DashboardMode = 'live' | 'prop'

const analyticsStore = useAnalyticsStore()
const accountStore = useAccountStore()
const syncStatusStore = useSyncStatusStore()
const {
  summary,
  overview,
  metrics,
  equity,
  drawdown,
  drawdownSeries,
  rankings,
  behavioral,
  loading,
} = storeToRefs(analyticsStore)
const { accounts, selectedAccount, selectedAccountId } = storeToRefs(accountStore)

const activeTab = ref<DashboardTab>('overview')
const dashboardMode = ref<DashboardMode>('live')
const tabMounted = ref<Record<DashboardTab, boolean>>({
  overview: true,
  chart: false,
  calendar: false,
})
const rangePreset = ref<RangePreset>('30d')
const initialRangeEnd = getTodayIso()
const calendarMonthKey = ref(monthKeyFromDate(new Date()))
const customDateFrom = ref(shiftIsoDate(initialRangeEnd, -29))
const customDateTo = ref(initialRangeEnd)
const recentTrades = ref<Trade[]>([])
const recentMissedTrades = ref<MissedTrade[]>([])
const recentTradesLoading = ref(false)
const recentMissedTradesLoading = ref(false)
const challengeStatus = ref<AccountChallengeStatusPayload | null>(null)
const challengeStatusLoading = ref(false)
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

const propAccounts = computed(() => accounts.value.filter((account) => isPropAccountType(account.account_type)))
const liveAccounts = computed(() => accounts.value.filter((account) => isLiveAccountType(account.account_type)))

const hasPropAccounts = computed(() => propAccounts.value.length > 0)
const hasLiveAccounts = computed(() => liveAccounts.value.length > 0)

const modeScopeAccounts = computed(() => {
  if (effectiveDashboardMode.value === 'prop') {
    return propAccounts.value
  }

  // Live mode intentionally scopes to personal accounts only.
  return liveAccounts.value
})

const accountScopeOptions = computed(() =>
  modeScopeAccounts.value.map((account) => ({
    label: account.name,
    value: String(account.id),
    subtitle: `${account.broker} - ${account.currency} ${Number(account.current_balance).toLocaleString()}${account.is_active ? '' : ' - inactive'}`,
    badge: account.account_type,
  }))
)

const selectedAccountScopeModel = computed({
  get: () => {
    if (selectedAccountId.value !== null) {
      return String(selectedAccountId.value)
    }

    return accountScopeOptions.value[0]?.value ?? ''
  },
  set: (value: string) => {
    if (!value) {
      return
    }

    accountStore.setSelectedAccountId(Number(value))
  },
})

const effectiveDashboardMode = computed<DashboardMode>(() => {
  if (dashboardMode.value === 'prop' && hasPropAccounts.value) {
    return 'prop'
  }

  if (dashboardMode.value === 'live' && hasLiveAccounts.value) {
    return 'live'
  }

  if (hasPropAccounts.value) {
    return 'prop'
  }

  return 'live'
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
const avgRRealized = computed(() =>
  toNumber(metrics.value?.avg_r_realized || metrics.value?.avg_r || metrics.value?.average_r)
)
const avgRrPlanned = computed(() => toNumber(metrics.value?.avg_rr_planned))
const expectancyMoney = computed(() =>
  toNumber(metrics.value?.expectancy_money || metrics.value?.expectancy || summary.value?.expectancy)
)
const expectancyR = computed(() => toNumber(metrics.value?.expectancy_r))
const payoffRatio = computed<number | null>(() => {
  const value = metrics.value?.payoff_ratio
  if (value === undefined || value === null) return null
  return Number(value)
})
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
const chartEmotionRows = computed(() => emotionChartRows.value)
const chartSessionRows = computed(() => sessionRows.value)
const hasChartData = computed(() => chartEmotionRows.value.length > 0 || chartSessionRows.value.length > 0)
const challengeRiskStateLabel = computed(() => {
  if (!challengeStatus.value) return 'No status'
  if (challengeStatus.value.risk_state === 'pass') return 'Pass'
  if (challengeStatus.value.risk_state === 'fail') return 'Fail'
  return 'In Progress'
})
const challengeRiskStateClass = computed(() => {
  if (!challengeStatus.value) return 'muted'
  if (challengeStatus.value.risk_state === 'pass') return 'positive'
  if (challengeStatus.value.risk_state === 'fail') return 'negative'
  return 'muted'
})

const reviewCandidates = computed(() => recentTrades.value.filter((trade) => needsReview(trade)))
const nextReviewTrade = computed(() => reviewCandidates.value[0] ?? null)
const recentTradeRows = computed(() => recentTrades.value.slice(0, 6))
const weeklyTrades = computed(() => recentTrades.value.filter((trade) => isWithinLastDays(trade.date, 7)))
const weeklyMissedRows = computed(() => recentMissedTrades.value.filter((entry) => isWithinLastDays(entry.date, 7)))
const weeklyReviewVolume = computed(() => weeklyTrades.value.length + weeklyMissedRows.value.length)
const weeklyCaptureCompleteCount = computed(() => {
  const tradeCaptures = weeklyTrades.value.filter((trade) => tradeCaptureReady(trade)).length
  const missedCaptures = weeklyMissedRows.value.filter((entry) => missedCaptureReady(entry)).length
  return tradeCaptures + missedCaptures
})
const weeklyCaptureRate = computed(() => toPercent(weeklyCaptureCompleteCount.value, weeklyReviewVolume.value))
const weeklyTriageQueueCount = computed(() => {
  const tradeQueue = weeklyTrades.value.filter((trade) => needsReview(trade)).length
  const missedQueue = weeklyMissedRows.value.filter((entry) => needsMissedRecovery(entry)).length
  return tradeQueue + missedQueue
})
const weeklyActionPlanCount = computed(() => {
  const tradePlans = weeklyTrades.value.filter((trade) => hasActionPlan(trade.notes)).length
  const missedPlans = weeklyMissedRows.value.filter((entry) => hasActionPlan(entry.notes)).length
  return tradePlans + missedPlans
})
const weeklyActionPlanRate = computed(() => toPercent(weeklyActionPlanCount.value, weeklyReviewVolume.value))
const weeklyFollowUpDoneCount = computed(() => {
  const tradeFollowUp = weeklyTrades.value.filter((trade) => hasFollowUpNote(trade.notes)).length
  const missedFollowUp = weeklyMissedRows.value.filter((entry) => hasFollowUpNote(entry.notes)).length
  return tradeFollowUp + missedFollowUp
})
const weeklyFollowUpPendingCount = computed(() => Math.max(weeklyActionPlanCount.value - weeklyFollowUpDoneCount.value, 0))
const weeklyLoopLoading = computed(() => recentTradesLoading.value || recentMissedTradesLoading.value)

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
  () => activeTab.value,
  (tab) => {
    tabMounted.value[tab] = true
  }
)

watch(
  [() => effectiveDashboardMode.value, () => accounts.value, () => selectedAccountId.value],
  () => {
    const scoped = modeScopeAccounts.value
    if (scoped.length === 0) {
      if (selectedAccountId.value !== null) {
        accountStore.setSelectedAccountId(null)
      }
      return
    }

    const currentId = selectedAccountId.value
    const hasCurrent = currentId !== null && scoped.some((account) => account.id === currentId)
    if (!hasCurrent) {
      accountStore.setSelectedAccountId(scoped[0]!.id)
    }
  },
  { immediate: true }
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
    fetchChallengeStatus(),
    fetchRecentTrades(filters),
    fetchRecentMissedTrades(filters),
  ])
}

async function fetchChallengeStatus() {
  if (effectiveDashboardMode.value !== 'prop' || selectedAccountId.value === null) {
    challengeStatus.value = null
    challengeStatusLoading.value = false
    return
  }

  challengeStatusLoading.value = true
  try {
    challengeStatus.value = await accountStore.fetchAccountChallengeStatus(selectedAccountId.value)
  } catch {
    challengeStatus.value = null
  } finally {
    challengeStatusLoading.value = false
  }
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
    syncStatusStore.markServerHealthy()
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
    syncStatusStore.markLocalFallback('dashboard-recent-trades')

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

async function fetchRecentMissedTrades(filters: { date_from?: string; date_to?: string } = {}) {
  recentMissedTradesLoading.value = true
  try {
    const params: Record<string, number | string> = {
      page: 1,
      per_page: 40,
    }

    if (filters.date_from) {
      params.date_from = filters.date_from
    }

    if (filters.date_to) {
      params.date_to = filters.date_to
    }

    const { data } = await api.get<Paginated<MissedTrade>>('/missed-trades', { params })
    syncStatusStore.markServerHealthy()
    const rows = (data.data ?? []).slice()

    rows.sort((left, right) => {
      const leftTime = new Date(left.date).getTime()
      const rightTime = new Date(right.date).getTime()
      return rightTime - leftTime
    })

    recentMissedTrades.value = rows
  } catch (error) {
    if (!shouldUseLocalFallback(error)) {
      throw error
    }
    syncStatusStore.markLocalFallback('dashboard-recent-missed-trades')

    const local = queryLocalMissedTrades({
      page: 1,
      per_page: 40,
      date_from: filters.date_from,
      date_to: filters.date_to,
    })
    recentMissedTrades.value = local.data
  } finally {
    recentMissedTradesLoading.value = false
  }
}

function startRecentTradesAutoRefresh() {
  stopRecentTradesAutoRefresh()
  recentTradesRefreshHandle = window.setInterval(() => {
    void Promise.allSettled([
      fetchRecentTrades(activeRangeFilters.value),
      fetchRecentMissedTrades(activeRangeFilters.value),
    ])
  }, 45000)
}

function stopRecentTradesAutoRefresh() {
  if (recentTradesRefreshHandle === null) return
  window.clearInterval(recentTradesRefreshHandle)
  recentTradesRefreshHandle = null
}

function handleVisibilityOrFocus() {
  if (document.visibilityState && document.visibilityState !== 'visible') return
  void Promise.allSettled([
    fetchRecentTrades(activeRangeFilters.value),
    fetchRecentMissedTrades(activeRangeFilters.value),
  ])
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

function toPercent(partial: number, total: number): number {
  if (total <= 0) return 0
  return (partial / total) * 100
}

function isWithinLastDays(value: string, days: number): boolean {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return false

  const now = new Date()
  const start = new Date(now)
  start.setHours(0, 0, 0, 0)
  start.setDate(start.getDate() - (days - 1))
  return parsed.getTime() >= start.getTime()
}

function parseTags(reason: string): string[] {
  return reason
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)
}

function imageCount(trade: Trade): number {
  return toNumber(trade.images_count ?? trade.images?.length ?? 0)
}

function tradeCaptureReady(trade: Trade): boolean {
  return imageCount(trade) > 0 || (trade.notes ?? '').trim().length >= 12
}

function missedCaptureReady(entry: MissedTrade): boolean {
  const images = toNumber(entry.images_count ?? entry.images?.length ?? 0)
  return images > 0 || parseTags(entry.reason).length > 0
}

function needsMissedRecovery(entry: MissedTrade): boolean {
  const images = toNumber(entry.images_count ?? entry.images?.length ?? 0)
  return images === 0 || parseTags(entry.reason).length < 2 || (entry.notes ?? '').trim().length < 20
}

function hasActionPlan(notes: string | null | undefined): boolean {
  return /action plan|next time|i will|prevent|if setup repeats/i.test(notes ?? '')
}

function hasFollowUpNote(notes: string | null | undefined): boolean {
  return /follow[\s-]?up|revisit|check back/i.test(notes ?? '')
}

function needsReview(trade: Trade): boolean {
  const note = (trade.notes ?? '').trim()
  return note.length < 8 || imageCount(trade) === 0
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

function setDashboardMode(mode: DashboardMode) {
  dashboardMode.value = mode
}

</script>

<template>
  <Transition name="account-switch">
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
            <button class="overview-tab-btn" :class="{ active: activeTab === 'chart' }" @click="activeTab = 'chart'">
              Chart
            </button>
          </div>

          <div class="overview-tab-switch">
            <button
              class="overview-tab-btn"
              :class="{ active: effectiveDashboardMode === 'live' }"
              :disabled="!hasLiveAccounts"
              @click="setDashboardMode('live')"
            >
              Live Journal
            </button>
            <button
              class="overview-tab-btn"
              :class="{ active: effectiveDashboardMode === 'prop' }"
              :disabled="!hasPropAccounts"
              @click="setDashboardMode('prop')"
            >
              Prop Challenge
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

      <section v-if="activeTab === 'overview' && effectiveDashboardMode === 'prop'" class="panel">
        <div class="section-head">
          <div>
            <h2 class="section-title">Prop Challenge Status</h2>
            <p class="section-note">Live pass/fail risk state based on current trade history.</p>
          </div>
          <span class="pill" :class="challengeRiskStateClass">{{ challengeRiskStateLabel }}</span>
        </div>

        <EmptyState
          v-if="selectedAccountId === null"
          title="Select an account"
          description="Challenge status is calculated per account."
          :icon="ShieldAlert"
        />

        <div v-else-if="challengeStatusLoading" class="grid grid-premium md:grid-cols-4">
          <SkeletonBlock v-for="index in 4" :key="`challenge-status-skeleton-${index}`" height-class="h-24" rounded-class="rounded-2xl" />
        </div>

        <div v-else-if="challengeStatus" class="grid grid-premium md:grid-cols-4">
          <article class="panel p-3">
            <p class="kicker-label">Target Progress</p>
            <p class="mt-1 text-lg font-semibold value-display">
              <AnimatedNumber :value="challengeStatus.target_progress.progress_pct" :decimals="2" suffix="%" />
            </p>
            <p class="muted text-xs">{{ asCurrency(challengeStatus.target_progress.net_profit) }} / {{ asCurrency(challengeStatus.target_progress.target_profit) }}</p>
          </article>
          <article class="panel p-3">
            <p class="kicker-label">Daily Loss Headroom</p>
            <p class="mt-1 text-lg font-semibold value-display" :class="challengeStatus.daily_loss_headroom.breached ? 'negative' : 'positive'">
              {{ asCurrency(challengeStatus.daily_loss_headroom.headroom) }}
            </p>
            <p class="muted text-xs">Used today: {{ asCurrency(challengeStatus.daily_loss_headroom.used) }}</p>
          </article>
          <article class="panel p-3">
            <p class="kicker-label">Total DD Headroom</p>
            <p class="mt-1 text-lg font-semibold value-display" :class="challengeStatus.total_dd_headroom.breached ? 'negative' : 'positive'">
              {{ asCurrency(challengeStatus.total_dd_headroom.headroom) }}
            </p>
            <p class="muted text-xs">Used: {{ asCurrency(challengeStatus.total_dd_headroom.used) }}</p>
          </article>
          <article class="panel p-3">
            <p class="kicker-label">Min Trading Days</p>
            <p class="mt-1 text-lg font-semibold value-display">
              <AnimatedNumber :value="challengeStatus.min_days_progress.progress_pct" :decimals="2" suffix="%" />
            </p>
            <p class="muted text-xs">{{ challengeStatus.min_days_progress.actual }} / {{ challengeStatus.min_days_progress.required }} days</p>
          </article>
        </div>

        <EmptyState
          v-else
          title="No challenge status"
          description="Configure challenge settings for this account."
          :icon="ShieldAlert"
        />
      </section>

      <div v-if="tabMounted.overview" v-show="activeTab === 'overview'">
        <section v-if="loading && !summary" class="grid grid-premium lg:grid-cols-4">
          <SkeletonBlock v-for="index in 4" :key="`dashboard-kpi-skeleton-${index}`" height-class="h-52" rounded-class="rounded-2xl" />
        </section>

        <section v-if="effectiveDashboardMode === 'prop'" class="panel p-3 text-sm">
          <p class="section-note">Trading analytics below are secondary to challenge compliance in this view.</p>
        </section>

        <section class="overview-kpi-grid">
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
            <p class="overview-kpi-caption">Net Profit (USD)</p>
            <div class="overview-kpi-split">
              <article>
                <strong class="value-display" :class="tradePnlClass(expectancyMoney)">
                  <AnimatedNumber :value="expectancyMoney" :decimals="2" prefix="$" :sign="true" />
                </strong>
                <span>Expectancy (USD/Trade)</span>
              </article>
              <article>
                <strong class="value-display" :class="payoffRatio !== null && payoffRatio < 1 ? 'negative' : ''">
                  {{ payoffRatio === null ? '-' : Number(payoffRatio).toFixed(2) }}
                </strong>
                <span>Payoff Ratio (Avg Win/Avg Loss)</span>
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
                  <AnimatedNumber :value="avgRRealized" :decimals="2" suffix="R" />
                </strong>
                <span>Avg R Realized</span>
              </article>
              <article>
                <strong class="value-display">
                  <AnimatedNumber :value="avgRrPlanned" :decimals="2" />
                </strong>
                <span>Avg RR Planned</span>
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
                  <AnimatedNumber :value="expectancyR" :decimals="2" suffix="R" />
                </strong>
                <span>Expectancy (R/Trade)</span>
              </article>
            </div>
          </GlassPanel>
        </section>

        <section class="grid grid-premium">
          <GlassPanel class="weekly-loop-panel">
            <div class="section-head">
              <div>
                <h2 class="section-title">Weekly Review Loop</h2>
                <p class="section-note">Capture -> Triage -> Action Plan -> Follow-up (last 7 days)</p>
              </div>
              <div class="weekly-loop-links">
                <RouterLink :to="{ path: '/trades', query: { focus: 'needs_review' } }" class="btn btn-ghost px-3 py-2 text-sm">
                  Trade triage
                </RouterLink>
                <RouterLink :to="{ path: '/missed-trades', query: { focus: 'action_required' } }" class="btn btn-ghost px-3 py-2 text-sm">
                  Missed triage
                </RouterLink>
              </div>
            </div>

            <div v-if="weeklyLoopLoading" class="grid gap-2 md:grid-cols-4">
              <SkeletonBlock v-for="index in 4" :key="`weekly-loop-skeleton-${index}`" height-class="h-20" rounded-class="rounded-xl" />
            </div>

            <div v-else class="weekly-loop-grid">
              <article class="weekly-loop-card">
                <span class="kicker-label">Capture</span>
                <strong class="value-display">
                  <AnimatedNumber :value="weeklyCaptureRate" :decimals="0" suffix="%" />
                </strong>
                <p class="section-note">
                  {{ weeklyCaptureCompleteCount }}/{{ weeklyReviewVolume }} entries captured
                </p>
              </article>

              <article class="weekly-loop-card">
                <span class="kicker-label">Triage</span>
                <strong class="value-display" :class="weeklyTriageQueueCount > 0 ? 'negative' : 'positive'">
                  <AnimatedNumber :value="weeklyTriageQueueCount" />
                </strong>
                <p class="section-note">
                  {{ weeklyTriageQueueCount > 0 ? 'Items need review now' : 'Queue is clear' }}
                </p>
              </article>

              <article class="weekly-loop-card">
                <span class="kicker-label">Action Plan</span>
                <strong class="value-display">
                  <AnimatedNumber :value="weeklyActionPlanRate" :decimals="0" suffix="%" />
                </strong>
                <p class="section-note">
                  {{ weeklyActionPlanCount }} of {{ weeklyReviewVolume }} entries planned
                </p>
              </article>

              <article class="weekly-loop-card">
                <span class="kicker-label">Follow-up</span>
                <strong class="value-display" :class="weeklyFollowUpPendingCount > 0 ? 'negative' : 'positive'">
                  <AnimatedNumber :value="weeklyFollowUpPendingCount" />
                </strong>
                <p class="section-note">
                  {{ weeklyFollowUpDoneCount }} closed
                </p>
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
      </div>

      <div v-if="tabMounted.chart" v-show="activeTab === 'chart'">
        <section v-if="!hasChartData" class="grid grid-premium xl:grid-cols-2">
          <GlassPanel>
            <EmptyState title="No emotion data" description="Log trades with emotion tags to populate this chart." :icon="BarChartHorizontalBig" />
          </GlassPanel>
          <GlassPanel>
            <EmptyState title="No session data" description="Log trades with session tags to populate this chart." :icon="BarChartHorizontalBig" />
          </GlassPanel>
        </section>

        <section v-else class="grid grid-premium xl:grid-cols-2">
          <GlassPanel>
            <div class="section-head">
              <h2 class="section-title">Emotional Impact</h2>
            </div>
            <EmotionPieChart :slices="chartEmotionRows" />
          </GlassPanel>

          <GlassPanel>
            <div class="section-head">
              <h2 class="section-title">Session Edge</h2>
            </div>
            <SessionPerformanceBarChart :rows="chartSessionRows" />
          </GlassPanel>
        </section>
      </div>

      <div v-if="tabMounted.calendar" v-show="activeTab === 'calendar'">
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
      </div>
    </div>
  </Transition>
</template>
