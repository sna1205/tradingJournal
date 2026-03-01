import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/authStore'
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

describe('authStore scoped logout purge', () => {
  beforeEach(() => {
    installMemoryStorage()
    setActivePinia(createPinia())
    setScope({ userId: null, accountId: null })
  })

  it('purges scoped storage for current user on clearSession/logout', () => {
    setScope({ userId: 7, accountId: null })
    const user7TradesKey = scopedKey('local-fallback', 'trades_v1')
    localStorage.setItem(user7TradesKey, JSON.stringify({ data: [{ id: 1 }] }))
    sessionStorage.setItem('tj:v3:u:7:a:all:local-fallback:accounts_v1', JSON.stringify({ data: [{ id: 1 }] }))

    setScope({ userId: 99, accountId: null })
    const user99TradesKey = scopedKey('local-fallback', 'trades_v1')
    localStorage.setItem(user99TradesKey, JSON.stringify({ data: [{ id: 2 }] }))

    const authStore = useAuthStore()
    authStore.user = {
      id: 7,
      name: 'Trader A',
      email: 'a@example.com',
    }

    authStore.clearSession()

    expect(authStore.user).toBeNull()
    expect(localStorage.getItem(user7TradesKey)).toBeNull()
    expect(sessionStorage.getItem('tj:v3:u:7:a:all:local-fallback:accounts_v1')).toBeNull()
    expect(localStorage.getItem(user99TradesKey)).not.toBeNull()
  })
})
