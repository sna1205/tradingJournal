import { expect, test } from '@playwright/test'

const scopedAccountsKey = 'tj:v3:u:anon:a:all:local-fallback:accounts_v1'
const scopedTradesKey = 'tj:v3:u:anon:a:all:local-fallback:trades_v1'
const offlineModeKey = 'tj_offline_mode_enabled'

const seededAccounts = [
  {
    id: 1,
    user_id: null,
    name: 'Primary Account',
    broker: 'Local',
    account_type: 'personal',
    starting_balance: '10000.00',
    current_balance: '10000.00',
    currency: 'USD',
    is_active: true,
    created_at: '2026-01-01T00:00:00.000Z',
    updated_at: '2026-01-01T00:00:00.000Z',
  },
]

const seededTrades = [
  {
    id: 101,
    account_id: 1,
    pair: 'EURUSD',
    direction: 'buy',
    entry_price: '1.100000',
    stop_loss: '1.099000',
    take_profit: '1.102000',
    actual_exit_price: '1.101000',
    lot_size: '0.1000',
    risk_per_unit: '0.001000',
    reward_per_unit: '0.002000',
    monetary_risk: '10.000000',
    monetary_reward: '20.000000',
    profit_loss: '25.00',
    rr: '2.00',
    r_multiple: '2.5000',
    risk_percent: '0.1000',
    account_balance_before_trade: '10000.00',
    account_balance_after_trade: '10025.00',
    followed_rules: true,
    emotion: 'calm',
    session: 'London',
    model: 'Breakout',
    date: '2026-01-05T10:00:00.000Z',
    notes: 'Verified seed trade',
    local_sync_status: 'synced',
    risk_validation_status: 'verified',
    images: [],
    images_count: 0,
    created_at: '2026-01-05T10:05:00.000Z',
    updated_at: '2026-01-05T10:05:00.000Z',
    deleted_at: null,
  },
  {
    id: 102,
    account_id: 1,
    pair: 'GBPUSD',
    direction: 'sell',
    entry_price: '1.260000',
    stop_loss: '1.262000',
    take_profit: '1.255000',
    actual_exit_price: '1.261500',
    lot_size: '0.1000',
    risk_per_unit: '0.002000',
    reward_per_unit: '0.005000',
    monetary_risk: '20.000000',
    monetary_reward: '50.000000',
    profit_loss: '-15.00',
    rr: '2.50',
    r_multiple: '-0.7500',
    risk_percent: '0.2000',
    account_balance_before_trade: '10025.00',
    account_balance_after_trade: '10010.00',
    followed_rules: false,
    emotion: 'fearful',
    session: 'New York',
    model: 'Pullback',
    date: '2026-01-06T12:00:00.000Z',
    notes: 'Draft and unverified seed trade',
    local_sync_status: 'draft_local',
    risk_validation_status: 'unverified',
    images: [],
    images_count: 0,
    created_at: '2026-01-06T12:05:00.000Z',
    updated_at: '2026-01-06T12:05:00.000Z',
    deleted_at: null,
  },
]

function envelope<T>(data: T) {
  return JSON.stringify({
    created_at: '2026-01-01T00:00:00.000Z',
    expire_at: null,
    data,
  })
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/**', async (route) => {
    await route.abort('failed')
  })

  await page.addInitScript(
    ({ accounts, trades, accountsKey, tradesKey, offlineKey }) => {
      localStorage.setItem(offlineKey, '1')
      localStorage.setItem(accountsKey, accounts)
      localStorage.setItem(tradesKey, trades)
    },
    {
      accounts: envelope(seededAccounts),
      trades: envelope(seededTrades),
      accountsKey: scopedAccountsKey,
      tradesKey: scopedTradesKey,
      offlineKey: offlineModeKey,
    }
  )
  await page.goto('/__visual-regression?visual=1')
})

test('trade log excludes drafts/unverified by default and includes them when toggled', async ({ page }) => {
  await page.goto('/trades?visual=1')
  await page.waitForLoadState('networkidle')

  await expect(page.getByTestId('trade-log-page')).toBeVisible()
  await expect(page.getByText('EURUSD').first()).toBeVisible()
  await expect(page.getByText('GBPUSD')).toHaveCount(0)

  await page.getByTestId('trade-quality-toggle').click()
  await expect(page.getByText('GBPUSD').first()).toBeVisible()
})
