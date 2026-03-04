import { describe, expect, it } from 'vitest'
import { createRequestManager } from '@/services/requestManager'

function abortError(): Error {
  const error = new Error('aborted')
  Object.assign(error, {
    name: 'AbortError',
    code: 'ERR_CANCELED',
  })
  return error
}

describe('requestManager', () => {
  it('aborts stale inflight request for same key', async () => {
    const manager = createRequestManager()

    const first = manager.run({
      key: 'trades',
      fingerprint: 'page-1',
      execute: async ({ signal }) => {
        await new Promise<void>((resolve, reject) => {
          const timer = setTimeout(() => resolve(), 40)
          signal.addEventListener('abort', () => {
            clearTimeout(timer)
            reject(abortError())
          }, { once: true })
        })
        return 'first'
      },
    })

    const second = manager.run({
      key: 'trades',
      fingerprint: 'page-2',
      execute: async () => {
        await new Promise((resolve) => setTimeout(resolve, 5))
        return 'second'
      },
    })

    await expect(first).rejects.toMatchObject({ name: 'AbortError' })
    const secondResult = await second
    expect(secondResult.value).toBe('second')
    expect(secondResult.stale).toBe(false)
  })

  it('marks older request as stale when latest-wins is enabled without aborting', async () => {
    const manager = createRequestManager()

    const older = manager.run({
      key: 'checklist',
      fingerprint: 'older',
      abortStale: false,
      execute: async () => {
        await new Promise((resolve) => setTimeout(resolve, 20))
        return 'older'
      },
    })

    const latest = manager.run({
      key: 'checklist',
      fingerprint: 'latest',
      abortStale: false,
      execute: async () => {
        await new Promise((resolve) => setTimeout(resolve, 5))
        return 'latest'
      },
    })

    const latestResult = await latest
    const olderResult = await older

    expect(latestResult.stale).toBe(false)
    expect(latestResult.value).toBe('latest')
    expect(olderResult.stale).toBe(true)
    expect(olderResult.value).toBe('older')
  })

  it('dedupes identical inflight requests by key and fingerprint', async () => {
    const manager = createRequestManager()
    let executions = 0

    const one = manager.run({
      key: 'accounts',
      fingerprint: 'active-only',
      execute: async () => {
        executions += 1
        await new Promise((resolve) => setTimeout(resolve, 10))
        return { ok: true }
      },
    })

    const two = manager.run({
      key: 'accounts',
      fingerprint: 'active-only',
      execute: async () => {
        executions += 1
        return { ok: true }
      },
    })

    const [first, second] = await Promise.all([one, two])
    expect(executions).toBe(1)
    expect(first.value.ok).toBe(true)
    expect(second.value.ok).toBe(true)
  })

  it('returns cached result within ttl window', async () => {
    const manager = createRequestManager()
    let executions = 0

    const first = await manager.run({
      key: 'precheck',
      fingerprint: 'payload-a',
      cacheKey: 'precheck:payload-a',
      cacheTtlMs: 200,
      execute: async () => {
        executions += 1
        return { risk: 1.23 }
      },
    })
    const second = await manager.run({
      key: 'precheck',
      fingerprint: 'payload-a',
      cacheKey: 'precheck:payload-a',
      cacheTtlMs: 200,
      execute: async () => {
        executions += 1
        return { risk: 9.99 }
      },
    })

    expect(executions).toBe(1)
    expect(first.fromCache).toBe(false)
    expect(second.fromCache).toBe(true)
    expect(second.value.risk).toBe(1.23)
  })
})
