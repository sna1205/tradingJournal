export type AccountType = 'funded' | 'personal' | 'demo'

export interface Account {
  id: number
  user_id: number | null
  name: string
  broker: string
  account_type: AccountType
  starting_balance: string
  current_balance: string
  currency: string
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface AccountEquityPayload {
  account_id: number
  equity_points: number[]
  equity_timestamps: string[]
  max_drawdown: number
  peak_balance: number
  net_profit: number
}

export interface AccountAnalyticsPayload {
  account_id: number
  win_rate: number
  profit_factor: number | null
  expectancy: number
  max_drawdown: number
  max_drawdown_percent: number
  recovery_factor: number | null
  average_r: number
  longest_streak: {
    type: 'win' | 'loss' | 'flat'
    length: number
  }
  longest_win_streak: number
  longest_loss_streak: number
  total_trades: number
  net_profit: number
}
