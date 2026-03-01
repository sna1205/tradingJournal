import { beforeEach, describe, expect, it } from 'vitest'
import { createLocalAccount, fetchLocalAccounts, migrateLegacyLocalFallbackKeys } from '@/services/localFallback'
import { getScope, scopedKey, setScope, setScopeUserId } from '@/services/storageScope'

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

describe('storage scope + local fallback scoping', () => {
  beforeEach(() => {
    installMemoryStorage()
    setScope({ userId: null, accountId: null })
  })

  it('builds scoped keys with v3 user/account envelope', () => {
    setScope({ userId: 7, accountId: 12 })
    expect(scopedKey('local-fallback', 'trades_v1')).toBe('tj:v3:u:7:a:12:local-fallback:trades_v1')

    setScope({ userId: null, accountId: null })
    expect(scopedKey('local-fallback', 'trades_v1')).toBe('tj:v3:u:anon:a:all:local-fallback:trades_v1')
  })

  it('stores local fallback data in user-scoped keys and isolates users', () => {
    setScopeUserId(101)
    createLocalAccount({
      name: 'User A',
      broker: 'Broker A',
      account_type: 'personal',
      starting_balance: 10000,
      currency: 'USD',
      is_active: true,
    })

    const userAKey = scopedKey('local-fallback', 'accounts_v1')
    const userAEnvelope = JSON.parse(localStorage.getItem(userAKey) ?? '{}') as {
      created_at?: string
      expire_at?: string | null
      data?: unknown
    }
    expect(userAEnvelope.created_at).toBeTypeOf('string')
    expect(userAEnvelope.expire_at).toBeNull()
    expect(Array.isArray(userAEnvelope.data)).toBe(true)

    setScopeUserId(202)
    const userBAccounts = fetchLocalAccounts()
    expect(userBAccounts.some((row) => row.name === 'User A')).toBe(false)
    expect(userBAccounts.length).toBeGreaterThan(0)
  })

  it('migrates legacy unscoped tj_* draft keys into scoped keys for authenticated user', () => {
    localStorage.setItem('tj_local_accounts_v1', JSON.stringify([{ id: 1, name: 'Legacy Account' }]))
    localStorage.setItem('tj_local_trades_v1', JSON.stringify([{ id: 1, pair: 'EURUSD' }]))
    localStorage.setItem('tj_local_missed_trades_v1', JSON.stringify([{ id: 1, pair: 'GBPUSD' }]))

    setScopeUserId(88)
    migrateLegacyLocalFallbackKeys()

    expect(localStorage.getItem('tj_local_accounts_v1')).toBeNull()
    expect(localStorage.getItem('tj_local_trades_v1')).toBeNull()
    expect(localStorage.getItem('tj_local_missed_trades_v1')).toBeNull()

    expect(localStorage.getItem(scopedKey('local-fallback', 'accounts_v1'))).not.toBeNull()
    expect(localStorage.getItem(scopedKey('local-fallback', 'trades_v1'))).not.toBeNull()
    expect(localStorage.getItem(scopedKey('local-fallback', 'missed_trades_v1'))).not.toBeNull()
  })

  it('purges unsafe anonymous legacy tj_* keys and keeps only feature flags', () => {
    setScopeUserId(null)
    localStorage.setItem('tj_feature_new_dashboard', '1')
    localStorage.setItem('tj_local_accounts_v1', JSON.stringify([{ id: 1 }]))
    localStorage.setItem('tj_local_trades_v1', JSON.stringify([{ id: 2 }]))
    localStorage.setItem('tj_local_missed_trades_v1', JSON.stringify([{ id: 3 }]))

    migrateLegacyLocalFallbackKeys()

    expect(localStorage.getItem('tj_feature_new_dashboard')).toBe('1')
    expect(localStorage.getItem('tj_local_accounts_v1')).toBeNull()
    expect(localStorage.getItem('tj_local_trades_v1')).toBeNull()
    expect(localStorage.getItem('tj_local_missed_trades_v1')).toBeNull()
    expect(getScope().userId).toBeNull()
  })
})
