import { beforeEach, describe, expect, it } from 'vitest'
import {
  __readPersistedLocalFallbackEnvelopeForTests,
  __resetLocalFallbackPersistenceForTests,
  createLocalAccount,
  fetchLocalAccounts,
  initializeLocalFallbackPersistence,
  migrateLegacyLocalFallbackKeys,
} from '@/services/localFallback'
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
  beforeEach(async () => {
    installMemoryStorage()
    await __resetLocalFallbackPersistenceForTests()
    setScope({ userId: null, accountId: null })
  })

  it('builds scoped keys with v3 user/account envelope', () => {
    setScope({ userId: 7, accountId: 12 })
    expect(scopedKey('local-fallback', 'trades_v1')).toBe('tj:v3:u:7:a:12:local-fallback:trades_v1')

    setScope({ userId: null, accountId: null })
    expect(scopedKey('local-fallback', 'trades_v1')).toBe('tj:v3:u:anon:a:all:local-fallback:trades_v1')
  })

  it('stores local fallback data in user-scoped IDB records and isolates users', async () => {
    setScopeUserId(101)
    createLocalAccount({
      name: 'User A',
      broker: 'Broker A',
      account_type: 'personal',
      starting_balance: 10000,
      currency: 'USD',
      is_active: true,
    })

    await new Promise((resolve) => setTimeout(resolve, 0))
    const userAEnvelope = await __readPersistedLocalFallbackEnvelopeForTests('accounts_v1')
    expect(userAEnvelope?.created_at).toBeTypeOf('string')
    expect(typeof userAEnvelope?.expire_at === 'string').toBe(true)
    expect(Array.isArray(userAEnvelope?.data)).toBe(true)

    setScopeUserId(202)
    const userBAccounts = fetchLocalAccounts()
    expect(userBAccounts.some((row) => row.name === 'User A')).toBe(false)
    expect(userBAccounts.length).toBeGreaterThan(0)
  })

  it('migrates legacy unscoped tj_* draft keys into scoped IDB records for authenticated user', async () => {
    localStorage.setItem('tj_local_accounts_v1', JSON.stringify([{ id: 1, name: 'Legacy Account' }]))
    localStorage.setItem('tj_local_trades_v1', JSON.stringify([{ id: 1, pair: 'EURUSD' }]))
    localStorage.setItem('tj_local_missed_trades_v1', JSON.stringify([{ id: 1, pair: 'GBPUSD' }]))

    setScopeUserId(88)
    migrateLegacyLocalFallbackKeys()
    await initializeLocalFallbackPersistence()

    expect(localStorage.getItem('tj_local_accounts_v1')).toBeNull()
    expect(localStorage.getItem('tj_local_trades_v1')).toBeNull()
    expect(localStorage.getItem('tj_local_missed_trades_v1')).toBeNull()
    expect(localStorage.getItem(scopedKey('local-fallback', 'accounts_v1'))).toBeNull()
    expect(localStorage.getItem(scopedKey('local-fallback', 'trades_v1'))).toBeNull()
    expect(localStorage.getItem(scopedKey('local-fallback', 'missed_trades_v1'))).toBeNull()

    const accounts = await __readPersistedLocalFallbackEnvelopeForTests('accounts_v1')
    const trades = await __readPersistedLocalFallbackEnvelopeForTests('trades_v1')
    const missedTrades = await __readPersistedLocalFallbackEnvelopeForTests('missed_trades_v1')
    expect(accounts).not.toBeNull()
    expect(trades).not.toBeNull()
    expect(missedTrades).not.toBeNull()
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
