import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import { useAccountStore } from '@/stores/accountStore'
import type { SummaryStats } from '@/types/trade'

export interface AnalyticsOverview {
  total_trades: number
  win_rate: number
  total_profit: number
  total_loss: number
  profit_factor: number | null
  returns_percent: number
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
  recovery_factor: number | null
  average_r: number
  avg_r: number
  sharpe_ratio: number | null
}

export interface RankingRow {
  session?: string
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

  async function fetchAnalytics(filters?: AnalyticsRangeFilters) {
    loading.value = true
    const params = analyticsQueryParams(accountStore.selectedAccountId, filters)

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

      overview.value = {
        ...overviewRes.data,
        total_trades: Number(overviewRes.data.total_trades || 0),
        win_rate: Number(overviewRes.data.win_rate || 0),
        total_profit: Number(overviewRes.data.total_profit || 0),
        total_loss: Number(overviewRes.data.total_loss || 0),
        profit_factor: overviewRes.data.profit_factor === null ? null : Number(overviewRes.data.profit_factor),
        returns_percent: Number(overviewRes.data.returns_percent || 0),
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
        recovery_factor: metricsRes.data.recovery_factor === null ? null : Number(metricsRes.data.recovery_factor),
        average_r: Number(metricsRes.data.average_r || 0),
        avg_r: Number(metricsRes.data.avg_r || 0),
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
