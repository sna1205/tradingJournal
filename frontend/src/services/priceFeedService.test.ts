import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PriceFeedService } from '@/services/priceFeedService'

describe('priceFeedService polling guards', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('does not run periodic polling with no active subscribers', async () => {
    let hidden = false
    const visibilityListeners = new Set<() => void>()
    Object.defineProperty(globalThis, 'document', {
      value: {
        get hidden() {
          return hidden
        },
        addEventListener: (event: string, listener: () => void) => {
          if (event === 'visibilitychange') {
            visibilityListeners.add(listener)
          }
        },
        removeEventListener: (event: string, listener: () => void) => {
          if (event === 'visibilitychange') {
            visibilityListeners.delete(listener)
          }
        },
      },
      configurable: true,
    })

    const fetcher = vi.fn(async () => ({
      EURUSD: {
        bid: 1.1,
        ask: 1.1002,
        ts: Date.now(),
      },
    }))

    const service = new PriceFeedService({
      poll_ms: 1000,
      cache_ttl_ms: 0,
      fetcher,
    })

    const releaseTrack = service.trackSymbols(['EURUSD'])
    await vi.advanceTimersByTimeAsync(3500)
    expect(fetcher).toHaveBeenCalledTimes(0)

    const unsubscribe = service.subscribe('EURUSD', () => undefined)
    await vi.advanceTimersByTimeAsync(1000)
    expect(fetcher).toHaveBeenCalledTimes(2)

    const callsAfterSubscribed = fetcher.mock.calls.length
    unsubscribe()
    await vi.advanceTimersByTimeAsync(3500)
    expect(fetcher).toHaveBeenCalledTimes(callsAfterSubscribed)

    releaseTrack()
    hidden = true
    for (const listener of visibilityListeners) {
      listener()
    }
  })

  it('pauses periodic polling while document.hidden is true', async () => {
    let hidden = false
    const visibilityListeners = new Set<() => void>()
    Object.defineProperty(globalThis, 'document', {
      value: {
        get hidden() {
          return hidden
        },
        addEventListener: (event: string, listener: () => void) => {
          if (event === 'visibilitychange') {
            visibilityListeners.add(listener)
          }
        },
        removeEventListener: (event: string, listener: () => void) => {
          if (event === 'visibilitychange') {
            visibilityListeners.delete(listener)
          }
        },
      },
      configurable: true,
    })

    const fetcher = vi.fn(async () => ({
      EURUSD: {
        bid: 1.1,
        ask: 1.1002,
        ts: Date.now(),
      },
    }))

    const service = new PriceFeedService({
      poll_ms: 1000,
      cache_ttl_ms: 0,
      fetcher,
    })

    const releaseTrack = service.trackSymbols(['EURUSD'])
    const unsubscribe = service.subscribe('EURUSD', () => undefined)

    await vi.advanceTimersByTimeAsync(1100)
    const callsWhileVisible = fetcher.mock.calls.length
    expect(callsWhileVisible).toBeGreaterThan(0)

    hidden = true
    for (const listener of visibilityListeners) {
      listener()
    }
    await vi.advanceTimersByTimeAsync(4000)
    expect(fetcher).toHaveBeenCalledTimes(callsWhileVisible)

    hidden = false
    for (const listener of visibilityListeners) {
      listener()
    }
    await vi.advanceTimersByTimeAsync(1200)
    expect(fetcher.mock.calls.length).toBeGreaterThan(callsWhileVisible)

    unsubscribe()
    releaseTrack()
  })
})
