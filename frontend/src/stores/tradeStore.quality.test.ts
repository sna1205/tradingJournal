import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTradeStore } from '@/stores/tradeStore'
import { scopedKey, setScope } from '@/services/storageScope'

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

describe('tradeStore quality segregation preference', () => {
  beforeEach(() => {
    installMemoryStorage()
    setActivePinia(createPinia())
    setScope({ userId: null, accountId: null })
  })

  it('defaults to excluding drafts/unverified', () => {
    const store = useTradeStore()
    expect(store.includeDraftsUnverified).toBe(false)
    expect(store.isTradeQualityIncluded({ local_sync_status: 'synced', risk_validation_status: 'verified' })).toBe(true)
    expect(store.isTradeQualityIncluded({ local_sync_status: 'draft_local', risk_validation_status: 'unverified' })).toBe(false)
  })

  it('persists include toggle in scoped storage', () => {
    const store = useTradeStore()
    store.setIncludeDraftsUnverified(true)

    const key = scopedKey('trade-preferences', 'include_drafts_unverified')
    expect(localStorage.getItem(key)).toBe('1')
    expect(store.isTradeQualityIncluded({ local_sync_status: 'draft_local', risk_validation_status: 'unverified' })).toBe(true)
  })

  it('reads preference from current scope when refreshed', () => {
    setScope({ userId: 7, accountId: null })
    localStorage.setItem(scopedKey('trade-preferences', 'include_drafts_unverified'), '1')

    setScope({ userId: 8, accountId: null })
    localStorage.setItem(scopedKey('trade-preferences', 'include_drafts_unverified'), '0')

    const store = useTradeStore()

    setScope({ userId: 7, accountId: null })
    store.refreshTradeQualityPreference()
    expect(store.includeDraftsUnverified).toBe(true)

    setScope({ userId: 8, accountId: null })
    store.refreshTradeQualityPreference()
    expect(store.includeDraftsUnverified).toBe(false)
  })
})
