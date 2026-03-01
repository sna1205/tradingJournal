import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  __resetTransientImageRegistryForTests,
  createLocalAccount,
  createLocalMissedTrade,
  createLocalTrade,
  fetchLocalMissedTrade,
  fetchLocalTradeDetails,
  uploadLocalMissedTradeImage,
  uploadLocalTradeImage,
} from '@/services/localFallback'
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

describe('localFallback image persistence', () => {
  const createObjectURL = vi.fn((_: Blob) => `blob:mock-${Math.random().toString(16).slice(2)}`)
  const revokeObjectURL = vi.fn()

  beforeEach(() => {
    installMemoryStorage()
    setScope({ userId: 101, accountId: null })
    __resetTransientImageRegistryForTests()

    Object.defineProperty(globalThis.URL, 'createObjectURL', {
      value: createObjectURL,
      configurable: true,
    })
    Object.defineProperty(globalThis.URL, 'revokeObjectURL', {
      value: revokeObjectURL,
      configurable: true,
    })
  })

  it('stores trade image metadata only and keeps in-session preview URL in memory', async () => {
    const account = createLocalAccount({
      name: 'Scoped Account',
      broker: 'Local',
      account_type: 'personal',
      starting_balance: 1000,
      currency: 'USD',
      is_active: true,
    })

    const trade = createLocalTrade({
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
      notes: 'scoped image test',
    })

    const uploaded = await uploadLocalTradeImage(trade.id, new File(['fake-bytes'], 'chart-a.png', { type: 'image/png' }))
    expect(uploaded.image_url.startsWith('blob:mock-')).toBe(true)

    const storedRaw = localStorage.getItem(scopedKey('local-fallback', 'trades_v1')) ?? ''
    expect(storedRaw.includes('data:image')).toBe(false)
    expect(storedRaw.includes('base64')).toBe(false)
    expect(storedRaw.includes('blob:')).toBe(false)

    const storedEnvelope = JSON.parse(storedRaw) as {
      data: Array<{ images?: Array<{ filename?: string; image_url?: string; thumbnail_url?: string; local_object_url_key?: string }> }>
    }
    const storedImage = storedEnvelope.data[0]?.images?.[0]
    expect(storedImage?.filename).toBe('chart-a.png')
    expect(storedImage?.image_url).toBe('')
    expect(storedImage?.thumbnail_url).toBe('')
    expect(typeof storedImage?.local_object_url_key).toBe('string')

    const details = fetchLocalTradeDetails(trade.id)
    expect(details.images[0]?.image_url.startsWith('blob:mock-')).toBe(true)
  })

  it('does not crash after reload and drops image previews when transient object URLs are gone', async () => {
    const account = createLocalAccount({
      name: 'Scoped Account',
      broker: 'Local',
      account_type: 'personal',
      starting_balance: 1000,
      currency: 'USD',
      is_active: true,
    })

    const trade = createLocalTrade({
      account_id: account.id,
      symbol: 'GBPUSD',
      direction: 'sell',
      entry_price: 1.2,
      stop_loss: 1.21,
      take_profit: 1.19,
      actual_exit_price: 1.195,
      position_size: 0.1,
      followed_rules: true,
      emotion: 'neutral',
      close_date: new Date().toISOString(),
      notes: 'reload safety',
    })

    await uploadLocalTradeImage(trade.id, new File(['fake-bytes'], 'chart-b.png', { type: 'image/png' }))
    __resetTransientImageRegistryForTests()

    expect(() => fetchLocalTradeDetails(trade.id)).not.toThrow()
    const details = fetchLocalTradeDetails(trade.id)
    expect(details.images).toHaveLength(1)
    expect(details.images[0]?.image_url ?? '').toBe('')
    expect(details.images[0]?.thumbnail_url ?? '').toBe('')
  })

  it('stores missed-trade image metadata without persisting blobs/base64', async () => {
    const entry = createLocalMissedTrade({
      pair: 'XAUUSD',
      model: 'Breakout',
      reason: 'hesitation,late-entry',
      date: new Date().toISOString(),
      notes: 'missed setup image',
    })

    const uploaded = await uploadLocalMissedTradeImage(entry.id, new File(['fake-bytes'], 'missed.png', { type: 'image/png' }))
    expect(uploaded.image_url.startsWith('blob:mock-')).toBe(true)

    const storedRaw = localStorage.getItem(scopedKey('local-fallback', 'missed_trades_v1')) ?? ''
    expect(storedRaw.includes('data:image')).toBe(false)
    expect(storedRaw.includes('base64')).toBe(false)
    expect(storedRaw.includes('blob:')).toBe(false)

    const saved = fetchLocalMissedTrade(entry.id)
    expect(saved.images?.[0]?.filename).toBe('missed.png')
    expect(saved.images?.[0]?.local_object_url_key).toBeTruthy()
  })

  it('scrubs legacy persisted data/blob URLs from stored trade image payloads', () => {
    const scopedTradesKey = scopedKey('local-fallback', 'trades_v1')
    localStorage.setItem(scopedTradesKey, JSON.stringify({
      created_at: new Date().toISOString(),
      expire_at: null,
      data: [
        {
          id: 777,
          account_id: 1,
          pair: 'EURUSD',
          direction: 'buy',
          entry_price: '1.1000',
          stop_loss: '1.0900',
          take_profit: '1.1200',
          actual_exit_price: '1.1100',
          lot_size: '0.10',
          followed_rules: true,
          emotion: 'calm',
          date: new Date().toISOString(),
          notes: null,
          images_count: 1,
          images: [
            {
              id: 11,
              image_url: 'data:image/png;base64,AAAA',
              thumbnail_url: 'blob:legacy-thumb',
              file_size: 4,
              file_type: 'image/png',
              sort_order: 0,
            },
          ],
        },
      ],
    }))

    const details = fetchLocalTradeDetails(777)
    expect(details.images[0]?.image_url).toBe('')
    expect(details.images[0]?.thumbnail_url).toBe('')

    const resavedRaw = localStorage.getItem(scopedTradesKey) ?? ''
    expect(resavedRaw.includes('data:image')).toBe(false)
    expect(resavedRaw.includes('blob:legacy-thumb')).toBe(false)
  })
})
