import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import type { SummaryStats } from '@/types/trade'

export interface AnalyticsOverview {
  total_trades: number
  win_rate: number
  total_profit: number
  total_loss: number
  profit_factor: number | null
  returns_percent: number
}

export interface AnalyticsDailyRow {
  date: string
  total_trades: number
  profit_loss: number
}

export interface PerformanceProfile {
  win_rate: number
  avg_rr: number
  profit_factor: number | null
  consistency_score: number
  recovery_factor: number | null
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
  const overview = ref<AnalyticsOverview | null>(null)
  const dailyStats = ref<AnalyticsDailyRow[]>([])
  const performanceProfile = ref<PerformanceProfile | null>(null)
  const loading = ref(false)

  // Legacy compatibility for existing screens that still consume these keys.
  const bySymbol = ref<Array<{ symbol: string; pnl: number; trade_count: number }>>([])
  const bySetup = ref<Array<{ setup: string; pnl: number; trade_count: number }>>([])
  const bestSymbol = computed(() => bySymbol.value[0] ?? null)

  const equityCurve = computed(() => {
    const points: Array<{ date: string; equity: number }> = []
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
      expectancy: closedTrades > 0 ? Number((totalPnl / closedTrades).toFixed(2)) : 0,
    }
  })

  async function fetchAnalytics() {
    loading.value = true

    try {
      const [overviewRes, dailyRes, profileRes] = await Promise.all([
        api.get<AnalyticsOverview>('/analytics/overview'),
        api.get<AnalyticsDailyRow[]>('/analytics/daily'),
        api.get<PerformanceProfile>('/analytics/performance-profile'),
      ])

      overview.value = {
        ...overviewRes.data,
        total_trades: Number(overviewRes.data.total_trades || 0),
        win_rate: Number(overviewRes.data.win_rate || 0),
        total_profit: Number(overviewRes.data.total_profit || 0),
        total_loss: Number(overviewRes.data.total_loss || 0),
        profit_factor: overviewRes.data.profit_factor === null ? null : Number(overviewRes.data.profit_factor),
        returns_percent: Number(overviewRes.data.returns_percent || 0),
      }

      dailyStats.value = dailyRes.data.map((row) => ({
        date: row.date,
        total_trades: Number(row.total_trades || 0),
        profit_loss: Number(row.profit_loss || 0),
      }))

      performanceProfile.value = {
        ...profileRes.data,
        win_rate: Number(profileRes.data.win_rate || 0),
        avg_rr: Number(profileRes.data.avg_rr || 0),
        profit_factor: profileRes.data.profit_factor === null ? null : Number(profileRes.data.profit_factor),
        consistency_score: Number(profileRes.data.consistency_score || 0),
        recovery_factor: profileRes.data.recovery_factor === null ? null : Number(profileRes.data.recovery_factor),
      }
    } finally {
      loading.value = false
    }
  }

  // Backward compatibility for existing pages/components.
  const daily = dailyStats
  const fetchAll = fetchAnalytics

  return {
    overview,
    dailyStats,
    daily,
    performanceProfile,
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
