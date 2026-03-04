import api from '@/services/api'

export interface PriceQuote {
  symbol: string
  bid: number
  ask: number
  mid: number
  ts: number
}

type QuoteListener = (quote: PriceQuote) => void

interface PriceFeedApiResponse {
  quotes?: Record<string, Partial<PriceQuote>>
}

interface PriceFeedServiceOptions {
  cache_ttl_ms?: number
  poll_ms?: number
  fetcher?: (symbols: string[]) => Promise<Record<string, Partial<PriceQuote>>>
  isHidden?: () => boolean
  bindVisibilityChange?: (handler: () => void) => (() => void) | null
}

export class PriceFeedService {
  private readonly cacheTtlMs: number
  private readonly pollMs: number
  private readonly quotes: Map<string, PriceQuote> = new Map()
  private readonly listeners: Map<string, Set<QuoteListener>> = new Map()
  private readonly trackedRefCount: Map<string, number> = new Map()
  private readonly lastFetchMs: Map<string, number> = new Map()
  private readonly symbolAvailability: Map<string, boolean> = new Map()
  private readonly persistentTrackingReleases: Map<string, () => void> = new Map()
  private readonly pendingEnsures: Map<string, Promise<void>> = new Map()
  private pollHandle: ReturnType<typeof setInterval> | null = null
  private refreshInFlight = false
  private readonly fetcher: (symbols: string[]) => Promise<Record<string, Partial<PriceQuote>>>
  private readonly isHidden: () => boolean
  private readonly bindVisibilityChange: (handler: () => void) => (() => void) | null

  constructor(options: PriceFeedServiceOptions = {}) {
    this.cacheTtlMs = options.cache_ttl_ms ?? 2000
    this.pollMs = options.poll_ms ?? 1000
    this.fetcher = options.fetcher ?? fetchQuotesFromApi
    this.isHidden = options.isHidden ?? defaultIsHidden
    this.bindVisibilityChange = options.bindVisibilityChange ?? defaultBindVisibilityChange
    this.bindVisibilityChange(() => {
      this.updatePollingState()
      if (!this.isHidden()) {
        void this.refreshTrackedSymbols(true)
      }
    })
  }

  getQuote(symbol: string): PriceQuote {
    const normalized = normalizeSymbol(symbol)
    const quote = this.quotes.get(normalized)
    if (!quote) {
      throw new Error(`Missing live quote for ${normalized}`)
    }
    return quote
  }

  getQuoteOrNull(symbol: string): PriceQuote | null {
    const normalized = normalizeSymbol(symbol)
    return this.quotes.get(normalized) ?? null
  }

  hasSymbol(symbol: string): boolean {
    const normalized = normalizeSymbol(symbol)
    return this.symbolAvailability.get(normalized) === true
  }

  async ensureSubscribed(symbol: string, timeoutMs = 1500): Promise<void> {
    const normalized = normalizeSymbol(symbol)
    if (!normalized) {
      throw new Error('Symbol is required.')
    }

    const existing = this.quotes.get(normalized)
    if (existing) {
      this.symbolAvailability.set(normalized, true)
      this.ensurePersistentTracking(normalized)
      return
    }

    const inFlight = this.pendingEnsures.get(normalized)
    if (inFlight) {
      return await inFlight
    }

    this.ensurePersistentTracking(normalized)

    const ensurePromise = new Promise<void>((resolve, reject) => {
      let settled = false
      const settleResolve = () => {
        if (settled) return
        settled = true
        cleanup()
        this.symbolAvailability.set(normalized, true)
        resolve()
      }
      const settleReject = (reason: string) => {
        if (settled) return
        settled = true
        cleanup()
        this.symbolAvailability.set(normalized, false)
        reject(new Error(reason))
      }

      const unsubscribe = this.subscribe(normalized, () => {
        settleResolve()
      })

      const timer = globalThis.setTimeout(() => {
        settleReject(`No tick for ${normalized} within ${timeoutMs}ms`)
      }, timeoutMs)

      const cleanup = () => {
        unsubscribe()
        globalThis.clearTimeout(timer)
      }

      const quoteNow = this.quotes.get(normalized)
      if (quoteNow) {
        settleResolve()
        return
      }

      void this.refreshTrackedSymbols(true)
    }).finally(() => {
      this.pendingEnsures.delete(normalized)
    })

    this.pendingEnsures.set(normalized, ensurePromise)
    return await ensurePromise
  }

  upsertQuote(symbol: string, quote: Omit<PriceQuote, 'symbol' | 'mid'> & { mid?: number }) {
    const normalized = normalizeSymbol(symbol)
    if (!normalized) return

    const normalizedQuote = normalizeQuote(normalized, quote)
    if (!normalizedQuote) return

    const previous = this.quotes.get(normalized)
    this.quotes.set(normalized, normalizedQuote)
    this.lastFetchMs.set(normalized, Date.now())
    this.symbolAvailability.set(normalized, true)

    if (!previous || previous.bid !== normalizedQuote.bid || previous.ask !== normalizedQuote.ask || previous.ts !== normalizedQuote.ts) {
      const listeners = this.listeners.get(normalized)
      if (listeners) {
        for (const listener of listeners) {
          listener(normalizedQuote)
        }
      }
    }
  }

  subscribe(symbol: string, listener: QuoteListener): () => void {
    const normalized = normalizeSymbol(symbol)
    if (!normalized) {
      return () => undefined
    }

    const bucket = this.listeners.get(normalized) ?? new Set<QuoteListener>()
    bucket.add(listener)
    this.listeners.set(normalized, bucket)

    const existing = this.quotes.get(normalized)
    if (existing) {
      listener(existing)
    }
    this.updatePollingState()
    if (!this.isHidden()) {
      void this.refreshTrackedSymbols(true)
    }

    return () => {
      const current = this.listeners.get(normalized)
      if (!current) return
      current.delete(listener)
      if (current.size === 0) {
        this.listeners.delete(normalized)
      }
      this.updatePollingState()
    }
  }

  trackSymbols(symbols: string[]): () => void {
    const normalizedSymbols = symbols.map(normalizeSymbol).filter((symbol) => symbol !== '')
    if (normalizedSymbols.length === 0) {
      return () => undefined
    }

    for (const symbol of normalizedSymbols) {
      this.trackedRefCount.set(symbol, (this.trackedRefCount.get(symbol) ?? 0) + 1)
    }
    this.updatePollingState()
    if (this.canPoll()) {
      void this.refreshTrackedSymbols(true)
    }

    return () => {
      for (const symbol of normalizedSymbols) {
        const next = (this.trackedRefCount.get(symbol) ?? 0) - 1
        if (next <= 0) {
          this.trackedRefCount.delete(symbol)
        } else {
          this.trackedRefCount.set(symbol, next)
        }
      }
      this.updatePollingState()
    }
  }

  private ensurePersistentTracking(symbol: string) {
    if (this.persistentTrackingReleases.has(symbol)) {
      return
    }
    const release = this.trackSymbols([symbol])
    this.persistentTrackingReleases.set(symbol, release)
  }

  private ensurePolling() {
    if (this.pollHandle !== null) return
    if (!this.canPoll()) return
    this.pollHandle = globalThis.setInterval(() => {
      if (!this.canPoll()) {
        this.updatePollingState()
        return
      }
      void this.refreshTrackedSymbols(false)
    }, this.pollMs)
  }

  private stopPolling() {
    if (this.pollHandle !== null) {
      globalThis.clearInterval(this.pollHandle)
      this.pollHandle = null
    }
  }

  private updatePollingState() {
    if (this.canPoll()) {
      this.ensurePolling()
      return
    }
    this.stopPolling()
  }

  private canPoll(): boolean {
    if (this.trackedRefCount.size <= 0) return false
    if (!this.hasActiveSubscribers()) return false
    if (this.isHidden()) return false
    return true
  }

  private hasActiveSubscribers(): boolean {
    for (const listeners of this.listeners.values()) {
      if (listeners.size > 0) return true
    }
    return false
  }

  private async refreshTrackedSymbols(force: boolean) {
    if (this.refreshInFlight) return

    const now = Date.now()
    const symbols = [...this.trackedRefCount.keys()].filter((symbol) => {
      if (force) return true
      const last = this.lastFetchMs.get(symbol) ?? 0
      return (now - last) >= this.cacheTtlMs
    })
    if (symbols.length === 0) return

    this.refreshInFlight = true
    try {
      const batch = await this.fetcher(symbols)
      for (const symbol of symbols) {
        this.lastFetchMs.set(symbol, now)
        const raw = batch[symbol]
        if (!raw) {
          this.symbolAvailability.set(symbol, false)
          continue
        }
        this.upsertQuote(symbol, {
          bid: Number(raw.bid ?? 0),
          ask: Number(raw.ask ?? 0),
          mid: raw.mid !== undefined ? Number(raw.mid) : undefined,
          ts: Number(raw.ts ?? now),
        })
      }
    } catch {
      // Keep polling alive without surfacing unhandled promise rejections.
      // Failed symbols are marked unavailable and throttled by cache TTL.
      for (const symbol of symbols) {
        this.lastFetchMs.set(symbol, now)
        this.symbolAvailability.set(symbol, false)
      }
    } finally {
      this.refreshInFlight = false
    }
  }
}

export function normalizeSymbol(symbol: string): string {
  return String(symbol ?? '').trim().toUpperCase()
}

function defaultIsHidden(): boolean {
  if (typeof document === 'undefined') return false
  return document.hidden === true
}

function defaultBindVisibilityChange(handler: () => void): (() => void) | null {
  if (typeof document === 'undefined' || typeof document.addEventListener !== 'function') {
    return null
  }
  document.addEventListener('visibilitychange', handler)
  return () => {
    document.removeEventListener('visibilitychange', handler)
  }
}

function normalizeQuote(symbol: string, quote: Omit<PriceQuote, 'symbol' | 'mid'> & { mid?: number }): PriceQuote | null {
  const bid = Number(quote.bid ?? 0)
  const ask = Number(quote.ask ?? 0)
  const midRaw = Number(quote.mid ?? 0)
  const ts = Number(quote.ts ?? Date.now())

  const bidSafe = Number.isFinite(bid) && bid > 0 ? bid : (Number.isFinite(midRaw) && midRaw > 0 ? midRaw : 0)
  const askSafe = Number.isFinite(ask) && ask > 0 ? ask : (Number.isFinite(midRaw) && midRaw > 0 ? midRaw : 0)
  const midSafe = Number.isFinite(midRaw) && midRaw > 0
    ? midRaw
    : (bidSafe > 0 && askSafe > 0 ? (bidSafe + askSafe) / 2 : 0)

  if (!(bidSafe > 0) || !(askSafe > 0) || !(midSafe > 0)) {
    return null
  }

  return {
    symbol,
    bid: bidSafe,
    ask: askSafe,
    mid: midSafe,
    ts: Number.isFinite(ts) && ts > 0 ? ts : Date.now(),
  }
}

async function fetchQuotesFromApi(symbols: string[]): Promise<Record<string, Partial<PriceQuote>>> {
  if (symbols.length === 0) return {}
  const { data } = await api.get<PriceFeedApiResponse>('/price-feed/quotes', {
    params: {
      symbols: symbols.join(','),
    },
  })

  const rows = data?.quotes ?? {}
  const normalized: Record<string, Partial<PriceQuote>> = {}
  for (const [symbol, quote] of Object.entries(rows)) {
    normalized[normalizeSymbol(symbol)] = quote
  }
  return normalized
}

export const livePriceFeedService = new PriceFeedService()
