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

export type ChallengeStatus = 'active' | 'passed' | 'failed' | 'paused'

export interface AccountChallenge {
  id: number
  account_id: number
  provider: string
  phase: string
  starting_balance: string
  profit_target_pct: string
  max_daily_loss_pct: string
  max_total_drawdown_pct: string
  min_trading_days: number
  start_date: string
  status: ChallengeStatus
  passed_at: string | null
  failed_at: string | null
  created_at: string
  updated_at: string
}

export interface AccountChallengeStatusPayload {
  account_id: number
  challenge_id: number
  provider: string
  phase: string
  start_date: string
  status: ChallengeStatus
  risk_state: 'pass' | 'fail' | 'in_progress'
  target_progress: {
    net_profit: number
    target_profit: number
    remaining: number
    progress_pct: number
    met: boolean
  }
  daily_loss_headroom: {
    limit: number
    used: number
    headroom: number
    worst_used: number
    breached: boolean
  }
  total_dd_headroom: {
    limit: number
    used: number
    headroom: number
    breached: boolean
  }
  min_days_progress: {
    required: number
    actual: number
    remaining: number
    progress_pct: number
    met: boolean
  }
  evaluated_through: string
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
