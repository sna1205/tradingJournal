import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTradeStore } from '@/stores/tradeStore'
import type { Trade, TradeLeg } from '@/types/trade'

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
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
    get: mocks.get,
    post: mocks.post,
    put: mocks.put,
    delete: mocks.delete,
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

function buildLeg(id: number): TradeLeg {
  return {
    id,
    trade_id: 0,
    leg_type: 'entry',
    price: '1.1000',
    quantity_lots: '0.10',
    executed_at: '2026-03-02T00:00:00Z',
    fees: '0',
    notes: null,
    created_at: '2026-03-02T00:00:00Z',
    updated_at: '2026-03-02T00:00:00Z',
  }
}

function buildTrade(id: number, revision: number, legs: TradeLeg[] = []): Trade {
  return {
    id,
    revision,
    account_id: 1,
    instrument_id: 1,
    strategy_model_id: 1,
    setup_id: 1,
    killzone_id: 1,
    session_enum: 'london',
    pair: 'EURUSD',
    direction: 'buy',
    entry_price: '1.1000',
    avg_entry_price: '1.1000',
    stop_loss: '1.0900',
    take_profit: '1.1200',
    actual_exit_price: '1.1100',
    avg_exit_price: '1.1100',
    lot_size: '0.10',
    risk_per_unit: '0.0100',
    reward_per_unit: '0.0200',
    monetary_risk: '100.00',
    monetary_reward: '200.00',
    gross_profit_loss: '100.00',
    costs_total: '0.00',
    commission: '0.00',
    swap: '0.00',
    spread_cost: '0.00',
    slippage_cost: '0.00',
    fx_rate_quote_to_usd: '1.0000',
    fx_symbol_used: null,
    fx_rate_timestamp: null,
    profit_loss: '100.00',
    rr: '2.00',
    r_multiple: '1.00',
    realized_r_multiple: '1.00',
    risk_percent: '1.00',
    account_balance_before_trade: '10000.00',
    account_balance_after_trade: '10100.00',
    followed_rules: true,
    checklist_incomplete: false,
    emotion: 'calm',
    risk_override_reason: null,
    session: 'London',
    model: 'General',
    date: '2026-03-02T00:00:00Z',
    notes: null,
    legs,
    tag_ids: [],
    images: [],
    images_count: 0,
    created_at: '2026-03-02T00:00:00Z',
    updated_at: '2026-03-02T00:00:00Z',
    local_sync_status: 'synced',
    risk_validation_status: 'verified',
  }
}

describe('trade store If-Match headers', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocks.get.mockReset()
    mocks.post.mockReset()
    mocks.put.mockReset()
    mocks.delete.mockReset()
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

  it('trade_store_update_trade_sends_if_match_header', async () => {
    const store = useTradeStore()
    store.trades = [buildTrade(11, 3)]
    mocks.put.mockResolvedValue({
      data: buildTrade(11, 4),
      headers: {},
    })

    await store.updateTrade(11, {
      notes: 'updated notes',
      fx_rate_quote_to_usd: 1.25,
      fx_symbol_used: 'EURUSD',
      fx_rate_timestamp: '2026-03-04T00:00:00Z',
    })

    expect(mocks.put).toHaveBeenCalledWith(
      '/trades/11',
      expect.not.objectContaining({
        fx_rate_quote_to_usd: expect.anything(),
        fx_symbol_used: expect.anything(),
        fx_rate_timestamp: expect.anything(),
      }),
      expect.objectContaining({
        headers: expect.objectContaining({
          'If-Match': '3',
        }),
      })
    )
  })

  it('trade_store_update_leg_sends_if_match_header', async () => {
    const store = useTradeStore()
    store.trades = [buildTrade(22, 7, [buildLeg(55)])]
    mocks.put.mockResolvedValue({
      data: {
        ...buildLeg(55),
        trade_id: 22,
      },
      headers: {
        etag: '"8:2026-03-02T00:00:01Z"',
      },
    })

    await store.updateTradeLeg(55, {
      leg_type: 'entry',
      price: 1.101,
      quantity_lots: 0.1,
      executed_at: '2026-03-02T00:00:00Z',
      fees: 0,
      notes: null,
    })

    expect(mocks.put).toHaveBeenCalledWith(
      '/trade-legs/55',
      expect.any(Object),
      expect.objectContaining({
        headers: expect.objectContaining({
          'If-Match': '7',
        }),
      })
    )
  })

  it('trade_store_delete_trade_sends_if_match_header', async () => {
    const store = useTradeStore()
    store.trades = [buildTrade(33, 5)]
    mocks.delete.mockResolvedValue({ status: 204, headers: {} })

    await store.deleteTrade(33)

    expect(mocks.delete).toHaveBeenCalledWith(
      '/trades/33',
      expect.objectContaining({
        headers: expect.objectContaining({
          'If-Match': '5',
        }),
      })
    )
  })
})
