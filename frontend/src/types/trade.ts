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
  account_id: number
  instrument_id?: number | null
  pair: string
  direction: TradeDirection
  entry_price: string
  avg_entry_price?: string | null
  stop_loss: string
  take_profit: string
  actual_exit_price: string | null
  avg_exit_price?: string | null
  lot_size: string
  risk_per_unit: string | null
  reward_per_unit: string | null
  monetary_risk: string | null
  monetary_reward: string | null
  gross_profit_loss?: string | null
  costs_total?: string | null
  commission?: string | null
  swap?: string | null
  spread_cost?: string | null
  slippage_cost?: string | null
  profit_loss: string
  rr: string
  r_multiple: string | null
  realized_r_multiple?: string | null
  risk_percent: string | null
  account_balance_before_trade: string | null
  account_balance_after_trade: string | null
  followed_rules: boolean
  emotion: TradeEmotion
  risk_override_reason?: string | null
  session: string
  model: string
  date: string
  notes: string | null
  legs?: TradeLeg[]
  images?: TradeImage[]
  images_count?: number
  account?: {
    id: number
    name: string
    account_type: 'funded' | 'personal' | 'demo'
    current_balance?: string
    currency?: string
  } | null
  instrument?: Instrument | null
  created_at: string
  updated_at: string
  deleted_at?: string | null
}

export interface TradeLeg {
  id?: number
  trade_id?: number
  leg_type: 'entry' | 'exit'
  price: string
  quantity_lots: string
  executed_at: string
  fees?: string | null
  notes?: string | null
  created_at?: string
  updated_at?: string
}

export interface Instrument {
  id: number
  symbol: string
  asset_class: string
  base_currency: string
  quote_currency: string
  contract_size: string
  tick_size: string
  tick_value: string
  pip_size: string
  min_lot: string
  lot_step: string
  is_active: boolean
}

export interface TradeImage {
  id: number
  image_url: string
  thumbnail_url: string
  file_size: number
  file_type: string
  sort_order: number
}

export interface TradeDetailsResponse {
  trade: Trade
  legs?: TradeLeg[]
  images: TradeImage[]
}

export interface MissedTrade {
  id: number
  pair: string
  model: string
  reason: string
  date: string
  notes: string | null
  images?: MissedTradeImage[]
  images_count?: number
  created_at: string
  updated_at: string
}

export interface MissedTradeImage {
  id: number
  image_url: string
  thumbnail_url: string
  file_size: number
  file_type: string
  sort_order: number
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
