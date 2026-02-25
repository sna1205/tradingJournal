import type { PriceFeedService } from '@/services/priceFeedService'

export type FxSymbolFormat = 'noslash' | 'slash'

export class SymbolResolver {
  private readonly priceFeedService: PriceFeedService

  constructor(priceFeedService: PriceFeedService) {
    this.priceFeedService = priceFeedService
  }

  buildFx(base: string, quote: string, format: FxSymbolFormat): string {
    const baseNormalized = normalizeCurrency(base)
    const quoteNormalized = normalizeCurrency(quote)
    if (!baseNormalized || !quoteNormalized) {
      return ''
    }

    return format === 'slash'
      ? `${baseNormalized}/${quoteNormalized}`
      : `${baseNormalized}${quoteNormalized}`
  }

  getCandidates(base: string, quote: string): string[] {
    const candidates = [
      this.buildFx(base, quote, 'noslash'),
      this.buildFx(base, quote, 'slash'),
    ].filter((symbol) => symbol !== '')

    return [...new Set(candidates)]
  }

  resolveAny(base: string, quote: string): string | null {
    const candidates = this.getCandidates(base, quote)
    for (const symbol of candidates) {
      if (this.priceFeedService.hasSymbol(symbol)) {
        return symbol
      }
    }
    return null
  }
}

function normalizeCurrency(value: string): string {
  return String(value ?? '').trim().toUpperCase()
}
