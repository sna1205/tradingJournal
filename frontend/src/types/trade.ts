export type TradeDirection = 'buy' | 'sell'
export type TradeEmotion =
  | 'neutral'
  | 'calm'
  | 'confident'
  | 'fearful'
  | 'greedy'
  | 'hesitant'
  | 'revenge'

export interface Trade {
  id: number
  pair: string
  direction: TradeDirection
  entry_price: string
  stop_loss: string
  take_profit: string
  actual_exit_price: string | null
  lot_size: string
  risk_per_unit: string | null
  reward_per_unit: string | null
  monetary_risk: string | null
  monetary_reward: string | null
  profit_loss: string
  rr: string
  r_multiple: string | null
  risk_percent: string | null
  account_balance_before_trade: string | null
  account_balance_after_trade: string | null
  followed_rules: boolean
  emotion: TradeEmotion
  session: string
  model: string
  date: string
  notes: string | null
  created_at: string
  updated_at: string
  deleted_at?: string | null
}

export interface MissedTrade {
  id: number
  pair: string
  model: string
  reason: string
  date: string
  notes: string | null
  created_at: string
  updated_at: string
}

export interface Paginated<T> {
  current_page: number
  data: T[]
  last_page: number
  per_page: number
  total: number
}

export interface SummaryStats {
  closed_trades: number
  winning_trades: number
  losing_trades: number
  breakeven_trades: number
  win_rate: number
  total_pnl: number
  gross_profit: number
  gross_loss: number
  avg_win: number
  avg_loss: number
  profit_factor: number | null
  expectancy: number
}
