import type { Account, AccountAnalyticsPayload, AccountEquityPayload, AccountType } from '@/types/account'
import { isConnectivityFailure, type SyncQueueStatus } from '@/services/offlineSyncQueue'
import {
  type StorageScope,
  getScope,
  migrateLegacyUnscopedKeys,
  scopedKeyForScope,
} from '@/services/storageScope'
import {
  __resetScopedIndexedDbForTests,
  deleteScopedRecord,
  getScopedRecord,
  purgeScopedRecordsForUser,
  putScopedRecord,
  scopedRecordId,
  type ScopedIndexedDbRecord,
} from '@/services/scopedIndexedDb'
import type {
  MissedTrade,
  MissedTradeImage,
  Paginated,
  Trade,
  TradeEmotion,
  TradeImage,
} from '@/types/trade'

const LOCAL_FALLBACK_NAMESPACE = 'local-fallback'
const ACCOUNTS_KEY = 'accounts_v1'
const TRADES_KEY = 'trades_v1'
const MISSED_TRADES_KEY = 'missed_trades_v1'
const LEGACY_ACCOUNTS_KEY = 'tj_local_accounts_v1'
const LEGACY_TRADES_KEY = 'tj_local_trades_v1'
const LEGACY_MISSED_TRADES_KEY = 'tj_local_missed_trades_v1'
const OFFLINE_MODE_KEY = 'tj_offline_mode_enabled'
const OFFLINE_MODE_ON = '1'
const OFFLINE_MODE_OFF = '0'
const DEFAULT_TTL_MS = 7 * 24 * 60 * 60 * 1000
const MAX_TRADES = 200
const MAX_ACCOUNTS = 20
const SENSITIVE_KEYS = new Set<string>([ACCOUNTS_KEY, TRADES_KEY])
let migrationScopeMarker = ''
const transientObjectUrlByKey = new Map<string, string>()
const scopedEnvelopeCache = new Map<string, LocalStorageEnvelope<unknown>>()
const scopeHydrationPromises = new Map<string, Promise<void>>()
const hydratedScopeMarkers = new Set<string>()
const persistenceQueuesByScopedKey = new Map<string, Promise<void>>()
let offlineModeEnabledCache: boolean | null = null

export interface AccountPayloadLike {
  name: string
  broker: string
  account_type: AccountType
  starting_balance: number
  currency: string
  is_active: boolean
}

export interface TradePayloadLike {
  account_id: number
  symbol: string
  direction: 'buy' | 'sell'
  entry_price: number
  stop_loss: number
  take_profit: number
  actual_exit_price: number
  position_size: number
  followed_rules: boolean
  emotion: TradeEmotion
  session?: string
  strategy_model?: string
  close_date: string
  notes: string | null
}

export interface MissedTradePayloadLike {
  pair: string
  model: string
  reason: string
  date: string
  notes: string | null
}

export function shouldUseLocalFallback(error: unknown): boolean {
  return isConnectivityFailure(error)
}

export function isOfflineModeEnabled(): boolean {
  if (offlineModeEnabledCache !== null) {
    return offlineModeEnabledCache
  }

  const storage = safeLocalStorage()
  if (!storage) {
    offlineModeEnabledCache = true
    return offlineModeEnabledCache
  }

  const raw = safeGet(storage, OFFLINE_MODE_KEY)
  if (raw === OFFLINE_MODE_OFF) {
    offlineModeEnabledCache = false
    return offlineModeEnabledCache
  }
  if (raw === OFFLINE_MODE_ON) {
    offlineModeEnabledCache = true
    return offlineModeEnabledCache
  }

  offlineModeEnabledCache = true
  return offlineModeEnabledCache
}

export async function setOfflineModeEnabled(enabled: boolean): Promise<boolean> {
  const next = Boolean(enabled)
  offlineModeEnabledCache = next

  const storage = safeLocalStorage()
  if (storage) {
    safeSet(storage, OFFLINE_MODE_KEY, next ? OFFLINE_MODE_ON : OFFLINE_MODE_OFF)
  }

  if (!next) {
    await purgeLocalFallbackPersistenceForUser(getScope().userId)
  } else {
    await initializeLocalFallbackPersistence()
  }

  return next
}

export function migrateLegacyLocalFallbackKeys(): void {
  const scope = getScope()
  const scopeMarker = `${scope.userId ?? 'anon'}:${scope.accountId ?? 'all'}`
  if (scopeMarker === migrationScopeMarker) return

  migrateLegacyUnscopedKeys([
    {
      legacyKey: LEGACY_ACCOUNTS_KEY,
      namespace: LOCAL_FALLBACK_NAMESPACE,
      key: ACCOUNTS_KEY,
      allowAnonymous: false,
    },
    {
      legacyKey: LEGACY_TRADES_KEY,
      namespace: LOCAL_FALLBACK_NAMESPACE,
      key: TRADES_KEY,
      allowAnonymous: false,
    },
    {
      legacyKey: LEGACY_MISSED_TRADES_KEY,
      namespace: LOCAL_FALLBACK_NAMESPACE,
      key: MISSED_TRADES_KEY,
      allowAnonymous: false,
    },
  ])

  migrationScopeMarker = scopeMarker
}

export async function initializeLocalFallbackPersistence(): Promise<void> {
  migrateLegacyLocalFallbackKeys()
  await hydrateScope(getScope())
  purgeLegacySensitiveScopedKeys()
}

export async function purgeLocalFallbackPersistenceForUser(userId: number | null | undefined): Promise<void> {
  const normalized = normalizeScopeUserId(userId)
  if (normalized === null) {
    clearSensitiveScopeCache(getScope())
    purgeLegacySensitiveScopedKeys()
    return
  }

  await purgeScopedRecordsForUser(normalized, LOCAL_FALLBACK_NAMESPACE)
  clearSensitiveUserCache(normalized)
  purgeLegacySensitiveScopedKeys()
}

export function __resetTransientImageRegistryForTests(): void {
  for (const url of transientObjectUrlByKey.values()) {
    try {
      globalThis.URL?.revokeObjectURL?.(url)
    } catch {
      // Ignore object URL cleanup failures in tests.
    }
  }
  transientObjectUrlByKey.clear()
}

export async function __resetLocalFallbackPersistenceForTests(): Promise<void> {
  migrationScopeMarker = ''
  offlineModeEnabledCache = null
  scopedEnvelopeCache.clear()
  scopeHydrationPromises.clear()
  hydratedScopeMarkers.clear()
  persistenceQueuesByScopedKey.clear()
  await __resetScopedIndexedDbForTests()
}

export async function __readPersistedLocalFallbackEnvelopeForTests(key: string): Promise<LocalStorageEnvelope<unknown> | null> {
  const scope = getScope()
  const persisted = await getScopedRecord<unknown>(scope, LOCAL_FALLBACK_NAMESPACE, key)
  if (!persisted) return null

  return {
    created_at: persisted.created_at,
    updated_at: persisted.updated_at,
    expire_at: persisted.expire_at,
    data: persisted.payload,
  }
}

export function fetchLocalAccounts(params?: { is_active?: boolean }): Account[] {
  const accounts = ensureAccounts()
  if (typeof params?.is_active !== 'boolean') {
    return accounts
  }
  return accounts.filter((account) => account.is_active === params.is_active)
}

export function createLocalAccount(payload: AccountPayloadLike): Account {
  const accounts = ensureAccounts()
  const id = accounts.reduce((max, account) => Math.max(max, account.id), 0) + 1
  const now = nowIso()
  const created: Account = {
    id,
    user_id: null,
    name: payload.name.trim(),
    broker: payload.broker.trim(),
    account_type: payload.account_type,
    starting_balance: asMoney(payload.starting_balance),
    current_balance: asMoney(payload.starting_balance),
    currency: payload.currency.trim().toUpperCase() || 'USD',
    is_active: payload.is_active,
    local_sync_status: 'draft_local',
    created_at: now,
    updated_at: now,
  }

  const next = [created, ...accounts]
  writeJson(ACCOUNTS_KEY, next)
  return created
}

export function updateLocalAccount(id: number, payload: Partial<AccountPayloadLike>): Account {
  const accounts = ensureAccounts()
  const index = accounts.findIndex((account) => account.id === id)
  if (index < 0) throw new Error('Account not found.')

  const current = accounts[index]!
  const updated: Account = {
    ...current,
    name: payload.name !== undefined ? payload.name.trim() : current.name,
    broker: payload.broker !== undefined ? payload.broker.trim() : current.broker,
    account_type: payload.account_type ?? current.account_type,
    starting_balance: payload.starting_balance !== undefined ? asMoney(payload.starting_balance) : current.starting_balance,
    currency: payload.currency !== undefined
      ? (payload.currency.trim().toUpperCase() || current.currency)
      : current.currency,
    is_active: payload.is_active ?? current.is_active,
    local_sync_status: current.local_sync_status ?? 'draft_local',
    updated_at: nowIso(),
  }

  accounts[index] = updated
  const recomputed = recomputeTradesAndBalances(accounts, readTrades())
  writeJson(ACCOUNTS_KEY, recomputed.accounts)
  writeJson(TRADES_KEY, recomputed.trades)
  return recomputed.accounts.find((account) => account.id === id) ?? updated
}

export function deleteLocalAccount(id: number): void {
  const accounts = ensureAccounts()
  if (accounts.length <= 1) {
    throw new Error('At least one account is required.')
  }

  const hasTrades = readTrades().some((trade) => trade.account_id === id)
  if (hasTrades) {
    throw new Error('Cannot delete account with existing trades.')
  }

  writeJson(ACCOUNTS_KEY, accounts.filter((account) => account.id !== id))
}

export function upsertLocalAccountSnapshot(account: Account): Account {
  const normalized = normalizeAccount(account)
  if (!normalized) {
    throw new Error('Invalid account snapshot.')
  }

  const accounts = ensureAccounts()
  const index = accounts.findIndex((row) => row.id === normalized.id)
  const next = accounts.slice()
  if (index >= 0) {
    next[index] = {
      ...next[index]!,
      ...normalized,
      local_sync_status: next[index]!.local_sync_status ?? normalized.local_sync_status ?? 'synced',
    }
  } else {
    next.unshift({
      ...normalized,
      local_sync_status: normalized.local_sync_status ?? 'synced',
    })
  }

  writeJson(ACCOUNTS_KEY, next)
  return next.find((row) => row.id === normalized.id) ?? normalized
}

export function setLocalAccountSyncStatus(id: number, status: SyncQueueStatus): Account | null {
  const accounts = ensureAccounts()
  const index = accounts.findIndex((account) => account.id === id)
  if (index < 0) return null

  const updated: Account = {
    ...accounts[index]!,
    local_sync_status: status,
    updated_at: nowIso(),
  }
  accounts[index] = updated
  writeJson(ACCOUNTS_KEY, accounts)
  return updated
}

export function fetchLocalAccountEquity(id: number): AccountEquityPayload {
  const account = ensureAccounts().find((item) => item.id === id)
  if (!account) throw new Error('Account not found.')

  const trades = readTrades()
    .filter((trade) => trade.account_id === id)
    .sort((left, right) => left.date.localeCompare(right.date) || left.id - right.id)

  const points: number[] = []
  const timestamps: string[] = []
  let running = toNumber(account.starting_balance)
  let peak = running
  let maxDrawdown = 0

  for (const trade of trades) {
    running = round(running + toNumber(trade.profit_loss), 2)
    points.push(running)
    timestamps.push(isoDate(trade.date))
    peak = Math.max(peak, running)
    maxDrawdown = Math.max(maxDrawdown, peak - running)
  }

  return {
    account_id: account.id,
    equity_points: points,
    equity_timestamps: timestamps,
    max_drawdown: round(maxDrawdown, 2),
    peak_balance: round(peak, 2),
    net_profit: round(running - toNumber(account.starting_balance), 2),
  }
}

export function fetchLocalAccountAnalytics(id: number): AccountAnalyticsPayload {
  const account = ensureAccounts().find((item) => item.id === id)
  if (!account) throw new Error('Account not found.')

  const trades = readTrades().filter((trade) => trade.account_id === id)
  const totals = summarizeTrades(trades)
  const drawdown = computeDrawdown(fetchLocalAccountEquity(id).equity_points, toNumber(account.starting_balance))
  const recovery = drawdown.max_drawdown > 0 ? round(totals.net / drawdown.max_drawdown, 2) : null

  return {
    account_id: id,
    win_rate: totals.winRate,
    profit_factor: totals.profitFactor,
    expectancy: totals.expectancy,
    max_drawdown: drawdown.max_drawdown,
    max_drawdown_percent: drawdown.max_drawdown_percent,
    recovery_factor: recovery,
    average_r: totals.avgR,
    longest_streak: {
      type: 'flat',
      length: 0,
    },
    longest_win_streak: 0,
    longest_loss_streak: 0,
    total_trades: totals.total,
    net_profit: totals.net,
  }
}

export function queryLocalTrades(params: {
  page: number
  per_page: number
  pair?: string
  direction?: '' | 'buy' | 'sell'
  model?: string
  date_from?: string
  date_to?: string
  account_id?: number
  include_drafts_unverified?: boolean
}): Paginated<Trade> {
  const page = Math.max(1, params.page || 1)
  const perPage = Math.max(1, Math.min(100, params.per_page || 15))
  const trades = readTrades()
    .filter((trade) => {
      if (params.account_id !== undefined && trade.account_id !== params.account_id) return false
      if (params.pair && !trade.pair.toLowerCase().includes(params.pair.toLowerCase().trim())) return false
      if (params.direction && trade.direction !== params.direction) return false
      if (params.model && !trade.model.toLowerCase().includes(params.model.toLowerCase().trim())) return false

      const day = isoDate(trade.date)
      if (params.date_from && day < params.date_from) return false
      if (params.date_to && day > params.date_to) return false
      if (!params.include_drafts_unverified) {
        if ((trade.local_sync_status ?? 'synced') !== 'synced') return false
        if ((trade.risk_validation_status ?? 'verified') !== 'verified') return false
      }
      return true
    })
    .sort((left, right) => right.date.localeCompare(left.date) || right.id - left.id)

  const total = trades.length
  const lastPage = Math.max(1, Math.ceil(total / perPage))
  const currentPage = Math.min(page, lastPage)
  const start = (currentPage - 1) * perPage

  return {
    current_page: currentPage,
    data: trades.slice(start, start + perPage),
    last_page: lastPage,
    per_page: perPage,
    total,
  }
}

export function createLocalTrade(payload: TradePayloadLike): Trade {
  const accounts = ensureAccounts()
  const accountId = accounts.some((account) => account.id === payload.account_id)
    ? payload.account_id
    : accounts[0]!.id
  const trades = readTrades()
  const now = nowIso()
  const created: Trade = {
    id: trades.reduce((max, trade) => Math.max(max, trade.id), 0) + 1,
    account_id: accountId,
    pair: payload.symbol.trim().toUpperCase(),
    direction: payload.direction,
    entry_price: asPrice(payload.entry_price),
    stop_loss: asPrice(payload.stop_loss),
    take_profit: asPrice(payload.take_profit),
    actual_exit_price: asPrice(payload.actual_exit_price),
    lot_size: asLot(payload.position_size),
    risk_per_unit: null,
    reward_per_unit: null,
    monetary_risk: null,
    monetary_reward: null,
    profit_loss: '0.00',
    rr: '0.00',
    r_multiple: '0.0000',
    risk_percent: '0.0000',
    account_balance_before_trade: null,
    account_balance_after_trade: null,
    followed_rules: payload.followed_rules,
    emotion: payload.emotion,
    session: payload.session?.trim() || 'N/A',
    model: payload.strategy_model?.trim() || 'General',
    date: toIso(payload.close_date),
    notes: payload.notes,
    local_sync_status: 'draft_local',
    risk_validation_status: 'unverified',
    images: [],
    images_count: 0,
    created_at: now,
    updated_at: now,
  }

  const recomputed = recomputeTradesAndBalances(accounts, [created, ...trades])
  writeJson(ACCOUNTS_KEY, recomputed.accounts)
  writeJson(TRADES_KEY, recomputed.trades)
  return recomputed.trades.find((trade) => trade.id === created.id) ?? created
}

export function updateLocalTrade(id: number, payload: Partial<TradePayloadLike>): Trade {
  const accounts = ensureAccounts()
  const trades = readTrades()
  const index = trades.findIndex((trade) => trade.id === id)
  if (index < 0) throw new Error('Trade not found.')

  const current = trades[index]!
  trades[index] = {
    ...current,
    account_id: payload.account_id !== undefined && accounts.some((account) => account.id === payload.account_id)
      ? payload.account_id
      : current.account_id,
    pair: payload.symbol !== undefined ? payload.symbol.trim().toUpperCase() : current.pair,
    direction: payload.direction ?? current.direction,
    entry_price: payload.entry_price !== undefined ? asPrice(payload.entry_price) : current.entry_price,
    stop_loss: payload.stop_loss !== undefined ? asPrice(payload.stop_loss) : current.stop_loss,
    take_profit: payload.take_profit !== undefined ? asPrice(payload.take_profit) : current.take_profit,
    actual_exit_price: payload.actual_exit_price !== undefined ? asPrice(payload.actual_exit_price) : current.actual_exit_price,
    lot_size: payload.position_size !== undefined ? asLot(payload.position_size) : current.lot_size,
    followed_rules: payload.followed_rules ?? current.followed_rules,
    emotion: payload.emotion ?? current.emotion,
    session: payload.session !== undefined ? payload.session.trim() || 'N/A' : current.session,
    model: payload.strategy_model !== undefined ? payload.strategy_model.trim() || 'General' : current.model,
    date: payload.close_date !== undefined ? toIso(payload.close_date) : current.date,
    notes: payload.notes !== undefined ? payload.notes : current.notes,
    local_sync_status: current.local_sync_status ?? 'draft_local',
    risk_validation_status: current.risk_validation_status ?? 'unverified',
    updated_at: nowIso(),
  }

  const recomputed = recomputeTradesAndBalances(accounts, trades)
  writeJson(ACCOUNTS_KEY, recomputed.accounts)
  writeJson(TRADES_KEY, recomputed.trades)
  const updated = recomputed.trades.find((trade) => trade.id === id)
  if (!updated) throw new Error('Trade not found.')
  return updated
}

export function deleteLocalTrade(id: number): void {
  const recomputed = recomputeTradesAndBalances(ensureAccounts(), readTrades().filter((trade) => trade.id !== id))
  writeJson(ACCOUNTS_KEY, recomputed.accounts)
  writeJson(TRADES_KEY, recomputed.trades)
}

export function upsertLocalTradeSnapshot(trade: Trade): Trade {
  const normalized = normalizeTrade(trade)
  if (!normalized) {
    throw new Error('Invalid trade snapshot.')
  }

  const trades = readTrades()
  const index = trades.findIndex((row) => row.id === normalized.id)
  const next = trades.slice()
  if (index >= 0) {
    next[index] = {
      ...next[index]!,
      ...normalized,
      local_sync_status: next[index]!.local_sync_status ?? normalized.local_sync_status ?? 'synced',
      risk_validation_status: next[index]!.risk_validation_status ?? normalized.risk_validation_status ?? 'verified',
    }
  } else {
    next.unshift({
      ...normalized,
      local_sync_status: normalized.local_sync_status ?? 'synced',
      risk_validation_status: normalized.risk_validation_status ?? 'verified',
    })
  }

  const recomputed = recomputeTradesAndBalances(ensureAccounts(), next)
  writeJson(ACCOUNTS_KEY, recomputed.accounts)
  writeJson(TRADES_KEY, recomputed.trades)
  return recomputed.trades.find((row) => row.id === normalized.id) ?? normalized
}

export function setLocalTradeSyncStatus(
  id: number,
  status: SyncQueueStatus,
  riskValidationStatus?: 'verified' | 'unverified'
): Trade | null {
  const trades = readTrades()
  const index = trades.findIndex((trade) => trade.id === id)
  if (index < 0) return null

  trades[index] = {
    ...trades[index]!,
    local_sync_status: status,
    risk_validation_status: riskValidationStatus ?? trades[index]!.risk_validation_status ?? 'unverified',
    updated_at: nowIso(),
  }

  const recomputed = recomputeTradesAndBalances(ensureAccounts(), trades)
  writeJson(ACCOUNTS_KEY, recomputed.accounts)
  writeJson(TRADES_KEY, recomputed.trades)
  return recomputed.trades.find((trade) => trade.id === id) ?? null
}

export function fetchLocalTradeDetails(id: number): { trade: Trade; images: TradeImage[] } {
  const trade = readTrades().find((item) => item.id === id)
  if (!trade) throw new Error('Trade not found.')

  const images = (trade.images ?? [])
    .slice()
    .sort((left, right) => left.sort_order - right.sort_order || left.id - right.id)

  return { trade, images }
}

export async function uploadLocalTradeImage(tradeId: number, file: File, sortOrder?: number): Promise<TradeImage> {
  const trades = readTrades()
  const index = trades.findIndex((trade) => trade.id === tradeId)
  if (index < 0) throw new Error('Trade not found.')

  const trade = trades[index]!
  const images = (trade.images ?? []).slice()
  const id = Math.max(0, ...trades.flatMap((item) => (item.images ?? []).map((image) => image.id))) + 1
  const localObjectUrlKey = makeLocalObjectUrlKey('trade-image')
  const transientUrl = registerTransientObjectUrl(localObjectUrlKey, file)
  const image: TradeImage = {
    id,
    image_url: '',
    thumbnail_url: '',
    file_size: file.size,
    file_type: file.type || 'image/jpeg',
    sort_order: typeof sortOrder === 'number' ? sortOrder : images.length,
    filename: file.name || `trade-image-${id}`,
    created_at: nowIso(),
    local_object_url_key: localObjectUrlKey,
  }

  images.push(image)
  images.sort((left, right) => left.sort_order - right.sort_order || left.id - right.id)

  trades[index] = {
    ...trade,
    images,
    images_count: images.length,
    updated_at: nowIso(),
  }
  writeJson(TRADES_KEY, trades)
  return {
    ...image,
    image_url: transientUrl,
    thumbnail_url: transientUrl,
  }
}

export function deleteLocalTradeImage(imageId: number): void {
  const trades = readTrades()
  let found = false

  for (let index = 0; index < trades.length; index += 1) {
    const trade = trades[index]!
    const images = trade.images ?? []
    const target = images.find((image) => image.id === imageId)
    if (target?.local_object_url_key) {
      revokeTransientObjectUrl(target.local_object_url_key)
    }
    const filtered = images.filter((image) => image.id !== imageId)
    if (filtered.length === images.length) continue

    trades[index] = {
      ...trade,
      images: filtered,
      images_count: filtered.length,
      updated_at: nowIso(),
    }
    found = true
    break
  }

  if (!found) throw new Error('Image not found.')
  writeJson(TRADES_KEY, trades)
}

export function queryLocalMissedTrades(params: {
  page: number
  per_page: number
  pair?: string
  model?: string
  reason?: string
  date_from?: string
  date_to?: string
}): Paginated<MissedTrade> {
  const page = Math.max(1, params.page || 1)
  const perPage = Math.max(1, Math.min(100, params.per_page || 15))
  const trades = readMissedTrades()
    .filter((trade) => {
      if (params.pair && !trade.pair.toLowerCase().includes(params.pair.toLowerCase().trim())) return false
      if (params.model && !trade.model.toLowerCase().includes(params.model.toLowerCase().trim())) return false
      if (params.reason && !trade.reason.toLowerCase().includes(params.reason.toLowerCase().trim())) return false
      const day = isoDate(trade.date)
      if (params.date_from && day < params.date_from) return false
      if (params.date_to && day > params.date_to) return false
      return true
    })
    .sort((left, right) => right.date.localeCompare(left.date) || right.id - left.id)

  const total = trades.length
  const lastPage = Math.max(1, Math.ceil(total / perPage))
  const currentPage = Math.min(page, lastPage)
  const start = (currentPage - 1) * perPage

  return {
    current_page: currentPage,
    data: trades.slice(start, start + perPage),
    last_page: lastPage,
    per_page: perPage,
    total,
  }
}

export function createLocalMissedTrade(payload: MissedTradePayloadLike): MissedTrade {
  const trades = readMissedTrades()
  const now = nowIso()
  const created: MissedTrade = {
    id: trades.reduce((max, trade) => Math.max(max, trade.id), 0) + 1,
    pair: payload.pair.trim().toUpperCase(),
    model: payload.model.trim(),
    reason: payload.reason.trim(),
    date: toIso(payload.date),
    notes: payload.notes,
    images: [],
    images_count: 0,
    created_at: now,
    updated_at: now,
  }

  writeJson(MISSED_TRADES_KEY, [created, ...trades])
  return created
}

export function updateLocalMissedTrade(id: number, payload: Partial<MissedTradePayloadLike>): MissedTrade {
  const trades = readMissedTrades()
  const index = trades.findIndex((trade) => trade.id === id)
  if (index < 0) throw new Error('Missed trade not found.')

  const current = trades[index]!
  const updated: MissedTrade = {
    ...current,
    pair: payload.pair !== undefined ? payload.pair.trim().toUpperCase() : current.pair,
    model: payload.model !== undefined ? payload.model.trim() : current.model,
    reason: payload.reason !== undefined ? payload.reason.trim() : current.reason,
    date: payload.date !== undefined ? toIso(payload.date) : current.date,
    notes: payload.notes !== undefined ? payload.notes : current.notes,
    updated_at: nowIso(),
  }

  trades[index] = updated
  writeJson(MISSED_TRADES_KEY, trades)
  return updated
}

export function deleteLocalMissedTrade(id: number): void {
  writeJson(MISSED_TRADES_KEY, readMissedTrades().filter((trade) => trade.id !== id))
}

export function fetchLocalMissedTrade(id: number): MissedTrade {
  const trade = readMissedTrades().find((item) => item.id === id)
  if (!trade) throw new Error('Missed trade not found.')
  return trade
}

export async function uploadLocalMissedTradeImage(missedTradeId: number, file: File, sortOrder?: number): Promise<MissedTradeImage> {
  const trades = readMissedTrades()
  const index = trades.findIndex((trade) => trade.id === missedTradeId)
  if (index < 0) throw new Error('Missed trade not found.')

  const trade = trades[index]!
  const images = (trade.images ?? []).slice()
  const id = Math.max(0, ...trades.flatMap((item) => (item.images ?? []).map((image) => image.id))) + 1
  const localObjectUrlKey = makeLocalObjectUrlKey('missed-image')
  const transientUrl = registerTransientObjectUrl(localObjectUrlKey, file)
  const image: MissedTradeImage = {
    id,
    image_url: '',
    thumbnail_url: '',
    file_size: file.size,
    file_type: file.type || 'image/jpeg',
    sort_order: typeof sortOrder === 'number' ? sortOrder : images.length,
    filename: file.name || `missed-image-${id}`,
    created_at: nowIso(),
    local_object_url_key: localObjectUrlKey,
  }

  images.push(image)
  images.sort((left, right) => left.sort_order - right.sort_order || left.id - right.id)

  trades[index] = {
    ...trade,
    images,
    images_count: images.length,
    updated_at: nowIso(),
  }

  writeJson(MISSED_TRADES_KEY, trades)
  return {
    ...image,
    image_url: transientUrl,
    thumbnail_url: transientUrl,
  }
}

export function deleteLocalMissedTradeImage(imageId: number): void {
  const trades = readMissedTrades()
  let found = false

  for (let index = 0; index < trades.length; index += 1) {
    const trade = trades[index]!
    const images = trade.images ?? []
    const target = images.find((image) => image.id === imageId)
    if (target?.local_object_url_key) {
      revokeTransientObjectUrl(target.local_object_url_key)
    }
    const filtered = images.filter((image) => image.id !== imageId)
    if (filtered.length === images.length) continue

    trades[index] = {
      ...trade,
      images: filtered,
      images_count: filtered.length,
      updated_at: nowIso(),
    }
    found = true
    break
  }

  if (!found) throw new Error('Image not found.')
  writeJson(MISSED_TRADES_KEY, trades)
}

function ensureAccounts(): Account[] {
  const existing = readAccounts()
  if (existing.length > 0) return existing

  const seed: Account = {
    id: 1,
    user_id: null,
    name: 'Primary Account',
    broker: 'Local',
    account_type: 'personal',
    starting_balance: '10000.00',
    current_balance: '10000.00',
    currency: 'USD',
    is_active: true,
    created_at: nowIso(),
    updated_at: nowIso(),
  }
  writeJson(ACCOUNTS_KEY, [seed])
  return [seed]
}

function readAccounts(): Account[] {
  const raw = readJson<unknown>(ACCOUNTS_KEY, [])
  if (!Array.isArray(raw)) return []
  return raw
    .map((item) => normalizeAccount(item))
    .filter((item): item is Account => item !== null)
}

function readTrades(): Trade[] {
  const raw = readJson<unknown>(TRADES_KEY, [])
  if (!Array.isArray(raw)) return []
  return raw
    .map((item) => normalizeTrade(item))
    .filter((item): item is Trade => item !== null)
}

function readMissedTrades(): MissedTrade[] {
  const raw = readJson<unknown>(MISSED_TRADES_KEY, [])
  if (!Array.isArray(raw)) return []
  return raw
    .map((item) => normalizeMissedTrade(item))
    .filter((item): item is MissedTrade => item !== null)
}

function recomputeTradesAndBalances(accountsSource: Account[], tradesSource: Trade[]): { accounts: Account[]; trades: Trade[] } {
  const accounts = accountsSource.map((account) => ({ ...account }))
  const trades = tradesSource.map((trade) => ({ ...trade, images: (trade.images ?? []).map((image) => ({ ...image })) }))
  const accountMap = new Map<number, Account>()

  for (const account of accounts) {
    account.current_balance = account.starting_balance
    accountMap.set(account.id, account)
  }

  const firstAccountId = accounts[0]?.id ?? 1
  for (const trade of trades) {
    if (!accountMap.has(trade.account_id)) {
      trade.account_id = firstAccountId
    }
  }

  for (const account of accounts) {
    const rows = trades
      .filter((trade) => trade.account_id === account.id)
      .sort((left, right) => left.date.localeCompare(right.date) || left.id - right.id)

    let balance = toNumber(account.starting_balance)
    for (const trade of rows) {
      const calculated = calculateTrade(trade, balance)
      Object.assign(trade, calculated.trade)
      balance = calculated.after
    }

    account.current_balance = asMoney(balance)
    account.updated_at = nowIso()
  }

  return { accounts, trades }
}

function calculateTrade(trade: Trade, balanceBefore: number): { trade: Trade; after: number } {
  const entry = toNumber(trade.entry_price)
  const stop = toNumber(trade.stop_loss)
  const take = toNumber(trade.take_profit)
  const exit = toNumber(trade.actual_exit_price)
  const lot = Math.max(0, toNumber(trade.lot_size))
  const direction = trade.direction === 'sell' ? 'sell' : 'buy'

  const riskPerUnit = Math.abs(entry - stop)
  const rewardPerUnit = Math.abs(take - entry)
  const monetaryRisk = riskPerUnit * lot
  const monetaryReward = rewardPerUnit * lot
  const profitLoss = direction === 'buy'
    ? (exit - entry) * lot
    : (entry - exit) * lot
  const rr = monetaryRisk > 0 ? monetaryReward / monetaryRisk : 0
  const rMultiple = monetaryRisk > 0 ? profitLoss / monetaryRisk : 0
  const riskPercent = balanceBefore > 0 ? (monetaryRisk / balanceBefore) * 100 : 0
  const after = round(balanceBefore + profitLoss, 2)
  const images = (trade.images ?? []).slice()

  return {
    trade: {
      ...trade,
      entry_price: asPrice(entry),
      stop_loss: asPrice(stop),
      take_profit: asPrice(take),
      actual_exit_price: asPrice(exit),
      lot_size: asLot(lot),
      risk_per_unit: asPrice(riskPerUnit),
      reward_per_unit: asPrice(rewardPerUnit),
      monetary_risk: asPrice(monetaryRisk),
      monetary_reward: asPrice(monetaryReward),
      profit_loss: asMoney(profitLoss),
      rr: asRatio(rr, 2),
      r_multiple: asRatio(rMultiple, 4),
      risk_percent: asRatio(riskPercent, 4),
      account_balance_before_trade: asMoney(balanceBefore),
      account_balance_after_trade: asMoney(after),
      followed_rules: Boolean(trade.followed_rules),
      emotion: normalizeEmotion(trade.emotion),
      session: trade.session?.trim() || 'N/A',
      model: trade.model?.trim() || 'General',
      date: toIso(trade.date),
      images,
      images_count: images.length,
      updated_at: nowIso(),
    },
    after,
  }
}

function summarizeTrades(trades: Trade[]) {
  let wins = 0
  let losses = 0
  let totalProfit = 0
  let totalLoss = 0
  let totalR = 0

  for (const trade of trades) {
    const pnl = toNumber(trade.profit_loss)
    totalR += toNumber(trade.r_multiple)
    if (pnl > 0) {
      wins += 1
      totalProfit += pnl
    } else if (pnl < 0) {
      losses += 1
      totalLoss += Math.abs(pnl)
    }
  }

  const total = trades.length
  const net = round(totalProfit - totalLoss, 2)
  return {
    total,
    net,
    winRate: total > 0 ? round((wins / total) * 100, 2) : 0,
    expectancy: total > 0 ? round(net / total, 2) : 0,
    avgR: total > 0 ? round(totalR / total, 3) : 0,
    profitFactor: totalLoss > 0 ? round(totalProfit / totalLoss, 2) : null,
  }
}

function computeDrawdown(points: number[], starting: number) {
  let peak = starting
  let maxDrawdown = 0

  for (const point of points) {
    peak = Math.max(peak, point)
    maxDrawdown = Math.max(maxDrawdown, peak - point)
  }

  const current = points.length > 0 ? points[points.length - 1]! : starting
  const currentDrawdown = Math.max(0, peak - current)

  return {
    max_drawdown: round(maxDrawdown, 2),
    max_drawdown_percent: peak > 0 ? round((maxDrawdown / peak) * 100, 2) : 0,
    current_drawdown: round(currentDrawdown, 2),
    current_drawdown_percent: peak > 0 ? round((currentDrawdown / peak) * 100, 2) : 0,
    peak_balance: round(peak, 2),
    current_equity: round(current, 2),
  }
}

function normalizeAccount(input: unknown): Account | null {
  if (!isRecord(input)) return null
  const id = toInt(input.id)
  if (id <= 0) return null

  return {
    id,
    user_id: null,
    name: String(input.name ?? 'Account'),
    broker: String(input.broker ?? 'Local'),
    account_type: normalizeAccountType(input.account_type),
    starting_balance: asMoney(input.starting_balance),
    current_balance: asMoney(input.current_balance ?? input.starting_balance),
    currency: String(input.currency ?? 'USD').toUpperCase(),
    is_active: Boolean(input.is_active ?? true),
    local_sync_status: normalizeLocalSyncStatus(input.local_sync_status),
    created_at: String(input.created_at ?? nowIso()),
    updated_at: String(input.updated_at ?? nowIso()),
  }
}

function normalizeTrade(input: unknown): Trade | null {
  if (!isRecord(input)) return null
  const id = toInt(input.id)
  if (id <= 0) return null
  const images = Array.isArray(input.images)
    ? input.images.map((image) => normalizeTradeImage(image)).filter((image): image is TradeImage => image !== null)
    : []

  return {
    id,
    account_id: Math.max(1, toInt(input.account_id)),
    pair: String(input.pair ?? '').toUpperCase(),
    direction: input.direction === 'sell' ? 'sell' : 'buy',
    entry_price: asPrice(input.entry_price),
    stop_loss: asPrice(input.stop_loss),
    take_profit: asPrice(input.take_profit),
    actual_exit_price: asPrice(input.actual_exit_price),
    lot_size: asLot(input.lot_size),
    risk_per_unit: asPrice(input.risk_per_unit),
    reward_per_unit: asPrice(input.reward_per_unit),
    monetary_risk: asPrice(input.monetary_risk),
    monetary_reward: asPrice(input.monetary_reward),
    profit_loss: asMoney(input.profit_loss),
    rr: asRatio(input.rr, 2),
    r_multiple: asRatio(input.r_multiple, 4),
    risk_percent: asRatio(input.risk_percent, 4),
    account_balance_before_trade: asMoney(input.account_balance_before_trade),
    account_balance_after_trade: asMoney(input.account_balance_after_trade),
    followed_rules: Boolean(input.followed_rules ?? true),
    emotion: normalizeEmotion(input.emotion),
    session: String(input.session ?? 'N/A'),
    model: String(input.model ?? 'General'),
    date: toIso(String(input.date ?? nowIso())),
    notes: input.notes === null || input.notes === undefined ? null : String(input.notes),
    local_sync_status: normalizeLocalSyncStatus(input.local_sync_status),
    risk_validation_status: normalizeRiskValidationStatus(input.risk_validation_status),
    images,
    images_count: toInt(input.images_count ?? images.length),
    created_at: String(input.created_at ?? nowIso()),
    updated_at: String(input.updated_at ?? nowIso()),
    deleted_at: input.deleted_at ? String(input.deleted_at) : null,
  }
}

function normalizeTradeImage(input: unknown): TradeImage | null {
  if (!isRecord(input)) return null
  const id = toInt(input.id)
  if (id <= 0) return null
  const localObjectUrlKey = toOptionalText(input.local_object_url_key)
  const transientUrl = localObjectUrlKey ? resolveTransientObjectUrl(localObjectUrlKey) : ''
  const persistedImageUrl = sanitizePersistedImageUrl(input.image_url)
  const persistedThumbUrl = sanitizePersistedImageUrl(input.thumbnail_url)
  const imageUrl = persistedImageUrl || transientUrl
  const thumbnailUrl = persistedThumbUrl || imageUrl

  return {
    id,
    image_url: imageUrl,
    thumbnail_url: thumbnailUrl,
    file_size: toInt(input.file_size),
    file_type: String(input.file_type ?? 'image/jpeg'),
    sort_order: toInt(input.sort_order),
    filename: toOptionalText(input.filename),
    created_at: toOptionalText(input.created_at),
    local_object_url_key: localObjectUrlKey,
  }
}

function normalizeMissedTrade(input: unknown): MissedTrade | null {
  if (!isRecord(input)) return null
  const id = toInt(input.id)
  if (id <= 0) return null
  const images = Array.isArray(input.images)
    ? input.images.map((image) => normalizeMissedTradeImage(image)).filter((image): image is MissedTradeImage => image !== null)
    : []

  return {
    id,
    pair: String(input.pair ?? '').toUpperCase(),
    model: String(input.model ?? ''),
    reason: String(input.reason ?? ''),
    date: toIso(String(input.date ?? nowIso())),
    notes: input.notes === null || input.notes === undefined ? null : String(input.notes),
    images,
    images_count: toInt(input.images_count ?? images.length),
    created_at: String(input.created_at ?? nowIso()),
    updated_at: String(input.updated_at ?? nowIso()),
  }
}

function normalizeMissedTradeImage(input: unknown): MissedTradeImage | null {
  if (!isRecord(input)) return null
  const id = toInt(input.id)
  if (id <= 0) return null
  const localObjectUrlKey = toOptionalText(input.local_object_url_key)
  const transientUrl = localObjectUrlKey ? resolveTransientObjectUrl(localObjectUrlKey) : ''
  const persistedImageUrl = sanitizePersistedImageUrl(input.image_url)
  const persistedThumbUrl = sanitizePersistedImageUrl(input.thumbnail_url)
  const imageUrl = persistedImageUrl || transientUrl
  const thumbnailUrl = persistedThumbUrl || imageUrl

  return {
    id,
    image_url: imageUrl,
    thumbnail_url: thumbnailUrl,
    file_size: toInt(input.file_size),
    file_type: String(input.file_type ?? 'image/jpeg'),
    sort_order: toInt(input.sort_order),
    filename: toOptionalText(input.filename),
    created_at: toOptionalText(input.created_at),
    local_object_url_key: localObjectUrlKey,
  }
}

function normalizeEmotion(value: unknown): TradeEmotion {
  const normalized = String(value ?? 'neutral').toLowerCase()
  const allowed: TradeEmotion[] = ['neutral', 'calm', 'confident', 'fearful', 'greedy', 'hesitant', 'revenge']
  return allowed.includes(normalized as TradeEmotion) ? (normalized as TradeEmotion) : 'neutral'
}

function normalizeAccountType(value: unknown): AccountType {
  const normalized = String(value ?? 'personal').toLowerCase()
  if (normalized === 'funded' || normalized === 'demo' || normalized === 'personal') {
    return normalized
  }
  return 'personal'
}

function normalizeLocalSyncStatus(value: unknown): SyncQueueStatus | undefined {
  const normalized = String(value ?? '').toLowerCase()
  if (
    normalized === 'draft_local'
    || normalized === 'pending_sync'
    || normalized === 'synced'
    || normalized === 'conflict'
  ) {
    return normalized
  }
  return undefined
}

function normalizeRiskValidationStatus(value: unknown): 'verified' | 'unverified' | undefined {
  const normalized = String(value ?? '').toLowerCase()
  if (normalized === 'verified' || normalized === 'unverified') {
    return normalized
  }
  return undefined
}

function makeLocalObjectUrlKey(prefix: string): string {
  const random = Math.random().toString(16).slice(2, 10)
  return `${prefix}-${Date.now()}-${random}`
}

function registerTransientObjectUrl(key: string, file: File): string {
  const creator = globalThis.URL?.createObjectURL
  if (typeof creator !== 'function') {
    return ''
  }

  try {
    const url = creator(file)
    transientObjectUrlByKey.set(key, url)
    return url
  } catch {
    return ''
  }
}

function resolveTransientObjectUrl(key: string): string {
  return transientObjectUrlByKey.get(key) ?? ''
}

function revokeTransientObjectUrl(key: string): void {
  const url = transientObjectUrlByKey.get(key)
  if (!url) return
  try {
    globalThis.URL?.revokeObjectURL?.(url)
  } catch {
    // Ignore object URL revoke failures.
  } finally {
    transientObjectUrlByKey.delete(key)
  }
}

function sanitizePersistedImageUrl(value: unknown): string {
  if (typeof value !== 'string') return ''
  const trimmed = value.trim()
  if (trimmed === '') return ''
  if (trimmed.startsWith('data:')) return ''
  if (trimmed.startsWith('blob:')) return ''
  return trimmed
}

function toOptionalText(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

interface LocalStorageEnvelope<T> {
  created_at: string
  updated_at: string
  expire_at: string | null
  data: T
}

function readJson<T>(key: string, fallback: T): T {
  migrateLegacyLocalFallbackKeys()
  const scope = getScope()
  const targetKey = scopedLocalFallbackKeyForScope(scope, key)

  if (!hydratedScopeMarkers.has(scopeMarker(scope))) {
    void hydrateScope(scope)
  }

  const cached = readEnvelopeFromCache<T>(targetKey, key)
  if (cached) {
    return cached.data
  }

  const migrated = readAndMigrateLegacyScopedEnvelope<T>(scope, key, targetKey)
  if (migrated) {
    return migrated.data
  }

  return fallback
}

function writeJson<T>(key: string, value: T): void {
  migrateLegacyLocalFallbackKeys()
  const scope = getScope()
  const targetKey = scopedLocalFallbackKeyForScope(scope, key)
  const now = nowIso()
  const existing = readEnvelopeFromCache<T>(targetKey, key)
  const normalized = sanitizePersistedPayloadValue(key, applyEntityCaps(key, value))

  const envelope: LocalStorageEnvelope<T> = {
    created_at: existing?.created_at ?? now,
    updated_at: now,
    expire_at: ttlExpiryIso(),
    data: normalized,
  }

  scopedEnvelopeCache.set(targetKey, envelope)
  if (shouldPersistKey(key)) {
    enqueuePersistence(targetKey, async () => {
      await persistEnvelope(scope, key, envelope)
    })
  } else {
    enqueuePersistence(targetKey, async () => {
      await deleteScopedRecord(scope, LOCAL_FALLBACK_NAMESPACE, key)
    })
  }

  purgeScopedLocalStorageKey(targetKey)
}

function scopedLocalFallbackKeyForScope(scope: StorageScope, key: string): string {
  return scopedKeyForScope(scope, LOCAL_FALLBACK_NAMESPACE, key)
}

function isEnvelope<T>(value: unknown): value is LocalStorageEnvelope<T> {
  if (!isRecord(value)) return false
  return 'created_at' in value && 'expire_at' in value && 'data' in value
}

function sanitizePersistedImagePayloads(key: string, payload: unknown): { payload: unknown; changed: boolean } {
  if (key !== TRADES_KEY && key !== MISSED_TRADES_KEY) {
    return { payload, changed: false }
  }

  const root = isEnvelope<unknown>(payload) ? payload.data : payload
  if (!Array.isArray(root)) {
    return { payload, changed: false }
  }

  let changed = false
  const nextRoot = root.map((row) => {
    if (!isRecord(row)) return row
    const images = Array.isArray(row.images) ? row.images : null
    if (!images) return row

    const nextImages = images.map((image) => {
      if (!isRecord(image)) return image
      const next = { ...image }

      if (typeof next.image_url === 'string' && (next.image_url.startsWith('data:') || next.image_url.startsWith('blob:'))) {
        next.image_url = ''
        changed = true
      }
      if (typeof next.thumbnail_url === 'string' && (next.thumbnail_url.startsWith('data:') || next.thumbnail_url.startsWith('blob:'))) {
        next.thumbnail_url = ''
        changed = true
      }

      return next
    })

    return {
      ...row,
      images: nextImages,
    }
  })

  if (!changed) {
    return { payload, changed: false }
  }

  if (isEnvelope<unknown>(payload)) {
    return {
      payload: {
        ...payload,
        data: nextRoot,
      },
      changed: true,
    }
  }

  return { payload: nextRoot, changed: true }
}

function sanitizePersistedPayloadValue<T>(key: string, payload: T): T {
  const sanitized = sanitizePersistedImagePayloads(key, payload)
  return sanitized.payload as T
}

function applyEntityCaps<T>(key: string, value: T): T {
  if (key !== ACCOUNTS_KEY && key !== TRADES_KEY) {
    return value
  }
  if (!Array.isArray(value)) {
    return value
  }

  const cap = key === TRADES_KEY ? MAX_TRADES : MAX_ACCOUNTS
  if (value.length <= cap) {
    return value
  }

  const rows = value.slice()
  rows.sort((left, right) => {
    const recencyDelta = recordRecency(right) - recordRecency(left)
    if (recencyDelta !== 0) return recencyDelta
    return recordId(right) - recordId(left)
  })
  return rows.slice(0, cap) as T
}

function recordRecency(value: unknown): number {
  if (!isRecord(value)) return 0
  const updatedAt = typeof value.updated_at === 'string' ? Date.parse(value.updated_at) : Number.NaN
  if (Number.isFinite(updatedAt)) return updatedAt
  const createdAt = typeof value.created_at === 'string' ? Date.parse(value.created_at) : Number.NaN
  return Number.isFinite(createdAt) ? createdAt : 0
}

function recordId(value: unknown): number {
  if (!isRecord(value)) return 0
  const id = Number(value.id)
  if (!Number.isFinite(id)) return 0
  return Math.trunc(id)
}

function readEnvelopeFromCache<T>(targetKey: string, key: string): LocalStorageEnvelope<T> | null {
  const cached = scopedEnvelopeCache.get(targetKey)
  if (!cached) return null

  if (isExpiredEnvelope(cached)) {
    scopedEnvelopeCache.delete(targetKey)
    const scope = getScope()
    enqueuePersistence(targetKey, async () => {
      await deleteScopedRecord(scope, LOCAL_FALLBACK_NAMESPACE, key)
    })
    return null
  }

  return cached as LocalStorageEnvelope<T>
}

function readAndMigrateLegacyScopedEnvelope<T>(
  scope: StorageScope,
  key: string,
  targetKey: string
): LocalStorageEnvelope<T> | null {
  const storage = safeLocalStorage()
  if (!storage) return null

  const raw = safeGet(storage, targetKey)
  if (!raw) return null

  let parsed: unknown = null
  try {
    parsed = JSON.parse(raw) as unknown
  } catch {
    safeRemove(storage, targetKey)
    return null
  }

  const envelope = normalizeEnvelope<T>(key, parsed)
  safeRemove(storage, targetKey)
  if (!envelope || isExpiredEnvelope(envelope)) {
    return null
  }

  scopedEnvelopeCache.set(targetKey, envelope)
  if (shouldPersistKey(key)) {
    enqueuePersistence(targetKey, async () => {
      await persistEnvelope(scope, key, envelope)
    })
  }
  return envelope
}

async function hydrateScope(scope: StorageScope): Promise<void> {
  const marker = scopeMarker(scope)
  if (hydratedScopeMarkers.has(marker)) {
    return
  }

  const existing = scopeHydrationPromises.get(marker)
  if (existing) {
    await existing
    return
  }

  const job = (async () => {
    await hydrateKey(scope, ACCOUNTS_KEY)
    await hydrateKey(scope, TRADES_KEY)
    await hydrateKey(scope, MISSED_TRADES_KEY)
    hydratedScopeMarkers.add(marker)
  })().finally(() => {
    scopeHydrationPromises.delete(marker)
  })

  scopeHydrationPromises.set(marker, job)
  await job
}

async function hydrateKey(scope: StorageScope, key: string): Promise<void> {
  const targetKey = scopedLocalFallbackKeyForScope(scope, key)
  let envelopeFromIdb: LocalStorageEnvelope<unknown> | null = null
  const persisted = await getScopedRecord<unknown>(scope, LOCAL_FALLBACK_NAMESPACE, key)
  if (persisted) {
    envelopeFromIdb = normalizeEnvelope(key, persistedToEnvelope(persisted))
    if (envelopeFromIdb && isExpiredEnvelope(envelopeFromIdb)) {
      envelopeFromIdb = null
      await deleteScopedRecord(scope, LOCAL_FALLBACK_NAMESPACE, key)
    }
  }

  const envelopeFromLocal = readAndMigrateLegacyScopedEnvelope(scope, key, targetKey)
  const selected = newestEnvelope(envelopeFromIdb, envelopeFromLocal)

  if (!selected) {
    scopedEnvelopeCache.delete(targetKey)
    return
  }

  scopedEnvelopeCache.set(targetKey, selected)
  if (shouldPersistKey(key)) {
    await persistEnvelope(scope, key, selected)
  } else if (isSensitiveKey(key)) {
    await deleteScopedRecord(scope, LOCAL_FALLBACK_NAMESPACE, key)
  }
}

function newestEnvelope<T>(
  left: LocalStorageEnvelope<T> | null,
  right: LocalStorageEnvelope<T> | null
): LocalStorageEnvelope<T> | null {
  if (!left) return right
  if (!right) return left
  return envelopeUpdatedAtMs(right) > envelopeUpdatedAtMs(left) ? right : left
}

function envelopeUpdatedAtMs(value: LocalStorageEnvelope<unknown>): number {
  const parsed = Date.parse(value.updated_at)
  if (Number.isFinite(parsed)) return parsed
  const createdParsed = Date.parse(value.created_at)
  return Number.isFinite(createdParsed) ? createdParsed : 0
}

async function persistEnvelope(
  scope: StorageScope,
  key: string,
  envelope: LocalStorageEnvelope<unknown>
): Promise<void> {
  const record: ScopedIndexedDbRecord<unknown> = {
    id: scopedRecordId(scope, LOCAL_FALLBACK_NAMESPACE, key),
    namespace: LOCAL_FALLBACK_NAMESPACE,
    key,
    user_id: scope.userId,
    account_id: scope.accountId,
    created_at: envelope.created_at,
    updated_at: envelope.updated_at,
    expire_at: envelope.expire_at,
    lru_at: envelope.updated_at,
    payload: envelope.data,
  }
  await putScopedRecord(record)
}

function enqueuePersistence(scopedKeyValue: string, writer: () => Promise<void>): void {
  const previous = persistenceQueuesByScopedKey.get(scopedKeyValue) ?? Promise.resolve()
  const next = previous
    .then(async () => {
      await writer()
    })
    .catch(() => {
      // Ignore queue write errors to avoid breaking local fallback runtime.
    })

  persistenceQueuesByScopedKey.set(scopedKeyValue, next)
  void next.finally(() => {
    if (persistenceQueuesByScopedKey.get(scopedKeyValue) === next) {
      persistenceQueuesByScopedKey.delete(scopedKeyValue)
    }
  })
}

function normalizeEnvelope<T>(key: string, raw: unknown): LocalStorageEnvelope<T> | null {
  const now = nowIso()
  let parsed: unknown = raw
  const sanitized = sanitizePersistedImagePayloads(key, parsed)
  if (sanitized.changed) {
    parsed = sanitized.payload
  }

  if (isEnvelope<T>(parsed)) {
    const normalized: LocalStorageEnvelope<T> = {
      created_at: typeof parsed.created_at === 'string' ? parsed.created_at : now,
      updated_at: typeof parsed.updated_at === 'string' ? parsed.updated_at : (typeof parsed.created_at === 'string' ? parsed.created_at : now),
      expire_at: normalizeExpireAt(parsed.expire_at),
      data: applyEntityCaps(key, parsed.data),
    }
    normalized.data = sanitizePersistedPayloadValue(key, normalized.data)
    return normalized
  }

  return {
    created_at: now,
    updated_at: now,
    expire_at: ttlExpiryIso(),
    data: sanitizePersistedPayloadValue(key, applyEntityCaps(key, parsed as T)),
  }
}

function persistedToEnvelope(record: ScopedIndexedDbRecord<unknown>): LocalStorageEnvelope<unknown> {
  return {
    created_at: record.created_at,
    updated_at: record.updated_at,
    expire_at: record.expire_at,
    data: record.payload,
  }
}

function normalizeExpireAt(value: unknown): string | null {
  if (typeof value !== 'string') return ttlExpiryIso()
  const trimmed = value.trim()
  if (!trimmed) return ttlExpiryIso()
  return trimmed
}

function ttlExpiryIso(ttlMs = DEFAULT_TTL_MS): string {
  return new Date(Date.now() + ttlMs).toISOString()
}

function isExpiredEnvelope(value: LocalStorageEnvelope<unknown>): boolean {
  if (!value.expire_at) return false
  const expiresAt = Date.parse(value.expire_at)
  if (!Number.isFinite(expiresAt)) return false
  return expiresAt <= Date.now()
}

function shouldPersistKey(key: string): boolean {
  if (!isSensitiveKey(key)) {
    return true
  }
  return isOfflineModeEnabled()
}

function isSensitiveKey(key: string): boolean {
  return SENSITIVE_KEYS.has(key)
}

function scopeMarker(scope: StorageScope): string {
  return `${scope.userId ?? 'anon'}:${scope.accountId ?? 'all'}`
}

function purgeScopedLocalStorageKey(targetKey: string): void {
  const storage = safeLocalStorage()
  if (!storage) return
  safeRemove(storage, targetKey)
}

function purgeLegacySensitiveScopedKeys(): void {
  const storage = safeLocalStorage()
  if (!storage) return

  const keys = readStorageKeys(storage)
  for (const key of keys) {
    if (key === LEGACY_ACCOUNTS_KEY || key === LEGACY_TRADES_KEY) {
      safeRemove(storage, key)
      continue
    }

    if (
      key.includes(`:${LOCAL_FALLBACK_NAMESPACE}:${ACCOUNTS_KEY}`)
      || key.includes(`:${LOCAL_FALLBACK_NAMESPACE}:${TRADES_KEY}`)
    ) {
      safeRemove(storage, key)
    }
  }
}

function clearSensitiveUserCache(userId: number): void {
  for (const [key] of scopedEnvelopeCache) {
    const userScopeTag = `:u:${userId}:`
    if (!key.includes(userScopeTag)) continue
    if (!key.endsWith(`:${ACCOUNTS_KEY}`) && !key.endsWith(`:${TRADES_KEY}`)) continue
    scopedEnvelopeCache.delete(key)
  }
}

function clearSensitiveScopeCache(scope: StorageScope): void {
  const accountScoped = scopedLocalFallbackKeyForScope(scope, ACCOUNTS_KEY)
  const tradeScoped = scopedLocalFallbackKeyForScope(scope, TRADES_KEY)
  scopedEnvelopeCache.delete(accountScoped)
  scopedEnvelopeCache.delete(tradeScoped)
}

function normalizeScopeUserId(userId: number | null | undefined): number | null {
  if (typeof userId !== 'number') return null
  if (!Number.isInteger(userId) || userId <= 0) return null
  return userId
}

function nowIso(): string {
  return new Date().toISOString()
}

function isoDate(value: string): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return nowIso().slice(0, 10)
  return parsed.toISOString().slice(0, 10)
}

function toIso(value: string): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return nowIso()
  return parsed.toISOString()
}

function toInt(value: unknown): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? Math.trunc(parsed) : 0
}

function toNumber(value: unknown): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

function asPrice(value: unknown): string {
  return round(toNumber(value), 6).toFixed(6)
}

function asLot(value: unknown): string {
  return round(toNumber(value), 4).toFixed(4)
}

function asMoney(value: unknown): string {
  return round(toNumber(value), 2).toFixed(2)
}

function asRatio(value: unknown, digits: number): string {
  return round(toNumber(value), digits).toFixed(digits)
}

function round(value: number, digits: number): number {
  const factor = 10 ** digits
  return Math.round((value + Number.EPSILON) * factor) / factor
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function safeLocalStorage(): Storage | null {
  try {
    if (typeof localStorage === 'undefined') return null
    return localStorage
  } catch {
    return null
  }
}

function readStorageKeys(storage: Storage): string[] {
  const keys: string[] = []
  for (let index = 0; index < storage.length; index += 1) {
    const key = storage.key(index)
    if (!key) continue
    keys.push(key)
  }
  return keys
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
