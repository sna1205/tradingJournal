export type TradeDirection = 'buy' | 'sell'

export interface Trade {
  id: number
  pair: string
  direction: TradeDirection
  entry_price: string
  stop_loss: string
  take_profit: string
  lot_size: string
  profit_loss: string
  rr: string
  session: string
  model: string
  date: string
  notes: string | null
  created_at: string
  updated_at: string
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
