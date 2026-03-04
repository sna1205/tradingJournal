/// <reference lib="webworker" />
import type { Trade } from '@/types/trade'
import type {
  OfflineAnalyticsFallbackSnapshot,
  OfflineAnalyticsWorkerPayload,
  OfflineAnalyticsWorkerRequestMessage,
  OfflineAnalyticsWorkerResponseMessage,
} from '@/services/offlineAnalyticsWorkerProtocol'

interface RankingRowShape {
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

const scope = self as DedicatedWorkerGlobalScope

scope.onmessage = (event: MessageEvent<OfflineAnalyticsWorkerRequestMessage>) => {
  const { id, payload } = event.data
  try {
    const result = buildOfflineAnalyticsSnapshot(payload)
    const response: OfflineAnalyticsWorkerResponseMessage = { id, result }
    scope.postMessage(response)
  } catch (error) {
    const response: OfflineAnalyticsWorkerResponseMessage = {
      id,
      error: error instanceof Error ? error.message : 'Failed to aggregate offline analytics.',
    }
    scope.postMessage(response)
  }
}

function buildOfflineAnalyticsSnapshot(payload: OfflineAnalyticsWorkerPayload): OfflineAnalyticsFallbackSnapshot {
  const filteredTrades = filterTrades(payload)
  const sorted = filteredTrades
    .slice()
    .sort((left, right) => left.date.localeCompare(right.date) || left.id - right.id)

  if (sorted.length === 0) {
    return emptySnapshot()
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

  const startingBalance = payload.selectedAccountId === null
    ? payload.accounts.reduce((sum, account) => sum + Number(account.starting_balance || 0), 0)
    : Number(payload.accounts.find((account) => account.id === payload.selectedAccountId)?.starting_balance || 0)

  const dailyStats = [...dayMap.entries()]
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
  for (const day of dailyStats) {
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
  const streaks = buildStreakPayload(sorted)

  const emotionBreakdown = [...emotionMap.entries()].map(([emotion, value]) => ({
    emotion,
    total_trades: value.total_trades,
    total_profit: Number(value.total_profit.toFixed(2)),
  }))

  return {
    overview: {
      total_trades: totalTrades,
      win_rate: winRate,
      total_profit: Number(totalProfit.toFixed(2)),
      total_loss: Number(totalLossAbs.toFixed(2)),
      profit_factor: profitFactor,
      return_on_equity_pct: startingBalance > 0 ? Number(((netProfit / startingBalance) * 100).toFixed(4)) : 0,
      expectancy,
      average_r: avgR,
      recovery_factor: maxDrawdown > 0 ? Number((netProfit / maxDrawdown).toFixed(2)) : null,
    },
    dailyStats,
    performanceProfile: {
      win_rate: winRate,
      avg_rr: avgR,
      profit_factor: profitFactor,
      consistency_score: totalTrades > 0 ? Number(((winRate / 100) * 10).toFixed(2)) : 0,
      recovery_factor: maxDrawdown > 0 ? Number((netProfit / maxDrawdown).toFixed(2)) : null,
      sharpe_ratio: null,
    },
    equity: {
      equity_points: equityPoints,
      cumulative_profit: cumulativeProfit,
      equity_timestamps: equityTimestamps,
    },
    drawdown: {
      max_drawdown: Number(maxDrawdown.toFixed(2)),
      max_drawdown_percent: maxDrawdownPercent,
      current_drawdown: Number(currentDrawdown.toFixed(2)),
      current_drawdown_percent: currentDrawdownPercent,
      peak_balance: Number(peakBalance.toFixed(2)),
      current_equity: Number(currentEquity.toFixed(2)),
    },
    streaks,
    metrics: {
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
    },
    rankings: {
      sessions: buildRankingRows(sessionMap, 'session'),
      killzones: buildRankingRows(killzoneMap, 'killzone'),
      setups: buildRankingRows(setupMap, 'setup'),
      strategy_models: buildRankingRows(modelMap, 'strategy_model'),
      symbols: buildRankingRows(symbolMap, 'symbol'),
    },
    monthlyHeatmap: {
      months: [],
      max_abs_daily_pnl: dailyStats.reduce((max, row) => Math.max(max, Math.abs(row.profit_loss)), 0),
    },
    riskStatus: {
      risk_percent_warning: false,
      loss_streak_caution: (streaks.current_loss_streak ?? 0) >= 3,
      drawdown_banner: currentDrawdownPercent > 10,
      revenge_behavior_flag: sorted.some((trade) => trade.emotion === 'revenge'),
      latest_risk_percent: Number(sorted[sorted.length - 1]?.risk_percent ?? 0),
      max_risk_percent: sorted.reduce((max, trade) => Math.max(max, Number(trade.risk_percent ?? 0)), 0),
      current_loss_streak: streaks.current_loss_streak ?? 0,
      current_drawdown_percent: currentDrawdownPercent,
      revenge_after_loss_events: [],
      warnings: [],
    },
    behavioral: {
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
    },
  }
}

function filterTrades(payload: OfflineAnalyticsWorkerPayload): Trade[] {
  const accountId = payload.selectedAccountId
  const from = payload.filters?.date_from
  const to = payload.filters?.date_to
  const includeDrafts = payload.filters?.include_drafts_unverified === true

  return payload.trades.filter((trade) => {
    if (accountId !== null && trade.account_id !== accountId) return false

    const day = toIsoDateKey(trade.date)
    if (from && day < from) return false
    if (to && day > to) return false

    if (!includeDrafts) {
      if ((trade.local_sync_status ?? 'synced') !== 'synced') return false
      if ((trade.risk_validation_status ?? 'verified') !== 'verified') return false
    }
    return true
  })
}

function buildRankingRows(
  map: Map<string, Trade[]>,
  kind: 'session' | 'killzone' | 'setup' | 'strategy_model' | 'symbol'
): RankingRowShape[] {
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

      const result: RankingRowShape = {
        total_trades: totalTrades,
        win_rate: totalTrades > 0 ? Number(((wins / totalTrades) * 100).toFixed(2)) : 0,
        profit_factor: totalLossAbs > 0 ? Number((totalProfit / totalLossAbs).toFixed(2)) : null,
        expectancy: totalTrades > 0 ? Number((totalPnl / totalTrades).toFixed(2)) : 0,
        total_pnl: Number(totalPnl.toFixed(2)),
        avg_r: totalTrades > 0
          ? Number((rows.reduce((sum, row) => sum + Number(row.r_multiple ?? 0), 0) / totalTrades).toFixed(3))
          : 0,
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

function buildStreakPayload(rows: Trade[]) {
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

function emptySnapshot(): OfflineAnalyticsFallbackSnapshot {
  return {
    overview: {
      total_trades: 0,
      win_rate: 0,
      total_profit: 0,
      total_loss: 0,
      profit_factor: null,
      return_on_equity_pct: 0,
      expectancy: 0,
      average_r: 0,
      recovery_factor: null,
    },
    dailyStats: [],
    performanceProfile: {
      win_rate: 0,
      avg_rr: 0,
      profit_factor: null,
      consistency_score: 0,
      recovery_factor: null,
      sharpe_ratio: null,
    },
    equity: {
      equity_points: [],
      cumulative_profit: [],
      equity_timestamps: [],
    },
    drawdown: {
      max_drawdown: 0,
      max_drawdown_percent: 0,
      current_drawdown: 0,
      current_drawdown_percent: 0,
      peak_balance: 0,
      current_equity: 0,
    },
    streaks: {
      longest_win_streak: 0,
      longest_loss_streak: 0,
      current_win_streak: 0,
      current_loss_streak: 0,
      current_streak: { type: 'flat', length: 0 },
    },
    metrics: {
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
    },
    rankings: {
      sessions: [],
      killzones: [],
      setups: [],
      strategy_models: [],
      symbols: [],
    },
    monthlyHeatmap: {
      months: [],
      max_abs_daily_pnl: 0,
    },
    behavioral: {
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
    },
    riskStatus: {
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
    },
  }
}
