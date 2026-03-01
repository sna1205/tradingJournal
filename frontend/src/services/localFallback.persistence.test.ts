import { beforeEach, describe, expect, it } from 'vitest'
import {
  __readPersistedLocalFallbackEnvelopeForTests,
  __resetLocalFallbackPersistenceForTests,
  createLocalAccount,
  createLocalMissedTrade,
  createLocalTrade,
  fetchLocalAccounts,
  setOfflineModeEnabled,
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

async function flushAsyncWrites() {
  await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('localFallback IndexedDB persistence controls', () => {
  beforeEach(async () => {
    installMemoryStorage()
    await __resetLocalFallbackPersistenceForTests()
    setScope({ userId: null, accountId: null })
  })

  it('disables sensitive persistence when offline mode is OFF', async () => {
    setScope({ userId: 21, accountId: null })
    await setOfflineModeEnabled(false)

    const account = createLocalAccount({
      name: 'No Persist Account',
      broker: 'Broker',
      account_type: 'personal',
      starting_balance: 1000,
      currency: 'USD',
      is_active: true,
    })

    createLocalTrade({
      account_id: account.id,
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

    createLocalMissedTrade({
      pair: 'XAUUSD',
      model: 'Breakout',
      reason: 'hesitation,late-entry',
      date: new Date().toISOString(),
      notes: null,
    })

    await flushAsyncWrites()

    expect(await __readPersistedLocalFallbackEnvelopeForTests('accounts_v1')).toBeNull()
    expect(await __readPersistedLocalFallbackEnvelopeForTests('trades_v1')).toBeNull()
    expect(await __readPersistedLocalFallbackEnvelopeForTests('missed_trades_v1')).not.toBeNull()
  })

  it('enforces TTL metadata and caps with recency eviction in IndexedDB', async () => {
    setScope({ userId: 22, accountId: null })
    await setOfflineModeEnabled(true)

    for (let index = 0; index < 25; index += 1) {
      createLocalAccount({
        name: `Account ${index + 1}`,
        broker: 'Broker',
        account_type: 'personal',
        starting_balance: 1000 + index,
        currency: 'USD',
        is_active: true,
      })
    }

    const accountId = fetchLocalAccounts()[0]?.id ?? 1
    for (let index = 0; index < 240; index += 1) {
      createLocalTrade({
        account_id: accountId,
        symbol: 'EURUSD',
        direction: index % 2 === 0 ? 'buy' : 'sell',
        entry_price: 1.1 + (index * 0.00001),
        stop_loss: 1.09,
        take_profit: 1.12,
        actual_exit_price: 1.11,
        position_size: 0.1,
        followed_rules: true,
        emotion: 'neutral',
        close_date: new Date(Date.now() + index * 1000).toISOString(),
        notes: null,
      })
    }

    await flushAsyncWrites()

    const accountsEnvelope = await __readPersistedLocalFallbackEnvelopeForTests('accounts_v1')
    const tradesEnvelope = await __readPersistedLocalFallbackEnvelopeForTests('trades_v1')
    expect(accountsEnvelope).not.toBeNull()
    expect(tradesEnvelope).not.toBeNull()

    const accounts = (accountsEnvelope?.data as Array<{ id: number }>) ?? []
    const trades = (tradesEnvelope?.data as Array<{ id: number }>) ?? []
    expect(accounts).toHaveLength(20)
    expect(trades).toHaveLength(200)

    const tradeIds = trades.map((row) => row.id)
    expect(tradeIds.includes(240)).toBe(true)
    expect(tradeIds.includes(1)).toBe(false)

    const expireAt = Date.parse(tradesEnvelope?.expire_at ?? '')
    const ttlMs = expireAt - Date.now()
    expect(Number.isFinite(expireAt)).toBe(true)
    expect(ttlMs).toBeGreaterThan(6 * 24 * 60 * 60 * 1000)
    expect(ttlMs).toBeLessThanOrEqual(7 * 24 * 60 * 60 * 1000)
  })
})
