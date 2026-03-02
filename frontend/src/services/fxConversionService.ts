import {
  FxRateResolutionError,
  FxToUsdService,
  type FxRateMode,
  type FxRateTableRow,
  type FxResolutionMethod,
  resolveQuoteToUsdFromTable,
} from '@/services/fxToUsdService'

export interface FxQuoteToAccountResolution {
  rate: number
  symbolUsed: string | null
  method: FxResolutionMethod
  mode: FxRateMode
  ts: number | null
  attemptedSymbols: string[]
  quoteToUsdRate: number | null
  usdToAccountRate: number | null
}

export class FxConversionResolutionError extends Error {
  readonly quoteCurrency: string
  readonly accountCurrency: string
  readonly attemptedSymbols: string[]

  constructor(quoteCurrency: string, accountCurrency: string, attemptedSymbols: string[] = []) {
    const attemptedText = attemptedSymbols.length > 0
      ? ` (tried: ${attemptedSymbols.join(', ')})`
      : ''
    super(`Missing live FX quote to convert ${quoteCurrency}->${accountCurrency}${attemptedText}`)
    this.name = 'FxConversionResolutionError'
    this.quoteCurrency = quoteCurrency
    this.accountCurrency = accountCurrency
    this.attemptedSymbols = attemptedSymbols
  }
}

export class FxConversionService {
  private readonly fxToUsdService: FxToUsdService

  constructor(fxToUsdService: FxToUsdService) {
    this.fxToUsdService = fxToUsdService
  }

  async getQuoteToAccountRate(
    quoteCurrency: string,
    accountCurrency: string,
    mode: FxRateMode = 'mid'
  ): Promise<FxQuoteToAccountResolution> {
    const quote = normalizeCurrency(quoteCurrency)
    const account = normalizeCurrency(accountCurrency)
    if (!quote || !account) {
      throw new Error('Quote and account currency are required.')
    }

    if (quote === account) {
      return {
        rate: 1,
        symbolUsed: null,
        method: 'identity',
        mode,
        ts: null,
        attemptedSymbols: [],
        quoteToUsdRate: quote === 'USD' ? 1 : null,
        usdToAccountRate: account === 'USD' ? 1 : null,
      }
    }

    try {
      if (account === 'USD') {
        const quoteToUsd = await this.fxToUsdService.getRate(quote, mode)
        return {
          rate: quoteToUsd.rate,
          symbolUsed: quoteToUsd.symbolUsed,
          method: quoteToUsd.method,
          mode: quoteToUsd.mode,
          ts: quoteToUsd.ts,
          attemptedSymbols: [...quoteToUsd.attemptedSymbols],
          quoteToUsdRate: quoteToUsd.rate,
          usdToAccountRate: 1,
        }
      }

      if (quote === 'USD') {
        const accountToUsd = await this.fxToUsdService.getRate(account, mode)
        if (!(accountToUsd.rate > 0)) {
          throw new FxConversionResolutionError(quote, account, accountToUsd.attemptedSymbols)
        }

        return {
          rate: 1 / accountToUsd.rate,
          symbolUsed: accountToUsd.symbolUsed,
          method: 'inverse',
          mode,
          ts: accountToUsd.ts,
          attemptedSymbols: [...accountToUsd.attemptedSymbols],
          quoteToUsdRate: 1,
          usdToAccountRate: 1 / accountToUsd.rate,
        }
      }

      const [quoteToUsd, accountToUsd] = await Promise.all([
        this.fxToUsdService.getRate(quote, mode),
        this.fxToUsdService.getRate(account, mode),
      ])
      if (!(quoteToUsd.rate > 0) || !(accountToUsd.rate > 0)) {
        throw new FxConversionResolutionError(quote, account, [
          ...quoteToUsd.attemptedSymbols,
          ...accountToUsd.attemptedSymbols,
        ])
      }

      return {
        rate: quoteToUsd.rate / accountToUsd.rate,
        symbolUsed: `${quoteToUsd.symbolUsed ?? `${quote}USD`} + ${accountToUsd.symbolUsed ?? `${account}USD`}`,
        method: 'pivot',
        mode,
        ts: oldestTimestamp(quoteToUsd.ts, accountToUsd.ts),
        attemptedSymbols: [...new Set([...quoteToUsd.attemptedSymbols, ...accountToUsd.attemptedSymbols])],
        quoteToUsdRate: quoteToUsd.rate,
        usdToAccountRate: 1 / accountToUsd.rate,
      }
    } catch (error) {
      if (error instanceof FxConversionResolutionError) throw error
      if (error instanceof FxRateResolutionError) {
        throw new FxConversionResolutionError(quote, account, error.attemptedSymbols)
      }
      throw error
    }
  }

  getTrackedSymbolsForPair(quoteCurrency: string, accountCurrency: string): string[] {
    const quote = normalizeCurrency(quoteCurrency)
    const account = normalizeCurrency(accountCurrency)
    if (!quote || !account || quote === account) {
      return []
    }

    if (account === 'USD') {
      return this.fxToUsdService.getTrackedSymbolsForQuoteCurrency(quote)
    }
    if (quote === 'USD') {
      return this.fxToUsdService.getTrackedSymbolsForQuoteCurrency(account)
    }

    return [...new Set([
      ...this.fxToUsdService.getTrackedSymbolsForQuoteCurrency(quote),
      ...this.fxToUsdService.getTrackedSymbolsForQuoteCurrency(account),
    ])]
  }
}

export function resolveQuoteToAccountFromTable(
  quoteCurrency: string,
  accountCurrency: string,
  rows: FxRateTableRow[]
): FxQuoteToAccountResolution | null {
  const quote = normalizeCurrency(quoteCurrency)
  const account = normalizeCurrency(accountCurrency)
  if (!quote || !account) return null

  if (quote === account) {
    return {
      rate: 1,
      symbolUsed: null,
      method: 'identity',
      mode: 'mid',
      ts: null,
      attemptedSymbols: [],
      quoteToUsdRate: quote === 'USD' ? 1 : null,
      usdToAccountRate: account === 'USD' ? 1 : null,
    }
  }

  if (account === 'USD') {
    const quoteToUsd = resolveQuoteToUsdFromTable(quote, rows)
    if (!quoteToUsd) return null
    return {
      rate: quoteToUsd.rate,
      symbolUsed: quoteToUsd.symbolUsed,
      method: quoteToUsd.method,
      mode: quoteToUsd.mode,
      ts: quoteToUsd.ts,
      attemptedSymbols: quoteToUsd.attemptedSymbols,
      quoteToUsdRate: quoteToUsd.rate,
      usdToAccountRate: 1,
    }
  }

  if (quote === 'USD') {
    const accountToUsd = resolveQuoteToUsdFromTable(account, rows)
    if (!accountToUsd || !(accountToUsd.rate > 0)) return null
    return {
      rate: 1 / accountToUsd.rate,
      symbolUsed: accountToUsd.symbolUsed,
      method: 'inverse',
      mode: accountToUsd.mode,
      ts: accountToUsd.ts,
      attemptedSymbols: accountToUsd.attemptedSymbols,
      quoteToUsdRate: 1,
      usdToAccountRate: 1 / accountToUsd.rate,
    }
  }

  const quoteToUsd = resolveQuoteToUsdFromTable(quote, rows)
  const accountToUsd = resolveQuoteToUsdFromTable(account, rows)
  if (!quoteToUsd || !accountToUsd || !(accountToUsd.rate > 0)) return null

  return {
    rate: quoteToUsd.rate / accountToUsd.rate,
    symbolUsed: `${quoteToUsd.symbolUsed ?? `${quote}USD (table)`} + ${accountToUsd.symbolUsed ?? `${account}USD (table)`}`,
    method: 'pivot',
    mode: quoteToUsd.mode,
    ts: oldestTimestamp(quoteToUsd.ts, accountToUsd.ts),
    attemptedSymbols: [],
    quoteToUsdRate: quoteToUsd.rate,
    usdToAccountRate: 1 / accountToUsd.rate,
  }
}

function normalizeCurrency(value: string): string {
  return String(value ?? '').trim().toUpperCase()
}

function oldestTimestamp(left: number | null, right: number | null): number | null {
  if (typeof left === 'number' && Number.isFinite(left) && typeof right === 'number' && Number.isFinite(right)) {
    return Math.min(left, right)
  }
  if (typeof left === 'number' && Number.isFinite(left)) return left
  if (typeof right === 'number' && Number.isFinite(right)) return right
  return null
}
