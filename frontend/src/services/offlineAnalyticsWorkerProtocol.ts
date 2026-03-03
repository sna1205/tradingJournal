import type { Trade } from '@/types/trade'

export interface OfflineAnalyticsWorkerFilter {
  date_from?: string
  date_to?: string
  include_drafts_unverified?: boolean
}

export interface OfflineAnalyticsWorkerAccount {
  id: number
  starting_balance: string
}

export interface OfflineAnalyticsWorkerPayload {
  trades: Trade[]
  accounts: OfflineAnalyticsWorkerAccount[]
  selectedAccountId: number | null
  filters?: OfflineAnalyticsWorkerFilter
}

export interface OfflineAnalyticsFallbackSnapshot {
  overview: Record<string, unknown>
  dailyStats: Array<Record<string, unknown>>
  performanceProfile: Record<string, unknown>
  equity: Record<string, unknown>
  drawdown: Record<string, unknown>
  streaks: Record<string, unknown>
  metrics: Record<string, unknown>
  rankings: Record<string, unknown>
  monthlyHeatmap: Record<string, unknown>
  riskStatus: Record<string, unknown>
  behavioral: Record<string, unknown>
}

export interface OfflineAnalyticsWorkerRequestMessage {
  id: number
  payload: OfflineAnalyticsWorkerPayload
}

export interface OfflineAnalyticsWorkerResponseMessage {
  id: number
  result?: OfflineAnalyticsFallbackSnapshot
  error?: string
}
