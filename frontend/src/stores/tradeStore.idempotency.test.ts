import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTradeStore, type TradePayload } from '@/stores/tradeStore'
import { setScope } from '@/services/storageScope'

const mocks = vi.hoisted(() => ({
  post: vi.fn(),
  shouldUseLocalFallback: vi.fn(() => false),
  upsertLocalTradeSnapshot: vi.fn(),
  markServerHealthy: vi.fn(),
  markLocalFallback: vi.fn(),
  refreshQueueState: vi.fn().mockResolvedValue(undefined),
  fetchAnalytics: vi.fn().mockResolvedValue(undefined),
  fetchAccounts: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('@/services/api', () => ({
  default: {
    post: mocks.post,
  },
}))

vi.mock('@/services/localFallback', () => ({
  createLocalTrade: vi.fn(),
  deleteLocalTrade: vi.fn(),
  deleteLocalTradeImage: vi.fn(),
  fetchLocalTradeDetails: vi.fn(),
  queryLocalTrades: vi.fn(() => ({
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
  })),
  setLocalTradeSyncStatus: vi.fn(),
  shouldUseLocalFallback: mocks.shouldUseLocalFallback,
  upsertLocalTradeSnapshot: mocks.upsertLocalTradeSnapshot,
  updateLocalTrade: vi.fn(),
  uploadLocalTradeImage: vi.fn(),
}))

vi.mock('@/services/offlineSyncQueue', () => ({
  enqueueSyncCreate: vi.fn(),
  enqueueSyncDelete: vi.fn(),
  enqueueSyncUpdate: vi.fn(),
}))

vi.mock('@/stores/syncStatusStore', () => ({
  useSyncStatusStore: () => ({
    markServerHealthy: mocks.markServerHealthy,
    markLocalFallback: mocks.markLocalFallback,
    refreshQueueState: mocks.refreshQueueState,
  }),
}))

vi.mock('@/stores/analyticsStore', () => ({
  useAnalyticsStore: () => ({
    fetchAnalytics: mocks.fetchAnalytics,
  }),
}))

vi.mock('@/stores/accountStore', () => ({
  useAccountStore: () => ({
    fetchAccounts: mocks.fetchAccounts,
  }),
}))

function buildPayload(): TradePayload {
  return {
    account_id: 1,
    instrument_id: 1,
    symbol: 'EURUSD',
    direction: 'buy',
    entry_price: 1.1,
    stop_loss: 1.09,
    take_profit: 1.12,
    actual_exit_price: 1.11,
    position_size: 0.1,
    followed_rules: true,
    emotion: 'calm',
    close_date: '2026-03-02T00:00:00Z',
    notes: null,
  }
}

describe('trade_store_create_trade_sends_idempotency_key_header', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    setScope({ userId: null, accountId: null })
    mocks.post.mockReset()
    mocks.shouldUseLocalFallback.mockReset()
    mocks.shouldUseLocalFallback.mockReturnValue(false)
    mocks.upsertLocalTradeSnapshot.mockReset()
    mocks.markServerHealthy.mockReset()
    mocks.markLocalFallback.mockReset()
    mocks.refreshQueueState.mockReset()
    mocks.refreshQueueState.mockResolvedValue(undefined)
    mocks.fetchAnalytics.mockReset()
    mocks.fetchAnalytics.mockResolvedValue(undefined)
    mocks.fetchAccounts.mockReset()
    mocks.fetchAccounts.mockResolvedValue(undefined)
  })

  it('sends Idempotency-Key header on createTrade', async () => {
    mocks.post.mockResolvedValue({
      data: {
        id: 101,
        local_sync_status: 'synced',
        risk_validation_status: 'verified',
      },
    })

    const store = useTradeStore()
    await store.createTrade(buildPayload())

    expect(mocks.post).toHaveBeenCalledTimes(1)
    expect(mocks.post).toHaveBeenCalledWith(
      '/trades',
      expect.any(Object),
      expect.objectContaining({
        headers: expect.objectContaining({
          'Idempotency-Key': expect.any(String),
        }),
      })
    )
  })

  it('strips prohibited client FX fields from create payload', async () => {
    mocks.post.mockResolvedValue({
      data: {
        id: 102,
        local_sync_status: 'synced',
        risk_validation_status: 'verified',
      },
    })

    const store = useTradeStore()
    await store.createTrade({
      ...buildPayload(),
      fx_rate_quote_to_usd: 1.2345,
      fx_symbol_used: 'EURUSD',
      fx_rate_timestamp: '2026-03-04T00:00:00Z',
    })

    expect(mocks.post).toHaveBeenCalledTimes(1)
    const sentPayload = mocks.post.mock.calls[0]?.[1] as Record<string, unknown>
    expect(sentPayload).not.toHaveProperty('fx_rate_quote_to_usd')
    expect(sentPayload).not.toHaveProperty('fx_symbol_used')
    expect(sentPayload).not.toHaveProperty('fx_rate_timestamp')
  })

  it('strips prohibited client FX fields from precheck payload', async () => {
    mocks.post.mockResolvedValue({
      data: {
        allowed: true,
        requires_override_reason: false,
        policy: {
          account_id: 1,
          max_risk_per_trade_pct: 1,
          max_daily_loss_pct: 5,
          max_total_drawdown_pct: 10,
          max_open_risk_pct: 2,
          enforce_hard_limits: true,
          allow_override: false,
        },
        violations: [],
        stats: {
          risk_percent: 0,
          monetary_risk: 0,
          daily_realized_loss: 0,
          projected_daily_loss: 0,
          projected_daily_loss_pct: 0,
          projected_drawdown: 0,
          projected_drawdown_pct: 0,
        },
        calculated: {
          monetary_risk: 0,
          monetary_reward: 0,
          gross_profit_loss: 0,
          costs_total: 0,
          profit_loss: 0,
          risk_percent: 0,
          r_multiple: 0,
          realized_r_multiple: 0,
          avg_entry_price: 0,
          avg_exit_price: 0,
          rr: 0,
        },
      },
    })

    const store = useTradeStore()
    await store.precheckTrade({
      ...buildPayload(),
      fx_rate_quote_to_usd: 1.111,
      fx_symbol_used: 'EURUSD',
      fx_rate_timestamp: '2026-03-04T00:00:00Z',
    })

    expect(mocks.post).toHaveBeenCalledTimes(1)
    const sentPayload = mocks.post.mock.calls[0]?.[1] as Record<string, unknown>
    expect(sentPayload).not.toHaveProperty('fx_rate_quote_to_usd')
    expect(sentPayload).not.toHaveProperty('fx_symbol_used')
    expect(sentPayload).not.toHaveProperty('fx_rate_timestamp')
  })
})
