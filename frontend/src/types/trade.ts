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
  strategy_model_id?: number | null
  setup_id?: number | null
  killzone_id?: number | null
  session_enum?: SessionEnum | null
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
  fx_rate_quote_to_usd?: string | null
  fx_symbol_used?: string | null
  fx_rate_timestamp?: string | null
  profit_loss: string
  rr: string
  r_multiple: string | null
  realized_r_multiple?: string | null
  risk_percent: string | null
  account_balance_before_trade: string | null
  account_balance_after_trade: string | null
  followed_rules: boolean
  checklist_incomplete?: boolean
  executed_checklist_id?: number | null
  executed_checklist_version?: number | null
  executed_enforcement_mode?: 'strict' | 'soft' | 'off' | null
  failed_rule_ids?: number[] | null
  failed_rule_titles?: string[] | null
  check_evaluated_at?: string | null
  emotion: TradeEmotion
  risk_override_reason?: string | null
  session: string
  model: string
  date: string
  notes: string | null
  legs?: TradeLeg[]
  tag_ids?: number[]
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
  strategy_model?: TaxonomyItem | null
  setup?: TaxonomyItem | null
  killzone?: KillzoneItem | null
  tags?: TradeTag[]
  psychology?: TradePsychology | null
  created_at: string
  updated_at: string
  deleted_at?: string | null
  local_sync_status?: 'draft_local' | 'pending_sync' | 'synced' | 'conflict'
  risk_validation_status?: 'verified' | 'unverified'
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

export interface FxRate {
  id: number
  from_currency: string
  to_currency: string
  rate: string
  rate_updated_at?: string | null
  created_at?: string
  updated_at?: string
}

export interface TradeImage {
  id: number
  image_url: string
  thumbnail_url: string
  file_size: number
  file_type: string
  sort_order: number
  context_tag?: ImageContextTag | null
  timeframe?: string | null
  annotation_notes?: string | null
}

export interface TradeDetailsResponse {
  trade: Trade
  legs?: TradeLeg[]
  psychology?: TradePsychology | null
  images: TradeImage[]
}

export type SessionEnum = 'asia' | 'london' | 'new_york' | 'overlap' | 'off_session'
export type ImageContextTag = 'pre_entry' | 'entry' | 'management' | 'exit' | 'post_review'

export interface TaxonomyItem {
  id: number
  name: string
  slug: string
  description?: string | null
  is_active: boolean
}

export interface KillzoneItem extends TaxonomyItem {
  session_enum: SessionEnum
}

export interface TradeTag extends TaxonomyItem {
  color?: string | null
}

export interface TradePsychology {
  trade_id: number
  pre_emotion: string | null
  post_emotion: string | null
  confidence_score: number | null
  stress_score: number | null
  sleep_hours: string | number | null
  impulse_flag: boolean
  fomo_flag: boolean
  revenge_flag: boolean
  notes: string | null
}

export interface SessionOption {
  value: SessionEnum
  label: string
}

export interface SavedReport {
  id: number
  name: string
  scope: 'trades' | 'dashboard'
  filters_json: Record<string, unknown>
  columns_json: string[] | null
  is_default: boolean
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
