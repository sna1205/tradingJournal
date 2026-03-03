import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/authStore'
import {
  __readPersistedLocalFallbackEnvelopeForTests,
  __resetLocalFallbackPersistenceForTests,
  createLocalAccount,
  createLocalTrade,
} from '@/services/localFallback'
import { setScope } from '@/services/storageScope'

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

describe('authStore scoped logout purge', () => {
  beforeEach(async () => {
    installMemoryStorage()
    await __resetLocalFallbackPersistenceForTests()
    setActivePinia(createPinia())
    setScope({ userId: null, accountId: null })
  })

  it('purges scoped IDB records for current user on clearSession/logout', async () => {
    setScope({ userId: 7, accountId: null })
    const user7Account = createLocalAccount({
      name: 'Trader A Account',
      broker: 'Broker A',
      account_type: 'personal',
      starting_balance: 10000,
      currency: 'USD',
      is_active: true,
    })
    createLocalTrade({
      account_id: user7Account.id,
      symbol: 'EURUSD',
      direction: 'buy',
      entry_price: 1.1,
      stop_loss: 1.09,
      take_profit: 1.12,
      actual_exit_price: 1.11,
      position_size: 0.1,
      followed_rules: true,
      emotion: 'calm',
      close_date: new Date().toISOString(),
      notes: null,
    })
    await new Promise((resolve) => setTimeout(resolve, 0))

    setScope({ userId: 99, accountId: null })
    const user99Account = createLocalAccount({
      name: 'Trader B Account',
      broker: 'Broker B',
      account_type: 'personal',
      starting_balance: 15000,
      currency: 'USD',
      is_active: true,
    })
    createLocalTrade({
      account_id: user99Account.id,
      symbol: 'GBPUSD',
      direction: 'sell',
      entry_price: 1.2,
      stop_loss: 1.21,
      take_profit: 1.19,
      actual_exit_price: 1.195,
      position_size: 0.2,
      followed_rules: true,
      emotion: 'neutral',
      close_date: new Date().toISOString(),
      notes: null,
    })
    await new Promise((resolve) => setTimeout(resolve, 0))

    const authStore = useAuthStore()
    authStore.user = {
      id: 7,
      name: 'Trader A',
      email: 'a@example.com',
    }

    await authStore.clearSession()

    expect(authStore.user).toBeNull()

    setScope({ userId: 7, accountId: null })
    expect(await __readPersistedLocalFallbackEnvelopeForTests('accounts_v1')).toBeNull()
    expect(await __readPersistedLocalFallbackEnvelopeForTests('trades_v1')).toBeNull()

    setScope({ userId: 99, accountId: null })
    expect(await __readPersistedLocalFallbackEnvelopeForTests('accounts_v1')).not.toBeNull()
    expect(await __readPersistedLocalFallbackEnvelopeForTests('trades_v1')).not.toBeNull()
  })
})
