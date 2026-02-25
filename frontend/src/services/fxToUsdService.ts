export interface FxRateLike {
  from_currency: string
  to_currency: string
  rate: string | number
}

export class FxToUsdService {
  private readonly pairRates: Map<string, number>

  constructor(rates: FxRateLike[]) {
    this.pairRates = new Map<string, number>()

    for (const row of rates) {
      const from = String(row.from_currency ?? '').trim().toUpperCase()
      const to = String(row.to_currency ?? '').trim().toUpperCase()
      const rate = Number(row.rate ?? 0)
      if (!from || !to || !Number.isFinite(rate) || rate <= 0) continue
      this.pairRates.set(`${from}:${to}`, rate)
    }
  }

  getRate(fromCurrency: string): number | null {
    const from = String(fromCurrency ?? '').trim().toUpperCase()
    if (!from) return null

    if (from === 'USD') {
      return 1
    }

    const directOrInverse = this.resolveDirectOrInverse(from, 'USD')
    if (directOrInverse !== null) {
      return directOrInverse
    }

    // Optional cross path: X -> EUR -> USD.
    const toEur = this.resolveDirectOrInverse(from, 'EUR')
    const eurToUsd = this.resolveDirectOrInverse('EUR', 'USD')
    if (toEur !== null && eurToUsd !== null) {
      return toEur * eurToUsd
    }

    return null
  }

  private resolveDirectOrInverse(from: string, to: string): number | null {
    const direct = this.pairRates.get(`${from}:${to}`)
    if (direct !== undefined && direct > 0) {
      return direct
    }

    const inverse = this.pairRates.get(`${to}:${from}`)
    if (inverse !== undefined && inverse > 0) {
      return 1 / inverse
    }

    return null
  }
}
