import type { PriceFeedService, PriceQuote } from '@/services/priceFeedService'
import { SymbolResolver } from '@/services/symbolResolver'

export type FxRateMode = 'mid' | 'conservative'
export type FxResolutionMethod = 'identity' | 'direct' | 'inverse' | 'pivot'

export interface FxQuoteToUsdResolution {
  rate: number
  symbolUsed: string | null
  method: FxResolutionMethod
  mode: FxRateMode
  ts: number | null
  attemptedSymbols: string[]
}

export class FxRateResolutionError extends Error {
  readonly quoteCurrency: string
  readonly attemptedSymbols: string[]

  constructor(quoteCurrency: string, attemptedSymbols: string[]) {
    const attemptedText = attemptedSymbols.length > 0
      ? ` (tried: ${attemptedSymbols.join(', ')})`
      : ''
    super(`Missing live FX quote to convert ${quoteCurrency}->USD${attemptedText}`)
    this.name = 'FxRateResolutionError'
    this.quoteCurrency = quoteCurrency
    this.attemptedSymbols = attemptedSymbols
  }
}

interface FxToUsdServiceOptions {
  pivotCurrencies?: string[]
  ensureTimeoutMs?: number
  cacheTtlMs?: number
}

interface ResolutionWithSource {
  result: FxQuoteToUsdResolution
  sourceSymbols: string[]
  sourceTimestamps: number[]
}

interface FxCacheEntry {
  value: ResolutionWithSource
  expiresAt: number
}

export class FxToUsdService {
  private readonly priceFeedService: PriceFeedService
  private readonly symbolResolver: SymbolResolver
  private readonly pivotCurrencies: string[]
  private readonly ensureTimeoutMs: number
  private readonly cacheTtlMs: number
  private readonly cacheByQuoteAndMode: Map<string, FxCacheEntry> = new Map()

  constructor(priceFeedService: PriceFeedService, options: FxToUsdServiceOptions = {}) {
    this.priceFeedService = priceFeedService
    this.symbolResolver = new SymbolResolver(priceFeedService)
    this.pivotCurrencies = normalizePivotCurrencies(options.pivotCurrencies ?? ['EUR', 'GBP'])
    this.ensureTimeoutMs = options.ensureTimeoutMs ?? 1500
    this.cacheTtlMs = options.cacheTtlMs ?? 1500
  }

  async getRate(quoteCurrency: string, mode: FxRateMode = 'mid'): Promise<FxQuoteToUsdResolution> {
    const quote = normalizeCurrency(quoteCurrency)
    if (!quote) {
      throw new Error('Quote currency is required.')
    }

    if (quote === 'USD') {
      return {
        rate: 1,
        symbolUsed: null,
        method: 'identity',
        mode,
        ts: null,
        attemptedSymbols: [],
      }
    }

    const cacheKey = `${quote}|${mode}`
    const cached = this.cacheByQuoteAndMode.get(cacheKey)
    if (cached && this.isCacheValid(cached)) {
      return cloneResolution(cached.value.result)
    }

    const attempted = new Set<string>()

    const direct = await this.tryDirect(quote, mode, attempted)
    if (direct) {
      this.writeCache(cacheKey, direct)
      return cloneResolution(direct.result)
    }

    const inverse = await this.tryInverse(quote, mode, attempted)
    if (inverse) {
      this.writeCache(cacheKey, inverse)
      return cloneResolution(inverse.result)
    }

    const pivot = await this.tryPivot(quote, mode, attempted)
    if (pivot) {
      this.writeCache(cacheKey, pivot)
      return cloneResolution(pivot.result)
    }

    throw new FxRateResolutionError(quote, [...attempted])
  }

  async getQuoteToUsdRate(quoteCurrency: string, mode: FxRateMode = 'mid'): Promise<FxQuoteToUsdResolution> {
    return await this.getRate(quoteCurrency, mode)
  }

  getTrackedSymbolsForQuoteCurrency(quoteCurrency: string): string[] {
    const quote = normalizeCurrency(quoteCurrency)
    if (!quote || quote === 'USD') {
      return []
    }

    const symbols = new Set<string>()
    for (const symbol of this.symbolResolver.getCandidates(quote, 'USD')) {
      symbols.add(symbol)
    }
    for (const symbol of this.symbolResolver.getCandidates('USD', quote)) {
      symbols.add(symbol)
    }

    for (const pivot of this.pivotCurrencies) {
      if (pivot === quote || pivot === 'USD') {
        continue
      }
      for (const symbol of this.symbolResolver.getCandidates(pivot, 'USD')) {
        symbols.add(symbol)
      }
      for (const symbol of this.symbolResolver.getCandidates(pivot, quote)) {
        symbols.add(symbol)
      }
    }

    return [...symbols]
  }

  private writeCache(cacheKey: string, resolution: ResolutionWithSource) {
    this.cacheByQuoteAndMode.set(cacheKey, {
      value: resolution,
      expiresAt: Date.now() + this.cacheTtlMs,
    })
  }

  private isCacheValid(entry: FxCacheEntry): boolean {
    if (entry.expiresAt <= Date.now()) {
      return false
    }

    const sourceSymbols = entry.value.sourceSymbols
    if (sourceSymbols.length === 0) {
      return true
    }

    for (let i = 0; i < sourceSymbols.length; i += 1) {
      const symbol = sourceSymbols[i]
      const expectedTs = entry.value.sourceTimestamps[i]
      if (!symbol || expectedTs === undefined) {
        return false
      }
      const quote = this.priceFeedService.getQuoteOrNull(symbol)
      if (!quote || quote.ts !== expectedTs) {
        return false
      }
    }

    return true
  }

  private async tryDirect(
    quoteCurrency: string,
    mode: FxRateMode,
    attempted: Set<string>
  ): Promise<ResolutionWithSource | null> {
    const symbol = await this.resolveAndSubscribe(quoteCurrency, 'USD', attempted)
    if (!symbol) return null

    const quote = this.priceFeedService.getQuoteOrNull(symbol)
    if (!quote) return null

    const rate = mode === 'conservative' ? quote.bid : quote.mid
    if (!(rate > 0)) return null

    return {
      result: {
        rate,
        symbolUsed: symbol,
        method: 'direct',
        mode,
        ts: quote.ts,
        attemptedSymbols: [...attempted],
      },
      sourceSymbols: [symbol],
      sourceTimestamps: [quote.ts],
    }
  }

  private async tryInverse(
    quoteCurrency: string,
    mode: FxRateMode,
    attempted: Set<string>
  ): Promise<ResolutionWithSource | null> {
    const symbol = await this.resolveAndSubscribe('USD', quoteCurrency, attempted)
    if (!symbol) return null

    const quote = this.priceFeedService.getQuoteOrNull(symbol)
    if (!quote) return null

    const divisor = mode === 'conservative' ? quote.ask : quote.mid
    if (!(divisor > 0)) return null

    return {
      result: {
        rate: 1 / divisor,
        symbolUsed: symbol,
        method: 'inverse',
        mode,
        ts: quote.ts,
        attemptedSymbols: [...attempted],
      },
      sourceSymbols: [symbol],
      sourceTimestamps: [quote.ts],
    }
  }

  private async tryPivot(
    quoteCurrency: string,
    mode: FxRateMode,
    attempted: Set<string>
  ): Promise<ResolutionWithSource | null> {
    for (const pivot of this.pivotCurrencies) {
      if (pivot === quoteCurrency || pivot === 'USD') {
        continue
      }

      const pivotUsd = await this.resolveAndSubscribe(pivot, 'USD', attempted)
      if (!pivotUsd) {
        continue
      }
      const pivotQuote = await this.resolveAndSubscribe(pivot, quoteCurrency, attempted)
      if (!pivotQuote) {
        continue
      }

      const pivotUsdQuote = this.priceFeedService.getQuoteOrNull(pivotUsd)
      const pivotQuoteQuote = this.priceFeedService.getQuoteOrNull(pivotQuote)
      if (!pivotUsdQuote || !pivotQuoteQuote) {
        continue
      }

      const numerator = mode === 'conservative' ? pivotUsdQuote.bid : pivotUsdQuote.mid
      const denominator = mode === 'conservative' ? pivotQuoteQuote.ask : pivotQuoteQuote.mid
      if (!(numerator > 0) || !(denominator > 0)) {
        continue
      }

      return {
        result: {
          rate: numerator / denominator,
          symbolUsed: `${pivotUsd} + ${pivotQuote}`,
          method: 'pivot',
          mode,
          ts: Math.min(pivotUsdQuote.ts, pivotQuoteQuote.ts),
          attemptedSymbols: [...attempted],
        },
        sourceSymbols: [pivotUsd, pivotQuote],
        sourceTimestamps: [pivotUsdQuote.ts, pivotQuoteQuote.ts],
      }
    }

    return null
  }

  private async resolveAndSubscribe(base: string, quote: string, attempted: Set<string>): Promise<string | null> {
    const known = this.symbolResolver.resolveAny(base, quote)
    if (known) {
      attempted.add(known)
      try {
        await this.priceFeedService.ensureSubscribed(known, this.ensureTimeoutMs)
      } catch {
        return null
      }

      if (this.priceFeedService.getQuoteOrNull(known)) {
        return known
      }
      return null
    }

    const candidates = this.symbolResolver.getCandidates(base, quote)
    if (candidates.length === 0) {
      return null
    }
    return await new Promise<string | null>((resolve) => {
      let completed = 0
      let resolved = false
      const complete = (symbol: string | null) => {
        if (resolved) return
        resolved = true
        resolve(symbol)
      }

      for (const symbol of candidates) {
        attempted.add(symbol)
        void this.priceFeedService.ensureSubscribed(symbol, this.ensureTimeoutMs)
          .then(() => {
            if (resolved) return
            const quoteValue = this.priceFeedService.getQuoteOrNull(symbol)
            if (quoteValue) {
              complete(symbol)
              return
            }

            completed += 1
            if (completed >= candidates.length) {
              complete(null)
            }
          })
          .catch(() => {
            if (resolved) return
            completed += 1
            if (completed >= candidates.length) {
              complete(null)
            }
          })
      }
    })
  }
}

function normalizeCurrency(value: string): string {
  return String(value ?? '').trim().toUpperCase()
}

function normalizePivotCurrencies(pivots: string[]): string[] {
  const normalized = pivots
    .map((value) => normalizeCurrency(value))
    .filter((value) => value !== '' && value !== 'USD')

  return [...new Set(normalized)]
}

function cloneResolution(value: FxQuoteToUsdResolution): FxQuoteToUsdResolution {
  return {
    ...value,
    attemptedSymbols: [...value.attemptedSymbols],
  }
}

export type FxPriceQuote = PriceQuote
