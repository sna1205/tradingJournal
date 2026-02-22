import { defineStore } from 'pinia'
import { computed, ref, type Ref } from 'vue'
import api from '@/services/api'
import { fetchLocalAccounts, queryLocalTrades, shouldUseLocalFallback } from '@/services/localFallback'
import { useAccountStore } from '@/stores/accountStore'
import { useSyncStatusStore } from '@/stores/syncStatusStore'
import type { SummaryStats, Trade } from '@/types/trade'

export interface AnalyticsOverview {
  total_trades: number
  win_rate: number
  total_profit: number
  total_loss: number
  profit_factor: number | null
  return_on_equity_pct: number
  expectancy?: number
  average_r?: number
  recovery_factor?: number | null
}

export interface AnalyticsDailyRow {
  date: string
  close_date?: string
  total_trades: number
  profit_loss: number
  average_r?: number
  win_rate?: number
}

export interface PerformanceProfile {
  win_rate: number
  avg_rr: number
  profit_factor: number | null
  consistency_score: number
  recovery_factor: number | null
  sharpe_ratio?: number | null
}

export interface EquityPayload {
  equity_points: number[]
  cumulative_profit: number[]
  equity_timestamps: string[]
}

export interface DrawdownPayload {
  max_drawdown: number
  max_drawdown_percent: number
  current_drawdown: number
  current_drawdown_percent: number
  peak_balance: number
  current_equity: number
}

export interface StreakPayload {
  longest_win_streak: number
  longest_loss_streak: number
  current_win_streak: number
  current_loss_streak: number
  current_streak: {
    type: 'win' | 'loss' | 'flat'
    length: number
  }
}

export interface MetricsPayload {
  total_trades: number
  wins: number
  losses: number
  breakeven: number
  win_rate: number
  loss_rate: number
  average_win: number
  average_loss: number
  total_winning_amount: number
  total_losing_amount: number
  net_profit: number
  profit_factor: number | null
  expectancy: number
  expectancy_money: number
  expectancy_r: number
  payoff_ratio: number | null
  recovery_factor: number | null
  average_r: number
  avg_r: number
  avg_r_realized: number
  avg_rr_planned: number
  sharpe_ratio: number | null
}

export interface RankingRow {
  session?: string
  killzone?: string
  setup?: string
  strategy_model?: string
  symbol?: string
  total_trades: number
  win_rate: number
  profit_factor: number | null
  expectancy: number
  total_pnl: number
  avg_r: number
}

export interface RankingsPayload {
  sessions: RankingRow[]
  killzones: RankingRow[]
  setups: RankingRow[]
  strategy_models: RankingRow[]
  symbols: RankingRow[]
}

export interface HeatmapDay {
  close_date: string
  number_of_trades: number
  total_profit: number
  average_r: number
  win_rate: number
  intensity: number
}

export interface HeatmapMonth {
  month: string
  label: string
  days: HeatmapDay[]
}

export interface MonthlyHeatmapPayload {
  months: HeatmapMonth[]
  max_abs_daily_pnl: number
}

export interface RiskStatusPayload {
  risk_percent_warning: boolean
  loss_streak_caution: boolean
  drawdown_banner: boolean
  revenge_behavior_flag: boolean
  latest_risk_percent: number
  max_risk_percent: number
  current_loss_streak: number
  current_drawdown_percent: number
  revenge_after_loss_events: Array<{ trade_id: number; previous_trade_id: number; date: string }>
  warnings: string[]
}

export interface BehavioralPayload {
  discipline_comparison: {
    followed_rules: Record<string, number | string | null>
    broke_rules: Record<string, number | string | null>
    insight: {
      when_follow_rules: string
      when_break_rules: string
    }
  }
  emotion_analytics: {
    breakdown: Array<Record<string, number | string | null>>
    most_costly_emotion: string | null
    most_profitable_mindset: string | null
  }
  psychology_correlations: {
    confidence_buckets: Array<{
      bucket: string
      total_trades: number
      expectancy_money: number
      expectancy_r: number
      win_rate: number
      rule_break_rate: number
    }>
    stress_buckets: Array<{
      bucket: string
      total_trades: number
      expectancy_money: number
      expectancy_r: number
      win_rate: number
      rule_break_rate: number
    }>
    flags: Record<string, {
      total_trades: number
      expectancy_money: number
      expectancy_r: number
      rule_break_rate: number
    }>
  }
}

export interface AnalyticsRangeFilters {
  date_from?: string
  date_to?: string
}

interface BreakdownPoint {
  pnl: number
  trade_count: number
}

interface DayBreakdown extends BreakdownPoint {
  day: string
}

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

export const useAnalyticsStore = defineStore('analytics', () => {
  const accountStore = useAccountStore()
  const syncStatusStore = useSyncStatusStore()
  const overview = ref<AnalyticsOverview | null>(null)
  const dailyStats = ref<AnalyticsDailyRow[]>([])
  const performanceProfile = ref<PerformanceProfile | null>(null)
  const equity = ref<EquityPayload | null>(null)
  const drawdown = ref<DrawdownPayload | null>(null)
  const streaks = ref<StreakPayload | null>(null)
  const metrics = ref<MetricsPayload | null>(null)
  const rankings = ref<RankingsPayload | null>(null)
  const monthlyHeatmap = ref<MonthlyHeatmapPayload | null>(null)
  const riskStatus = ref<RiskStatusPayload | null>(null)
  const behavioral = ref<BehavioralPayload | null>(null)
  const loading = ref(false)

  const bySymbol = computed(() =>
    (rankings.value?.symbols ?? []).map((row) => ({
      symbol: row.symbol ?? 'Unknown',
      pnl: Number(row.total_pnl ?? 0),
      trade_count: Number(row.total_trades ?? 0),
    }))
  )

  const bySetup = computed(() =>
    (rankings.value?.strategy_models ?? []).map((row) => ({
      setup: row.strategy_model ?? 'Unknown',
      pnl: Number(row.total_pnl ?? 0),
      trade_count: Number(row.total_trades ?? 0),
    }))
  )

  const bestSymbol = computed(() => bySymbol.value[0] ?? null)

  const equityCurve = computed(() => {
    const points: Array<{ date: string; equity: number }> = []
    const source = equity.value

    if (source && source.equity_points.length === source.equity_timestamps.length) {
      for (let i = 0; i < source.equity_points.length; i += 1) {
        points.push({
          date: source.equity_timestamps[i] ?? '',
          equity: Number(source.equity_points[i] ?? 0),
        })
      }
      return points
    }

    let running = 0
    const ordered = [...dailyStats.value].sort((a, b) => a.date.localeCompare(b.date))
    for (const row of ordered) {
      running += Number(row.profit_loss || 0)
      points.push({
        date: row.date,
        equity: Number(running.toFixed(2)),
      })
    }

    return points
  })

  const drawdownSeries = computed(() => {
    const series: number[] = []
    let peak = 0

    for (const point of equityCurve.value) {
      peak = Math.max(peak, point.equity)
      series.push(Number((peak - point.equity).toFixed(2)))
    }

    return series
  })

  const byWeekday = computed<DayBreakdown[]>(() => {
    const buckets = weekdayLabels.map((day) => ({
      day,
      pnl: 0,
      trade_count: 0,
    }))

    for (const row of dailyStats.value) {
      const index = new Date(row.date).getDay()
      const safeIndex = Number.isFinite(index) ? index : 0
      const bucket = buckets[safeIndex]
      if (!bucket) continue

      bucket.pnl += Number(row.profit_loss || 0)
      bucket.trade_count += Number(row.total_trades || 0)
    }

    return buckets.map((bucket) => ({
      day: bucket.day,
      pnl: Number(bucket.pnl.toFixed(2)),
      trade_count: bucket.trade_count,
    }))
  })

  const summary = computed<SummaryStats | null>(() => {
    if (!overview.value) return null

    const closedTrades = overview.value.total_trades
    const winningTrades = Math.round((closedTrades * overview.value.win_rate) / 100)
    const losingTrades = Math.max(0, closedTrades - winningTrades)
    const totalPnl = Number((overview.value.total_profit - overview.value.total_loss).toFixed(2))
    const avgWin = winningTrades > 0 ? overview.value.total_profit / winningTrades : 0
    const avgLoss = losingTrades > 0 ? overview.value.total_loss / losingTrades : 0

    return {
      closed_trades: closedTrades,
      winning_trades: winningTrades,
      losing_trades: losingTrades,
      breakeven_trades: 0,
      win_rate: overview.value.win_rate,
      total_pnl: totalPnl,
      gross_profit: overview.value.total_profit,
      gross_loss: overview.value.total_loss,
      avg_win: Number(avgWin.toFixed(2)),
      avg_loss: Number(avgLoss.toFixed(2)),
      profit_factor: overview.value.profit_factor,
      expectancy: Number((overview.value.expectancy ?? 0).toFixed(2)),
    }
  })

  async function fetchAnalytics(filters?: AnalyticsRangeFilters, accountIdOverride?: number | null) {
    loading.value = true
    const activeAccountId =
      accountIdOverride === undefined ? accountStore.selectedAccountId : accountIdOverride
    const params = analyticsQueryParams(activeAccountId, filters)

    try {
      const [
        overviewRes,
        dailyRes,
        profileRes,
        equityRes,
        drawdownRes,
        streaksRes,
        metricsRes,
        behavioralRes,
        rankingsRes,
        monthlyHeatmapRes,
        riskStatusRes,
      ] = await Promise.all([
        api.get<AnalyticsOverview>('/analytics/overview', { params }),
        api.get<AnalyticsDailyRow[]>('/analytics/daily', { params }),
        api.get<PerformanceProfile>('/analytics/performance-profile', { params }),
        api.get<EquityPayload>('/analytics/equity', { params }),
        api.get<DrawdownPayload>('/analytics/drawdown', { params }),
        api.get<StreakPayload>('/analytics/streaks', { params }),
        api.get<MetricsPayload>('/analytics/metrics', { params }),
        api.get<BehavioralPayload>('/analytics/behavioral', { params }),
        api.get<RankingsPayload>('/analytics/rankings', { params }),
        api.get<MonthlyHeatmapPayload>('/analytics/monthly-heatmap', { params }),
        api.get<RiskStatusPayload>('/analytics/risk-status', { params }),
      ])
      syncStatusStore.markServerHealthy()

      overview.value = {
        ...overviewRes.data,
        total_trades: Number(overviewRes.data.total_trades || 0),
        win_rate: Number(overviewRes.data.win_rate || 0),
        total_profit: Number(overviewRes.data.total_profit || 0),
        total_loss: Number(overviewRes.data.total_loss || 0),
        profit_factor: overviewRes.data.profit_factor === null ? null : Number(overviewRes.data.profit_factor),
        return_on_equity_pct: Number(overviewRes.data.return_on_equity_pct || 0),
        expectancy: Number(overviewRes.data.expectancy || 0),
        average_r: Number(overviewRes.data.average_r || 0),
        recovery_factor: overviewRes.data.recovery_factor === null ? null : Number(overviewRes.data.recovery_factor),
      }

      const dailyRows = Array.isArray(dailyRes.data) ? dailyRes.data : []
      dailyStats.value = dailyRows.map((row) => ({
        date: row.date || row.close_date || '',
        close_date: row.close_date || row.date || '',
        total_trades: Number(row.total_trades || 0),
        profit_loss: Number(row.profit_loss || 0),
        average_r: Number(row.average_r || 0),
        win_rate: Number(row.win_rate || 0),
      }))

      performanceProfile.value = {
        ...profileRes.data,
        win_rate: Number(profileRes.data.win_rate || 0),
        avg_rr: Number(profileRes.data.avg_rr || 0),
        profit_factor: profileRes.data.profit_factor === null ? null : Number(profileRes.data.profit_factor),
        consistency_score: Number(profileRes.data.consistency_score || 0),
        recovery_factor: profileRes.data.recovery_factor === null ? null : Number(profileRes.data.recovery_factor),
        sharpe_ratio: profileRes.data.sharpe_ratio === null ? null : Number(profileRes.data.sharpe_ratio || 0),
      }

      equity.value = {
        equity_points: (equityRes.data.equity_points || []).map((value) => Number(value || 0)),
        cumulative_profit: (equityRes.data.cumulative_profit || []).map((value) => Number(value || 0)),
        equity_timestamps: (equityRes.data.equity_timestamps || []).map((value) => String(value || '')),
      }

      drawdown.value = {
        max_drawdown: Number(drawdownRes.data.max_drawdown || 0),
        max_drawdown_percent: Number(drawdownRes.data.max_drawdown_percent || 0),
        current_drawdown: Number(drawdownRes.data.current_drawdown || 0),
        current_drawdown_percent: Number(drawdownRes.data.current_drawdown_percent || 0),
        peak_balance: Number(drawdownRes.data.peak_balance || 0),
        current_equity: Number(drawdownRes.data.current_equity || 0),
      }

      streaks.value = {
        ...streaksRes.data,
        longest_win_streak: Number(streaksRes.data.longest_win_streak || 0),
        longest_loss_streak: Number(streaksRes.data.longest_loss_streak || 0),
        current_win_streak: Number(streaksRes.data.current_win_streak || 0),
        current_loss_streak: Number(streaksRes.data.current_loss_streak || 0),
        current_streak: {
          type: streaksRes.data.current_streak?.type ?? 'flat',
          length: Number(streaksRes.data.current_streak?.length || 0),
        },
      }

      metrics.value = {
        ...metricsRes.data,
        total_trades: Number(metricsRes.data.total_trades || 0),
        wins: Number(metricsRes.data.wins || 0),
        losses: Number(metricsRes.data.losses || 0),
        breakeven: Number(metricsRes.data.breakeven || 0),
        win_rate: Number(metricsRes.data.win_rate || 0),
        loss_rate: Number(metricsRes.data.loss_rate || 0),
        average_win: Number(metricsRes.data.average_win || 0),
        average_loss: Number(metricsRes.data.average_loss || 0),
        total_winning_amount: Number(metricsRes.data.total_winning_amount || 0),
        total_losing_amount: Number(metricsRes.data.total_losing_amount || 0),
        net_profit: Number(metricsRes.data.net_profit || 0),
        profit_factor: metricsRes.data.profit_factor === null ? null : Number(metricsRes.data.profit_factor),
        expectancy: Number(metricsRes.data.expectancy || 0),
        expectancy_money: Number(metricsRes.data.expectancy_money || metricsRes.data.expectancy || 0),
        expectancy_r: Number(metricsRes.data.expectancy_r || 0),
        payoff_ratio: metricsRes.data.payoff_ratio === null ? null : Number(metricsRes.data.payoff_ratio),
        recovery_factor: metricsRes.data.recovery_factor === null ? null : Number(metricsRes.data.recovery_factor),
        average_r: Number(metricsRes.data.average_r || 0),
        avg_r: Number(metricsRes.data.avg_r || 0),
        avg_r_realized: Number(metricsRes.data.avg_r_realized || metricsRes.data.avg_r || metricsRes.data.average_r || 0),
        avg_rr_planned: Number(metricsRes.data.avg_rr_planned || 0),
        sharpe_ratio: metricsRes.data.sharpe_ratio === null ? null : Number(metricsRes.data.sharpe_ratio),
      }

      behavioral.value = behavioralRes.data

      rankings.value = {
        sessions: (rankingsRes.data.sessions || []).map((row) => ({
          ...row,
          total_trades: Number(row.total_trades || 0),
          win_rate: Number(row.win_rate || 0),
          profit_factor: row.profit_factor === null ? null : Number(row.profit_factor),
          expectancy: Number(row.expectancy || 0),
          total_pnl: Number(row.total_pnl || 0),
          avg_r: Number(row.avg_r || 0),
        })),
        killzones: (rankingsRes.data.killzones || []).map((row) => ({
          ...row,
          total_trades: Number(row.total_trades || 0),
          win_rate: Number(row.win_rate || 0),
          profit_factor: row.profit_factor === null ? null : Number(row.profit_factor),
          expectancy: Number(row.expectancy || 0),
          total_pnl: Number(row.total_pnl || 0),
          avg_r: Number(row.avg_r || 0),
        })),
        setups: (rankingsRes.data.setups || []).map((row) => ({
          ...row,
          total_trades: Number(row.total_trades || 0),
          win_rate: Number(row.win_rate || 0),
          profit_factor: row.profit_factor === null ? null : Number(row.profit_factor),
          expectancy: Number(row.expectancy || 0),
          total_pnl: Number(row.total_pnl || 0),
          avg_r: Number(row.avg_r || 0),
        })),
        strategy_models: (rankingsRes.data.strategy_models || []).map((row) => ({
          ...row,
          total_trades: Number(row.total_trades || 0),
          win_rate: Number(row.win_rate || 0),
          profit_factor: row.profit_factor === null ? null : Number(row.profit_factor),
          expectancy: Number(row.expectancy || 0),
          total_pnl: Number(row.total_pnl || 0),
          avg_r: Number(row.avg_r || 0),
        })),
        symbols: (rankingsRes.data.symbols || []).map((row) => ({
          ...row,
          total_trades: Number(row.total_trades || 0),
          win_rate: Number(row.win_rate || 0),
          profit_factor: row.profit_factor === null ? null : Number(row.profit_factor),
          expectancy: Number(row.expectancy || 0),
          total_pnl: Number(row.total_pnl || 0),
          avg_r: Number(row.avg_r || 0),
        })),
      }

      monthlyHeatmap.value = {
        months: (monthlyHeatmapRes.data.months || []).map((month) => ({
          ...month,
          days: (month.days || []).map((day) => ({
            ...day,
            number_of_trades: Number(day.number_of_trades || 0),
            total_profit: Number(day.total_profit || 0),
            average_r: Number(day.average_r || 0),
            win_rate: Number(day.win_rate || 0),
            intensity: Number(day.intensity || 0),
          })),
        })),
        max_abs_daily_pnl: Number(monthlyHeatmapRes.data.max_abs_daily_pnl || 0),
      }

      riskStatus.value = {
        ...riskStatusRes.data,
        risk_percent_warning: Boolean(riskStatusRes.data.risk_percent_warning),
        loss_streak_caution: Boolean(riskStatusRes.data.loss_streak_caution),
        drawdown_banner: Boolean(riskStatusRes.data.drawdown_banner),
        revenge_behavior_flag: Boolean(riskStatusRes.data.revenge_behavior_flag),
        latest_risk_percent: Number(riskStatusRes.data.latest_risk_percent || 0),
        max_risk_percent: Number(riskStatusRes.data.max_risk_percent || 0),
        current_loss_streak: Number(riskStatusRes.data.current_loss_streak || 0),
        current_drawdown_percent: Number(riskStatusRes.data.current_drawdown_percent || 0),
        revenge_after_loss_events: riskStatusRes.data.revenge_after_loss_events || [],
        warnings: riskStatusRes.data.warnings || [],
      }

    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('analytics')

      const localTrades = queryLocalTrades({
        page: 1,
        per_page: 100000,
        account_id: activeAccountId ?? undefined,
        date_from: filters?.date_from,
        date_to: filters?.date_to,
      }).data
      const localAccounts = fetchLocalAccounts()
      applyLocalAnalyticsFallback({
        trades: localTrades,
        accounts: localAccounts,
        selectedAccountId: activeAccountId,
        overview,
        dailyStats,
        performanceProfile,
        equity,
        drawdown,
        streaks,
        metrics,
        rankings,
        monthlyHeatmap,
        riskStatus,
        behavioral,
      })
    } finally {
      loading.value = false
    }
  }

  const daily = dailyStats
  const fetchAll = fetchAnalytics

  return {
    overview,
    dailyStats,
    daily,
    performanceProfile,
    equity,
    drawdown,
    drawdownSeries,
    streaks,
    metrics,
    rankings,
    monthlyHeatmap,
    riskStatus,
    behavioral,
    summary,
    equityCurve,
    bySymbol,
    bySetup,
    byWeekday,
    loading,
    bestSymbol,
    fetchAnalytics,
    fetchAll,
  }
})

function analyticsQueryParams(
  selectedAccountId: number | null,
  filters?: AnalyticsRangeFilters
): Record<string, number | string> | undefined {
  const params: Record<string, number | string> = {}

  if (selectedAccountId !== null) {
    params.account_id = selectedAccountId
  }

  if (filters?.date_from) {
    params.date_from = filters.date_from
  }

  if (filters?.date_to) {
    params.date_to = filters.date_to
  }

  return Object.keys(params).length > 0 ? params : undefined
}

function applyLocalAnalyticsFallback(args: {
  trades: Trade[]
  accounts: Array<{ id: number; starting_balance: string }>
  selectedAccountId: number | null
  overview: Ref<AnalyticsOverview | null>
  dailyStats: Ref<AnalyticsDailyRow[]>
  performanceProfile: Ref<PerformanceProfile | null>
  equity: Ref<EquityPayload | null>
  drawdown: Ref<DrawdownPayload | null>
  streaks: Ref<StreakPayload | null>
  metrics: Ref<MetricsPayload | null>
  rankings: Ref<RankingsPayload | null>
  monthlyHeatmap: Ref<MonthlyHeatmapPayload | null>
  riskStatus: Ref<RiskStatusPayload | null>
  behavioral: Ref<BehavioralPayload | null>
}) {
  const sorted = args.trades
    .slice()
    .sort((left, right) => left.date.localeCompare(right.date) || left.id - right.id)

  if (sorted.length === 0) {
    applyEmptyAnalyticsState(args)
    return
  }

  const totalTrades = sorted.length
  let wins = 0
  let losses = 0
  let totalProfit = 0
  let totalLossAbs = 0
  let totalR = 0

  const dayMap = new Map<string, { total_trades: number; profit_loss: number; wins: number; rTotal: number }>()
  const sessionMap = new Map<string, Trade[]>()
  const killzoneMap = new Map<string, Trade[]>()
  const setupMap = new Map<string, Trade[]>()
  const modelMap = new Map<string, Trade[]>()
  const symbolMap = new Map<string, Trade[]>()
  const emotionMap = new Map<string, { total_trades: number; total_profit: number }>()

  for (const trade of sorted) {
    const pnl = Number(trade.profit_loss || 0)
    const r = Number(trade.r_multiple ?? 0)
    totalR += r

    if (pnl > 0) {
      wins += 1
      totalProfit += pnl
    } else if (pnl < 0) {
      losses += 1
      totalLossAbs += Math.abs(pnl)
    }

    const day = toIsoDateKey(trade.date)
    const bucket = dayMap.get(day) ?? { total_trades: 0, profit_loss: 0, wins: 0, rTotal: 0 }
    bucket.total_trades += 1
    bucket.profit_loss += pnl
    bucket.rTotal += r
    if (pnl > 0) bucket.wins += 1
    dayMap.set(day, bucket)

    const session = trade.session || 'N/A'
    const killzone = trade.killzone?.name || 'Legacy/Unmapped'
    const setup = trade.setup?.name || 'Legacy/Unmapped'
    const model = trade.model || 'General'
    const symbol = trade.pair || 'Unknown'
    sessionMap.set(session, [...(sessionMap.get(session) ?? []), trade])
    killzoneMap.set(killzone, [...(killzoneMap.get(killzone) ?? []), trade])
    setupMap.set(setup, [...(setupMap.get(setup) ?? []), trade])
    modelMap.set(model, [...(modelMap.get(model) ?? []), trade])
    symbolMap.set(symbol, [...(symbolMap.get(symbol) ?? []), trade])

    const emotion = trade.emotion || 'neutral'
    const emotionBucket = emotionMap.get(emotion) ?? { total_trades: 0, total_profit: 0 }
    emotionBucket.total_trades += 1
    emotionBucket.total_profit += pnl
    emotionMap.set(emotion, emotionBucket)
  }

  const netProfit = Number((totalProfit - totalLossAbs).toFixed(2))
  const winRate = totalTrades > 0 ? Number(((wins / totalTrades) * 100).toFixed(2)) : 0
  const avgR = totalTrades > 0 ? Number((totalR / totalTrades).toFixed(3)) : 0
  const expectancy = totalTrades > 0 ? Number((netProfit / totalTrades).toFixed(2)) : 0
  const profitFactor = totalLossAbs > 0 ? Number((totalProfit / totalLossAbs).toFixed(2)) : null
  const averageWin = wins > 0 ? Number((totalProfit / wins).toFixed(2)) : 0
  const averageLoss = losses > 0 ? Number((totalLossAbs / losses).toFixed(2)) : 0
  const payoffRatio = averageLoss > 0 ? Number((averageWin / averageLoss).toFixed(4)) : null

  const startingBalance = args.selectedAccountId === null
    ? args.accounts.reduce((sum, account) => sum + Number(account.starting_balance || 0), 0)
    : Number(args.accounts.find((account) => account.id === args.selectedAccountId)?.starting_balance || 0)

  const dailyRows = [...dayMap.entries()]
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([date, value]) => ({
      date,
      close_date: date,
      total_trades: value.total_trades,
      profit_loss: Number(value.profit_loss.toFixed(2)),
      average_r: value.total_trades > 0 ? Number((value.rTotal / value.total_trades).toFixed(3)) : 0,
      win_rate: value.total_trades > 0 ? Number(((value.wins / value.total_trades) * 100).toFixed(2)) : 0,
    }))

  const cumulativeProfit: number[] = []
  const equityPoints: number[] = []
  const equityTimestamps: string[] = []
  let runningProfit = 0
  for (const day of dailyRows) {
    runningProfit = Number((runningProfit + day.profit_loss).toFixed(2))
    cumulativeProfit.push(runningProfit)
    equityPoints.push(Number((startingBalance + runningProfit).toFixed(2)))
    equityTimestamps.push(day.date)
  }

  let peakBalance = startingBalance
  let maxDrawdown = 0
  for (const point of equityPoints) {
    peakBalance = Math.max(peakBalance, point)
    maxDrawdown = Math.max(maxDrawdown, peakBalance - point)
  }
  const currentEquity = equityPoints.length > 0 ? equityPoints[equityPoints.length - 1]! : startingBalance
  const currentDrawdown = Math.max(0, peakBalance - currentEquity)
  const maxDrawdownPercent = peakBalance > 0 ? Number(((maxDrawdown / peakBalance) * 100).toFixed(2)) : 0
  const currentDrawdownPercent = peakBalance > 0 ? Number(((currentDrawdown / peakBalance) * 100).toFixed(2)) : 0

  args.overview.value = {
    total_trades: totalTrades,
    win_rate: winRate,
    total_profit: Number(totalProfit.toFixed(2)),
    total_loss: Number(totalLossAbs.toFixed(2)),
    profit_factor: profitFactor,
    return_on_equity_pct: startingBalance > 0 ? Number(((netProfit / startingBalance) * 100).toFixed(4)) : 0,
    expectancy,
    average_r: avgR,
    recovery_factor: maxDrawdown > 0 ? Number((netProfit / maxDrawdown).toFixed(2)) : null,
  }
  args.dailyStats.value = dailyRows
  args.performanceProfile.value = {
    win_rate: winRate,
    avg_rr: avgR,
    profit_factor: profitFactor,
    consistency_score: totalTrades > 0 ? Number(((winRate / 100) * 10).toFixed(2)) : 0,
    recovery_factor: maxDrawdown > 0 ? Number((netProfit / maxDrawdown).toFixed(2)) : null,
    sharpe_ratio: null,
  }
  args.equity.value = {
    equity_points: equityPoints,
    cumulative_profit: cumulativeProfit,
    equity_timestamps: equityTimestamps,
  }
  args.drawdown.value = {
    max_drawdown: Number(maxDrawdown.toFixed(2)),
    max_drawdown_percent: maxDrawdownPercent,
    current_drawdown: Number(currentDrawdown.toFixed(2)),
    current_drawdown_percent: currentDrawdownPercent,
    peak_balance: Number(peakBalance.toFixed(2)),
    current_equity: Number(currentEquity.toFixed(2)),
  }
  args.streaks.value = buildStreakPayload(sorted)
  args.metrics.value = {
    total_trades: totalTrades,
    wins,
    losses,
    breakeven: Math.max(0, totalTrades - wins - losses),
    win_rate: winRate,
    loss_rate: totalTrades > 0 ? Number(((losses / totalTrades) * 100).toFixed(2)) : 0,
    average_win: averageWin,
    average_loss: averageLoss,
    total_winning_amount: Number(totalProfit.toFixed(2)),
    total_losing_amount: Number(totalLossAbs.toFixed(2)),
    net_profit: netProfit,
    profit_factor: profitFactor,
    expectancy,
    expectancy_money: expectancy,
    expectancy_r: avgR,
    payoff_ratio: payoffRatio,
    recovery_factor: maxDrawdown > 0 ? Number((netProfit / maxDrawdown).toFixed(2)) : null,
    average_r: avgR,
    avg_r: avgR,
    avg_r_realized: avgR,
    avg_rr_planned: 0,
    sharpe_ratio: null,
  }
  args.rankings.value = {
    sessions: buildRankingRows(sessionMap, 'session'),
    killzones: buildRankingRows(killzoneMap, 'killzone'),
    setups: buildRankingRows(setupMap, 'setup'),
    strategy_models: buildRankingRows(modelMap, 'strategy_model'),
    symbols: buildRankingRows(symbolMap, 'symbol'),
  }
  args.monthlyHeatmap.value = {
    months: [],
    max_abs_daily_pnl: dailyRows.reduce((max, row) => Math.max(max, Math.abs(row.profit_loss)), 0),
  }
  const emotionBreakdown = [...emotionMap.entries()].map(([emotion, value]) => ({
    emotion,
    total_trades: value.total_trades,
    total_profit: Number(value.total_profit.toFixed(2)),
  }))
  args.behavioral.value = {
    discipline_comparison: {
      followed_rules: {},
      broke_rules: {},
      insight: {
        when_follow_rules: 'Local mode fallback enabled.',
        when_break_rules: 'Connect backend for full behavioral analysis.',
      },
    },
    emotion_analytics: {
      breakdown: emotionBreakdown,
      most_costly_emotion: emotionBreakdown.slice().sort((a, b) => Number(a.total_profit) - Number(b.total_profit))[0]?.emotion ?? null,
      most_profitable_mindset: emotionBreakdown.slice().sort((a, b) => Number(b.total_profit) - Number(a.total_profit))[0]?.emotion ?? null,
    },
    psychology_correlations: {
      confidence_buckets: [],
      stress_buckets: [],
      flags: {},
    },
  }
  args.riskStatus.value = {
    risk_percent_warning: false,
    loss_streak_caution: (args.streaks.value?.current_loss_streak ?? 0) >= 3,
    drawdown_banner: currentDrawdownPercent > 10,
    revenge_behavior_flag: sorted.some((trade) => trade.emotion === 'revenge'),
    latest_risk_percent: Number(sorted[sorted.length - 1]?.risk_percent ?? 0),
    max_risk_percent: sorted.reduce((max, trade) => Math.max(max, Number(trade.risk_percent ?? 0)), 0),
    current_loss_streak: args.streaks.value?.current_loss_streak ?? 0,
    current_drawdown_percent: currentDrawdownPercent,
    revenge_after_loss_events: [],
    warnings: [],
  }
}

function buildRankingRows(
  map: Map<string, Trade[]>,
  kind: 'session' | 'killzone' | 'setup' | 'strategy_model' | 'symbol'
): RankingRow[] {
  return [...map.entries()]
    .map(([name, rows]) => {
      const totalTrades = rows.length
      const wins = rows.filter((row) => Number(row.profit_loss) > 0).length
      const totalPnl = rows.reduce((sum, row) => sum + Number(row.profit_loss || 0), 0)
      const totalProfit = rows
        .filter((row) => Number(row.profit_loss || 0) > 0)
        .reduce((sum, row) => sum + Number(row.profit_loss || 0), 0)
      const totalLossAbs = Math.abs(rows
        .filter((row) => Number(row.profit_loss || 0) < 0)
        .reduce((sum, row) => sum + Number(row.profit_loss || 0), 0))
      const result: RankingRow = {
        total_trades: totalTrades,
        win_rate: totalTrades > 0 ? Number(((wins / totalTrades) * 100).toFixed(2)) : 0,
        profit_factor: totalLossAbs > 0 ? Number((totalProfit / totalLossAbs).toFixed(2)) : null,
        expectancy: totalTrades > 0 ? Number((totalPnl / totalTrades).toFixed(2)) : 0,
        total_pnl: Number(totalPnl.toFixed(2)),
        avg_r: totalTrades > 0 ? Number((rows.reduce((sum, row) => sum + Number(row.r_multiple ?? 0), 0) / totalTrades).toFixed(3)) : 0,
      }
      if (kind === 'session') result.session = name
      if (kind === 'killzone') result.killzone = name
      if (kind === 'setup') result.setup = name
      if (kind === 'strategy_model') result.strategy_model = name
      if (kind === 'symbol') result.symbol = name
      return result
    })
    .sort((left, right) => right.expectancy - left.expectancy)
}

function buildStreakPayload(rows: Trade[]): StreakPayload {
  let longestWin = 0
  let longestLoss = 0
  let currentWin = 0
  let currentLoss = 0

  for (const row of rows) {
    const pnl = Number(row.profit_loss || 0)
    if (pnl > 0) {
      currentWin += 1
      currentLoss = 0
      longestWin = Math.max(longestWin, currentWin)
    } else if (pnl < 0) {
      currentLoss += 1
      currentWin = 0
      longestLoss = Math.max(longestLoss, currentLoss)
    } else {
      currentWin = 0
      currentLoss = 0
    }
  }

  const type: 'win' | 'loss' | 'flat' = currentWin > 0 ? 'win' : currentLoss > 0 ? 'loss' : 'flat'
  return {
    longest_win_streak: longestWin,
    longest_loss_streak: longestLoss,
    current_win_streak: currentWin,
    current_loss_streak: currentLoss,
    current_streak: {
      type,
      length: Math.max(currentWin, currentLoss),
    },
  }
}

function toIsoDateKey(value: string): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return new Date().toISOString().slice(0, 10)
  return parsed.toISOString().slice(0, 10)
}

function applyEmptyAnalyticsState(args: {
  overview: Ref<AnalyticsOverview | null>
  dailyStats: Ref<AnalyticsDailyRow[]>
  performanceProfile: Ref<PerformanceProfile | null>
  equity: Ref<EquityPayload | null>
  drawdown: Ref<DrawdownPayload | null>
  streaks: Ref<StreakPayload | null>
  metrics: Ref<MetricsPayload | null>
  rankings: Ref<RankingsPayload | null>
  monthlyHeatmap: Ref<MonthlyHeatmapPayload | null>
  riskStatus: Ref<RiskStatusPayload | null>
  behavioral: Ref<BehavioralPayload | null>
}) {
  args.overview.value = {
    total_trades: 0,
    win_rate: 0,
    total_profit: 0,
    total_loss: 0,
    profit_factor: null,
    return_on_equity_pct: 0,
    expectancy: 0,
    average_r: 0,
    recovery_factor: null,
  }
  args.dailyStats.value = []
  args.performanceProfile.value = {
    win_rate: 0,
    avg_rr: 0,
    profit_factor: null,
    consistency_score: 0,
    recovery_factor: null,
    sharpe_ratio: null,
  }
  args.equity.value = {
    equity_points: [],
    cumulative_profit: [],
    equity_timestamps: [],
  }
  args.drawdown.value = {
    max_drawdown: 0,
    max_drawdown_percent: 0,
    current_drawdown: 0,
    current_drawdown_percent: 0,
    peak_balance: 0,
    current_equity: 0,
  }
  args.streaks.value = {
    longest_win_streak: 0,
    longest_loss_streak: 0,
    current_win_streak: 0,
    current_loss_streak: 0,
    current_streak: { type: 'flat', length: 0 },
  }
  args.metrics.value = {
    total_trades: 0,
    wins: 0,
    losses: 0,
    breakeven: 0,
    win_rate: 0,
    loss_rate: 0,
    average_win: 0,
    average_loss: 0,
    total_winning_amount: 0,
    total_losing_amount: 0,
    net_profit: 0,
    profit_factor: null,
    expectancy: 0,
    expectancy_money: 0,
    expectancy_r: 0,
    payoff_ratio: null,
    recovery_factor: null,
    average_r: 0,
    avg_r: 0,
    avg_r_realized: 0,
    avg_rr_planned: 0,
    sharpe_ratio: null,
  }
  args.rankings.value = {
    sessions: [],
    killzones: [],
    setups: [],
    strategy_models: [],
    symbols: [],
  }
  args.monthlyHeatmap.value = {
    months: [],
    max_abs_daily_pnl: 0,
  }
  args.behavioral.value = {
    discipline_comparison: {
      followed_rules: { expectancy: 0, win_rate: 0, total_pnl: 0 },
      broke_rules: { expectancy: 0, win_rate: 0, total_pnl: 0 },
      insight: {
        when_follow_rules: 'No trade data in selected range.',
        when_break_rules: 'No trade data in selected range.',
      },
    },
    emotion_analytics: {
      breakdown: [],
      most_costly_emotion: null,
      most_profitable_mindset: null,
    },
    psychology_correlations: {
      confidence_buckets: [],
      stress_buckets: [],
      flags: {},
    },
  }
  args.riskStatus.value = {
    risk_percent_warning: false,
    loss_streak_caution: false,
    drawdown_banner: false,
    revenge_behavior_flag: false,
    latest_risk_percent: 0,
    max_risk_percent: 0,
    current_loss_streak: 0,
    current_drawdown_percent: 0,
    revenge_after_loss_events: [],
    warnings: [],
  }
}
