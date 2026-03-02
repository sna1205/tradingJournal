import { describe, expect, it } from 'vitest'
import { calculateLotSize, type LotSizeInstrumentSpec } from '@/utils/lotSizeEngine'

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
  conversion?: {
    rate: number
    symbolUsed: string | null
    method: 'identity' | 'direct' | 'inverse' | 'pivot'
    ts: number | null
    mode?: 'mid' | 'conservative'
  }
) {
  return calculateLotSize({
    ...BASE_INPUT,
    entry_price,
    stop_loss,
    instrument,
    fx_rate_quote_to_usd: conversion?.rate ?? null,
    fx_symbol_used: conversion?.symbolUsed ?? null,
    fx_conversion_method: conversion?.method ?? null,
    fx_rate_timestamp: conversion?.ts ?? null,
    fx_rate_mode: conversion?.mode ?? 'mid',
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

  it('rejects percentage risk above 100%', () => {
    const result = calculateLotSize({
      ...BASE_INPUT,
      risk_mode: 'percent',
      risk_percent: '960',
      entry_price: '1.1000',
      stop_loss: '1.0990',
      instrument: EURUSD,
      fx_rate_quote_to_usd: 1,
      fx_symbol_used: null,
      fx_conversion_method: 'identity',
      fx_rate_timestamp: null,
      fx_rate_mode: 'mid',
    })

    expect(result.valid).toBe(false)
    expect(result.field_errors.risk_percent).toBe('Risk % must be between 0 and 100.')
    expect(result.target_risk_amount).toBe(0)
  })

  it('EURJPY uses inverse USDJPY conversion', () => {
    const result = runCase(EURJPY, '160.00', '159.50', {
      rate: 1 / 150,
      symbolUsed: 'USDJPY',
      method: 'inverse',
      ts: 1700000000000,
      mode: 'mid',
    })

    expect(result.valid).toBe(true)
    expect(result.conversion_symbol_used).toBe('USDJPY')
    expect(result.conversion_rate_quote_to_usd ?? 0).toBeCloseTo(1 / 150, 8)
    expect(result.risk_per_one_lot).toBeCloseTo(333.333, 3)
    expect(result.lot_size).toBeCloseTo(0.3, 8)
    expect(result.actual_risk_at_stop).toBeCloseTo(100, 2)
    expect(result.actual_risk_at_stop).toBeLessThanOrEqual(result.target_risk_amount + 0.01)
  })

  it('updates lot size when FX quote changes', () => {
    const first = runCase(EURJPY, '160.00', '159.50', {
      rate: 1 / 150,
      symbolUsed: 'USDJPY',
      method: 'inverse',
      ts: 1700000000000,
    })
    const second = runCase(EURJPY, '160.00', '159.50', {
      rate: 1 / 155,
      symbolUsed: 'USDJPY',
      method: 'inverse',
      ts: 1700000000100,
    })

    expect(second.lot_size_raw).toBeGreaterThan(first.lot_size_raw)
    expect(second.risk_per_one_lot).toBeLessThan(first.risk_per_one_lot)
  })

  it('EURGBP uses direct GBPUSD conversion', () => {
    const result = runCase(EURGBP, '0.8600', '0.8580', {
      rate: 1.27,
      symbolUsed: 'GBPUSD',
      method: 'direct',
      ts: 1700000000000,
      mode: 'mid',
    })

    expect(result.valid).toBe(true)
    expect(result.conversion_symbol_used).toBe('GBPUSD')
    expect(result.conversion_rate_quote_to_usd).toBeCloseTo(1.27, 8)
    expect(result.risk_per_one_lot).toBeCloseTo(254, 6)
    expect(result.lot_size).toBeCloseTo(0.39, 8)
    expect(result.actual_risk_at_stop).toBeCloseTo(99.06, 2)
    expect(result.actual_risk_at_stop).toBeLessThanOrEqual(result.target_risk_amount + 1)
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
    const result = runCase(EURGBP, '0.8600', '0.8580')

    expect(result.valid).toBe(false)
    expect(result.lot_size).toBe(0)
    expect(result.warnings).toContain('Missing live FX quote to convert GBP->USD')
  })
})
