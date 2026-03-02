import { describe, expect, it } from 'vitest'
import { FxRateResolutionError, FxToUsdService, resolveQuoteToUsdFromTable } from '@/services/fxToUsdService'
import type { PriceQuote } from '@/services/priceFeedService'

type PendingWaiter = {
  resolve: () => void
  reject: (error: Error) => void
  timer: ReturnType<typeof setTimeout>
}

class MockPriceFeed {
  private readonly quotes: Map<string, PriceQuote> = new Map()
  private readonly pending: Map<string, PendingWaiter[]> = new Map()

  set(symbol: string, bid: number, ask: number, ts = Date.now()) {
    const normalized = this.normalize(symbol)
    this.quotes.set(normalized, {
      symbol: normalized,
      bid,
      ask,
      mid: (bid + ask) / 2,
      ts,
    })

    const waiters = this.pending.get(normalized) ?? []
    for (const waiter of waiters) {
      clearTimeout(waiter.timer)
      waiter.resolve()
    }
    this.pending.delete(normalized)
  }

  hasSymbol(symbol: string): boolean {
    return this.quotes.has(this.normalize(symbol))
  }

  getQuoteOrNull(symbol: string): PriceQuote | null {
    return this.quotes.get(this.normalize(symbol)) ?? null
  }

  async ensureSubscribed(symbol: string, timeoutMs = 1500): Promise<void> {
    const normalized = this.normalize(symbol)
    if (this.quotes.has(normalized)) {
      return
    }

    await new Promise<void>((resolve, reject) => {
      const timer = setTimeout(() => {
        reject(new Error(`No tick for ${normalized} within ${timeoutMs}ms`))
      }, timeoutMs)

      const waiters = this.pending.get(normalized) ?? []
      waiters.push({ resolve, reject, timer })
      this.pending.set(normalized, waiters)
    })
  }

  private normalize(symbol: string): string {
    return symbol.trim().toUpperCase()
  }
}

describe('FxToUsdService live quote resolution', () => {
  it('returns identity for USD quote currency', async () => {
    const feed = new MockPriceFeed()
    const service = new FxToUsdService(feed as never)

    const result = await service.getRate('USD', 'mid')

    expect(result.rate).toBe(1)
    expect(result.symbolUsed).toBeNull()
    expect(result.method).toBe('identity')
  })

  it('resolves inverse when USDJPY exists in no-slash format', async () => {
    const feed = new MockPriceFeed()
    feed.set('USDJPY', 149.99, 150.01, 1700000000100)
    const service = new FxToUsdService(feed as never)

    const result = await service.getRate('JPY', 'mid')

    expect(result.symbolUsed).toBe('USDJPY')
    expect(result.method).toBe('inverse')
    expect(result.rate).toBeCloseTo(1 / 150, 8)
  })

  it('resolves inverse when USD/JPY exists only in slash format', async () => {
    const feed = new MockPriceFeed()
    feed.set('USD/JPY', 150.0, 150.0, 1700000000200)
    const service = new FxToUsdService(feed as never)

    const result = await service.getRate('JPY', 'mid')

    expect(result.symbolUsed).toBe('USD/JPY')
    expect(result.method).toBe('inverse')
    expect(result.rate).toBeCloseTo(1 / 150, 8)
  })

  it('resolves pivot when direct and inverse are missing', async () => {
    const feed = new MockPriceFeed()
    feed.set('EURUSD', 1.1, 1.1, 1700000000300)
    feed.set('EURJPY', 150.0, 150.0, 1700000000300)
    const service = new FxToUsdService(feed as never)

    const result = await service.getRate('JPY', 'mid')

    expect(result.method).toBe('pivot')
    expect(result.symbolUsed).toBe('EURUSD + EURJPY')
    expect(result.rate).toBeCloseTo(1.1 / 150, 8)
  })

  it('resolves direct when GBP/USD exists in slash format', async () => {
    const feed = new MockPriceFeed()
    feed.set('GBP/USD', 1.27, 1.27, 1700000000400)
    const service = new FxToUsdService(feed as never)

    const result = await service.getRate('GBP', 'mid')

    expect(result.method).toBe('direct')
    expect(result.symbolUsed).toBe('GBP/USD')
    expect(result.rate).toBeCloseTo(1.27, 8)
  })

  it('updates conversion when quote tick changes', async () => {
    const feed = new MockPriceFeed()
    feed.set('USDJPY', 149.95, 150.05, 1700000000500)
    const service = new FxToUsdService(feed as never, { cacheTtlMs: 3000 })

    const first = await service.getRate('JPY', 'mid')
    feed.set('USDJPY', 151.95, 152.05, 1700000000600)
    const second = await service.getRate('JPY', 'mid')

    expect(second.rate).toBeCloseTo(1 / 152, 8)
    expect(second.rate).not.toBe(first.rate)
  })

  it('waits for delayed quote arrival within timeout', async () => {
    const feed = new MockPriceFeed()
    const service = new FxToUsdService(feed as never, { ensureTimeoutMs: 1200 })

    const pending = service.getRate('JPY', 'mid')
    setTimeout(() => {
      feed.set('USDJPY', 150, 150, 1700000000700)
    }, 200)

    const result = await pending
    expect(result.rate).toBeCloseTo(1 / 150, 8)
    expect(result.method).toBe('inverse')
  })

  it('returns attempted symbols when resolution fails', async () => {
    const feed = new MockPriceFeed()
    const service = new FxToUsdService(feed as never, { ensureTimeoutMs: 50 })

    await expect(service.getRate('JPY', 'mid')).rejects.toBeInstanceOf(FxRateResolutionError)
    await expect(service.getRate('JPY', 'mid')).rejects.toMatchObject({
      attemptedSymbols: expect.arrayContaining(['JPYUSD', 'JPY/USD', 'USDJPY', 'USD/JPY']),
    })
  })
})

describe('resolveQuoteToUsdFromTable', () => {
  it('resolves inverse from USDJPY table row', () => {
    const result = resolveQuoteToUsdFromTable('JPY', [
      { from_currency: 'USD', to_currency: 'JPY', rate: '150.0' },
    ])

    expect(result).not.toBeNull()
    expect(result?.method).toBe('inverse')
    expect(result?.rate).toBeCloseTo(1 / 150, 8)
  })

  it('resolves direct when quote->USD row exists', () => {
    const result = resolveQuoteToUsdFromTable('GBP', [
      { from_currency: 'GBP', to_currency: 'USD', rate: 1.27 },
    ])

    expect(result).not.toBeNull()
    expect(result?.method).toBe('direct')
    expect(result?.rate).toBeCloseTo(1.27, 8)
  })

  it('resolves pivot using cross rates in table', () => {
    const result = resolveQuoteToUsdFromTable('JPY', [
      { from_currency: 'EUR', to_currency: 'JPY', rate: 150.0 },
      { from_currency: 'EUR', to_currency: 'USD', rate: 1.1 },
    ])

    expect(result).not.toBeNull()
    expect(result?.method).toBe('pivot')
    expect(result?.rate).toBeCloseTo(1.1 / 150, 8)
  })
})
