import { describe, expect, it } from 'vitest'
import type { FxRateLike } from '@/services/fxToUsdService'
import { calculateLotSize, type LotSizeInstrumentSpec } from '@/utils/lotSizeEngine'

const FX_RATES: FxRateLike[] = [
  { from_currency: 'USD', to_currency: 'JPY', rate: '150.0000000000' },
  { from_currency: 'GBP', to_currency: 'USD', rate: '1.2700000000' },
  { from_currency: 'EUR', to_currency: 'USD', rate: '1.0800000000' },
]

const BASE_INPUT = {
  account_balance: '10000',
  risk_mode: 'percent' as const,
  risk_percent: '1',
  risk_amount_fixed: '100',
  direction: 'long' as const,
  take_profit: '',
  spread_ticks: '0',
  commission_per_lot: '0',
  slippage_ticks: '0',
  leverage: '100',
}

const EURUSD: LotSizeInstrumentSpec = {
  symbol: 'EURUSD',
  asset_class: 'forex',
  base_currency: 'EUR',
  quote_currency: 'USD',
  contract_size: '100000',
  tick_size: '0.00001',
  pip_size: '0.0001',
  lot_step: '0.01',
  min_lot: '0.01',
}

const EURJPY: LotSizeInstrumentSpec = {
  symbol: 'EURJPY',
  asset_class: 'forex',
  base_currency: 'EUR',
  quote_currency: 'JPY',
  contract_size: '100000',
  tick_size: '0.001',
  pip_size: '0.01',
  lot_step: '0.01',
  min_lot: '0.01',
}

const EURGBP: LotSizeInstrumentSpec = {
  symbol: 'EURGBP',
  asset_class: 'forex',
  base_currency: 'EUR',
  quote_currency: 'GBP',
  contract_size: '100000',
  tick_size: '0.00001',
  pip_size: '0.0001',
  lot_step: '0.01',
  min_lot: '0.01',
}

const XAUUSD: LotSizeInstrumentSpec = {
  symbol: 'XAUUSD',
  asset_class: 'commodities',
  base_currency: 'XAU',
  quote_currency: 'USD',
  contract_size: '100',
  tick_size: '0.01',
  pip_size: '0.1',
  lot_step: '0.01',
  min_lot: '0.01',
}

function runCase(
  instrument: LotSizeInstrumentSpec,
  entry_price: string,
  stop_loss: string,
  fx_rates: FxRateLike[] = FX_RATES
) {
  return calculateLotSize({
    ...BASE_INPUT,
    entry_price,
    stop_loss,
    instrument,
    fx_rates,
  })
}

describe('calculateLotSize FX-aware sizing', () => {
  it('EURUSD uses USD quote without conversion', () => {
    const result = runCase(EURUSD, '1.1000', '1.0990')

    expect(result.valid).toBe(true)
    expect(result.conversion_rate_quote_to_usd).toBe(1)
    expect(result.risk_per_one_lot).toBeCloseTo(100, 6)
    expect(result.lot_size).toBeCloseTo(1, 8)
    expect(result.actual_risk_at_stop).toBeCloseTo(100, 2)
  })

  it('EURJPY uses inverse USDJPY conversion', () => {
    const result = runCase(EURJPY, '160.00', '159.50')

    expect(result.valid).toBe(true)
    expect(result.conversion_rate_quote_to_usd ?? 0).toBeCloseTo(1 / 150, 8)
    expect(result.risk_per_one_lot).toBeCloseTo(333.333, 3)
    expect(result.lot_size).toBeCloseTo(0.3, 8)
    expect(result.actual_risk_at_stop).toBeCloseTo(100, 2)
  })

  it('EURGBP uses direct GBPUSD conversion', () => {
    const result = runCase(EURGBP, '0.8600', '0.8580')

    expect(result.valid).toBe(true)
    expect(result.conversion_rate_quote_to_usd).toBeCloseTo(1.27, 8)
    expect(result.risk_per_one_lot).toBeCloseTo(254, 6)
    expect(result.lot_size).toBeCloseTo(0.39, 8)
    expect(result.actual_risk_at_stop).toBeCloseTo(99.06, 2)
    expect(result.if_sl_hits_text).toBe('If SL hits -> -$99.06')
  })

  it('XAUUSD keeps USD quote (no conversion)', () => {
    const result = runCase(XAUUSD, '2350.00', '2340.00')

    expect(result.valid).toBe(true)
    expect(result.conversion_rate_quote_to_usd).toBe(1)
    expect(result.risk_per_one_lot).toBeCloseTo(1000, 6)
    expect(result.lot_size).toBeCloseTo(0.1, 8)
    expect(result.actual_risk_at_stop).toBeCloseTo(100, 2)
  })

  it('blocks lot calculation when quote->USD rate is missing', () => {
    const result = runCase(EURGBP, '0.8600', '0.8580', [
      { from_currency: 'USD', to_currency: 'JPY', rate: '150.0000000000' },
    ])

    expect(result.valid).toBe(false)
    expect(result.lot_size).toBe(0)
    expect(result.warnings).toContain('Missing FX rate to convert GBP->USD')
  })
})
