import { expect, test } from '@playwright/test'

const seededAccounts = [
  {
    id: 1,
    user_id: null,
    name: 'Primary Account',
    broker: 'Local',
    account_type: 'personal',
    starting_balance: '10000.00',
    current_balance: '10050.00',
    currency: 'USD',
    is_active: true,
    created_at: '2026-01-01T00:00:00.000Z',
    updated_at: '2026-01-01T00:00:00.000Z',
  },
]

const seededTrades = [
  {
    id: 1,
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
    profit_loss: '50.00',
    rr: '2.00',
    r_multiple: '5.0000',
    risk_percent: '0.1000',
    account_balance_before_trade: '10000.00',
    account_balance_after_trade: '10050.00',
    followed_rules: true,
    emotion: 'calm',
    session: 'London',
    model: 'Breakout',
    date: '2026-01-05T10:00:00.000Z',
    notes: 'Seeded visual edit trade',
    local_sync_status: 'synced',
    risk_validation_status: 'verified',
    images: [],
    images_count: 0,
    created_at: '2026-01-05T10:05:00.000Z',
    updated_at: '2026-01-05T10:05:00.000Z',
    deleted_at: null,
  },
]

test.beforeEach(async ({ page }) => {
  await page.route('**/api/**', async (route) => {
    await route.abort('failed')
  })

  await page.goto('/__visual-regression')
  await page.evaluate(
    ({ accounts, trades }) => {
      localStorage.setItem('tj_local_accounts_v1', JSON.stringify(accounts))
      localStorage.setItem('tj_local_trades_v1', JSON.stringify(trades))
    },
    { accounts: seededAccounts, trades: seededTrades }
  )
})

test.describe('Key routes render on all visual projects', () => {
  test('dashboard renders', async ({ page }) => {
    await page.goto('/dashboard?visual=1')
    await page.waitForLoadState('networkidle')
    await expect(page.getByTestId('dashboard-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible()
  })

  test('accounts renders', async ({ page }) => {
    await page.goto('/accounts?visual=1')
    await page.waitForLoadState('networkidle')
    await expect(page.getByTestId('accounts-page')).toBeVisible()
    await expect(page.getByTestId('accounts-page').getByRole('heading', { name: 'Account Center' }).first()).toBeVisible()
  })

  test('trade create renders', async ({ page }) => {
    await page.goto('/trades/new?visual=1')
    await page.waitForLoadState('networkidle')
    await expect(page.getByTestId('trade-form-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'New Execute' })).toBeVisible()
  })

  test('trade edit renders', async ({ page }) => {
    await page.goto('/trades/1/edit?visual=1')
    await page.waitForLoadState('networkidle')
    await expect(page.getByTestId('trade-form-page')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Edit Execute' })).toBeVisible()
  })
})
