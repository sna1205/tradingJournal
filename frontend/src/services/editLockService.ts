export interface TradeEditLockRecord {
  trade_id: number
  holder_tab_id: string
  holder_label: string
  acquired_at: string
  heartbeat_at: string
  expire_at: string
  nonce: string
}

export type TradeEditLockHolder = 'none' | 'self' | 'other'

export interface TradeEditLockState {
  tradeId: number
  tabId: string
  key: string
  holder: TradeEditLockHolder
  record: TradeEditLockRecord | null
}

export interface TradeEditLockOptions {
  heartbeatMs?: number
  monitorMs?: number
  ttlMs?: number
  tabId?: string
  holderLabel?: string
}

export interface TradeEditLockHandle {
  start: () => void
  stop: () => void
  refresh: () => void
  takeOver: () => boolean
  release: () => void
  getState: () => TradeEditLockState
  isOwnedByCurrentTab: () => boolean
  subscribe: (listener: (state: TradeEditLockState) => void) => () => void
}

const LOCK_PREFIX = 'tj:trade-edit-lock:v1:'
const TAB_ID_KEY = 'tj:tab-id:v1'
const DEFAULT_HEARTBEAT_MS = 2_000
const DEFAULT_MONITOR_MS = 1_000
const DEFAULT_TTL_MS = 8_000

let cachedTabId: string | null = null

export function tradeEditLockKey(tradeId: number): string {
  return `${LOCK_PREFIX}${tradeId}`
}

export function createTradeEditLock(tradeId: number, options?: TradeEditLockOptions): TradeEditLockHandle {
  const key = tradeEditLockKey(tradeId)
  const tabId = normalizeTabId(options?.tabId) || getOrCreateTabId()
  const holderLabel = options?.holderLabel?.trim() || `Tab ${tabId.slice(0, 6)}`
  const heartbeatMs = clampMs(options?.heartbeatMs, DEFAULT_HEARTBEAT_MS)
  const monitorMs = clampMs(options?.monitorMs, DEFAULT_MONITOR_MS)
  const ttlMs = clampMs(options?.ttlMs, DEFAULT_TTL_MS)

  const listeners = new Set<(state: TradeEditLockState) => void>()
  let state: TradeEditLockState = {
    tradeId,
    tabId,
    key,
    holder: 'none',
    record: null,
  }
  let heartbeatTimer: ReturnType<typeof setInterval> | null = null
  let monitorTimer: ReturnType<typeof setInterval> | null = null
  let started = false

  function emit(nextState: TradeEditLockState): void {
    state = nextState
    for (const listener of listeners) {
      listener(state)
    }
  }

  function updateState(holder: TradeEditLockHolder, record: TradeEditLockRecord | null): void {
    emit({
      tradeId,
      tabId,
      key,
      holder,
      record,
    })
  }

  function readRecord(): TradeEditLockRecord | null {
    const storage = safeLocalStorage()
    if (!storage) return null
    const raw = safeGet(storage, key)
    if (!raw) return null

    let parsed: unknown
    try {
      parsed = JSON.parse(raw) as unknown
    } catch {
      return null
    }

    if (!isRecord(parsed)) return null
    if (Number(parsed.trade_id) !== tradeId) return null

    const record: TradeEditLockRecord = {
      trade_id: tradeId,
      holder_tab_id: String(parsed.holder_tab_id ?? ''),
      holder_label: String(parsed.holder_label ?? ''),
      acquired_at: String(parsed.acquired_at ?? ''),
      heartbeat_at: String(parsed.heartbeat_at ?? ''),
      expire_at: String(parsed.expire_at ?? ''),
      nonce: String(parsed.nonce ?? ''),
    }

    if (!record.holder_tab_id || !record.expire_at || !record.nonce) {
      return null
    }

    return record
  }

  function writeRecord(record: TradeEditLockRecord): void {
    const storage = safeLocalStorage()
    if (!storage) return
    safeSet(storage, key, JSON.stringify(record))
  }

  function removeIfOwned(): void {
    const current = readRecord()
    if (!current) return
    if (current.holder_tab_id !== tabId) return
    const storage = safeLocalStorage()
    if (!storage) return
    safeRemove(storage, key)
  }

  function isExpired(record: TradeEditLockRecord): boolean {
    const expireAtMs = Date.parse(record.expire_at)
    if (!Number.isFinite(expireAtMs)) return true
    return expireAtMs <= Date.now()
  }

  function buildRecord(previous?: TradeEditLockRecord | null): TradeEditLockRecord {
    const now = new Date().toISOString()
    return {
      trade_id: tradeId,
      holder_tab_id: tabId,
      holder_label: holderLabel,
      acquired_at: previous?.acquired_at || now,
      heartbeat_at: now,
      expire_at: new Date(Date.now() + ttlMs).toISOString(),
      nonce: `${Date.now()}-${Math.random().toString(16).slice(2, 10)}`,
    }
  }

  function tryAcquire(force = false): boolean {
    const current = readRecord()
    if (current && !isExpired(current) && current.holder_tab_id !== tabId && !force) {
      updateState('other', current)
      return false
    }

    const next = buildRecord(current?.holder_tab_id === tabId ? current : null)
    writeRecord(next)

    const confirmed = readRecord()
    const owned = Boolean(
      confirmed
      && confirmed.holder_tab_id === tabId
      && confirmed.nonce === next.nonce
    )

    if (owned) {
      updateState('self', confirmed)
      return true
    }

    if (confirmed && !isExpired(confirmed)) {
      updateState(confirmed.holder_tab_id === tabId ? 'self' : 'other', confirmed)
      return confirmed.holder_tab_id === tabId
    }

    updateState('none', null)
    return false
  }

  function heartbeat(): void {
    const current = readRecord()
    if (!current) {
      updateState('none', null)
      return
    }
    if (isExpired(current)) {
      const storage = safeLocalStorage()
      if (storage) {
        safeRemove(storage, key)
      }
      updateState('none', null)
      return
    }
    if (current.holder_tab_id !== tabId) {
      updateState('other', current)
      return
    }

    const next = buildRecord(current)
    writeRecord(next)
    const confirmed = readRecord()
    if (confirmed && confirmed.holder_tab_id === tabId && !isExpired(confirmed)) {
      updateState('self', confirmed)
      return
    }
    if (confirmed && !isExpired(confirmed)) {
      updateState('other', confirmed)
      return
    }
    updateState('none', null)
  }

  function sync(allowAcquire: boolean): void {
    const current = readRecord()
    if (!current) {
      if (allowAcquire) {
        tryAcquire(false)
      } else {
        updateState('none', null)
      }
      return
    }
    if (isExpired(current)) {
      const storage = safeLocalStorage()
      if (storage) {
        safeRemove(storage, key)
      }
      if (allowAcquire) {
        tryAcquire(false)
      } else {
        updateState('none', null)
      }
      return
    }
    if (current.holder_tab_id === tabId) {
      updateState('self', current)
      return
    }
    updateState('other', current)
    if (allowAcquire) {
      return
    }
  }

  function onStorage(event: StorageEvent): void {
    if (event.key !== key) return
    sync(false)
  }

  function start(): void {
    if (started) return
    started = true

    sync(true)
    heartbeatTimer = setInterval(() => {
      if (state.holder === 'self') {
        heartbeat()
      }
    }, heartbeatMs)
    monitorTimer = setInterval(() => {
      if (state.holder === 'self') {
        heartbeat()
      } else {
        sync(true)
      }
    }, monitorMs)

    if (typeof window !== 'undefined') {
      window.addEventListener('storage', onStorage)
    }
  }

  function stop(): void {
    if (!started) return
    started = false

    if (heartbeatTimer) {
      clearInterval(heartbeatTimer)
      heartbeatTimer = null
    }
    if (monitorTimer) {
      clearInterval(monitorTimer)
      monitorTimer = null
    }

    if (typeof window !== 'undefined') {
      window.removeEventListener('storage', onStorage)
    }
    removeIfOwned()
    updateState('none', null)
  }

  function refresh(): void {
    if (state.holder === 'self') {
      heartbeat()
      return
    }
    sync(true)
  }

  function takeOver(): boolean {
    const acquired = tryAcquire(true)
    if (!acquired) {
      sync(false)
    }
    return acquired
  }

  function release(): void {
    removeIfOwned()
    sync(false)
  }

  function getState(): TradeEditLockState {
    return state
  }

  function isOwnedByCurrentTab(): boolean {
    return state.holder === 'self'
  }

  function subscribe(listener: (nextState: TradeEditLockState) => void): () => void {
    listeners.add(listener)
    listener(state)
    return () => {
      listeners.delete(listener)
    }
  }

  return {
    start,
    stop,
    refresh,
    takeOver,
    release,
    getState,
    isOwnedByCurrentTab,
    subscribe,
  }
}

function clampMs(value: number | undefined, fallback: number): number {
  const parsed = Number(value)
  if (!Number.isFinite(parsed) || parsed <= 0) return fallback
  return Math.trunc(parsed)
}

function normalizeTabId(value: string | undefined): string | null {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed || null
}

function getOrCreateTabId(): string {
  if (cachedTabId) return cachedTabId

  const storage = safeSessionStorage()
  if (storage) {
    const existing = safeGet(storage, TAB_ID_KEY)
    if (existing && existing.trim()) {
      cachedTabId = existing.trim()
      return cachedTabId
    }
  }

  const next = `${Date.now()}-${Math.random().toString(16).slice(2, 10)}`
  cachedTabId = next

  if (storage) {
    safeSet(storage, TAB_ID_KEY, next)
  }

  return next
}

function safeLocalStorage(): Storage | null {
  try {
    if (typeof localStorage === 'undefined') return null
    return localStorage
  } catch {
    return null
  }
}

function safeSessionStorage(): Storage | null {
  try {
    if (typeof sessionStorage === 'undefined') return null
    return sessionStorage
  } catch {
    return null
  }
}

function safeGet(storage: Storage, key: string): string | null {
  try {
    return storage.getItem(key)
  } catch {
    return null
  }
}

function safeSet(storage: Storage, key: string, value: string): void {
  try {
    storage.setItem(key, value)
  } catch {
    // Ignore storage write failures.
  }
}

function safeRemove(storage: Storage, key: string): void {
  try {
    storage.removeItem(key)
  } catch {
    // Ignore storage remove failures.
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
