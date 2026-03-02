import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createDebouncedLatestRunner, stableStringify } from '@/services/precheckScheduler'

describe('precheckScheduler stableStringify', () => {
  it('produces same hash for same object with different key order', () => {
    const left = { b: 2, a: 1, nested: { z: true, y: false } }
    const right = { nested: { y: false, z: true }, a: 1, b: 2 }

    expect(stableStringify(left)).toBe(stableStringify(right))
  })
})

describe('precheckScheduler debounced latest runner', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('dedupes unchanged payload hash', async () => {
    const execute = vi.fn(async () => ({ allowed: true }))
    const runner = createDebouncedLatestRunner({
      debounceMs: 800,
      getHash: (payload: { hash: string }) => payload.hash,
      execute,
    })

    runner.schedule({ hash: 'same' })
    await vi.advanceTimersByTimeAsync(800)
    await Promise.resolve()

    runner.schedule({ hash: 'same' })
    await vi.advanceTimersByTimeAsync(800)
    await Promise.resolve()

    expect(execute).toHaveBeenCalledTimes(1)
  })

  it('applies only latest request result and aborts prior request', async () => {
    const starts: number[] = []
    const applied: string[] = []
    let firstSignal: AbortSignal | null = null

    const execute = vi.fn((payload: { hash: string }, context) => {
      starts.push(context.requestId)
      if (payload.hash === 'first') {
        firstSignal = context.signal
        return new Promise<string>((_resolve, reject) => {
          context.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
        })
      }

      return Promise.resolve('second-ok')
    })

    const runner = createDebouncedLatestRunner({
      debounceMs: 800,
      getHash: (payload: { hash: string }) => payload.hash,
      execute,
      onRequestSuccess: (result) => {
        applied.push(result)
      },
    })

    runner.schedule({ hash: 'first' })
    await vi.advanceTimersByTimeAsync(800)
    await Promise.resolve()

    runner.schedule({ hash: 'second' })
    await vi.advanceTimersByTimeAsync(800)
    await Promise.resolve()
    await Promise.resolve()

    expect(Boolean((firstSignal as { aborted?: boolean } | null)?.aborted)).toBe(true)

    expect(starts).toHaveLength(2)
    expect(applied).toEqual(['second-ok'])
  })
})
