import { beforeEach, describe, expect, it } from 'vitest'
import { createTradeEditLock, tradeEditLockKey } from '@/services/editLockService'

class MemoryStorage implements Storage {
  private readonly map = new Map<string, string>()

  get length(): number {
    return this.map.size
  }

  clear(): void {
    this.map.clear()
  }

  getItem(key: string): string | null {
    return this.map.has(key) ? (this.map.get(key) ?? null) : null
  }

  key(index: number): string | null {
    return [...this.map.keys()][index] ?? null
  }

  removeItem(key: string): void {
    this.map.delete(key)
  }

  setItem(key: string, value: string): void {
    this.map.set(key, String(value))
  }
}

function installMemoryStorage() {
  Object.defineProperty(globalThis, 'localStorage', {
    value: new MemoryStorage(),
    configurable: true,
  })
  Object.defineProperty(globalThis, 'sessionStorage', {
    value: new MemoryStorage(),
    configurable: true,
  })
}

describe('editLockService', () => {
  beforeEach(() => {
    installMemoryStorage()
  })

  it('builds a per-trade lock key', () => {
    expect(tradeEditLockKey(55)).toBe('tj:trade-edit-lock:v1:55')
  })

  it('prevents silent concurrent edits and supports takeover', () => {
    const tabA = createTradeEditLock(77, {
      heartbeatMs: 10_000,
      monitorMs: 10_000,
      ttlMs: 30_000,
      tabId: 'tab-a',
    })
    const tabB = createTradeEditLock(77, {
      heartbeatMs: 10_000,
      monitorMs: 10_000,
      ttlMs: 30_000,
      tabId: 'tab-b',
    })

    tabA.start()
    tabB.start()

    expect(tabA.getState().holder).toBe('self')
    expect(tabB.getState().holder).toBe('other')

    const takeover = tabB.takeOver()
    expect(takeover).toBe(true)
    tabA.refresh()
    tabB.refresh()

    expect(tabA.getState().holder).toBe('other')
    expect(tabB.getState().holder).toBe('self')

    tabB.stop()
    tabA.refresh()

    expect(tabA.getState().holder).toBe('self')
    tabA.stop()
  })
})
