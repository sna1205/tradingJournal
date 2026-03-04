import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { type AxiosResponseHeaders, type RawAxiosResponseHeaders } from 'axios'
import api from '@/services/api'
import {
  enqueueSyncCreate,
  enqueueSyncDelete,
  enqueueSyncUpdate,
} from '@/services/offlineSyncQueue'
import {
  createLocalTrade,
  deleteLocalTrade,
  deleteLocalTradeImage,
  fetchLocalTradeDetails,
  queryLocalTrades,
  setLocalTradeSyncStatus,
  shouldUseLocalFallback,
  upsertLocalTradeSnapshot,
  updateLocalTrade,
  uploadLocalTradeImage,
} from '@/services/localFallback'
import { createRequestManager, isAbortError, stableSerialize } from '@/services/requestManager'
import { scopedKey } from '@/services/storageScope'
import { useAnalyticsStore } from '@/stores/analyticsStore'
import { useAccountStore } from '@/stores/accountStore'
import { useSyncStatusStore } from '@/stores/syncStatusStore'
import { normalizeApiError } from '@/utils/apiError'
import { createIdempotencyKey } from '@/utils/idempotency'
import type { TradeChecklistReadiness } from '@/types/rules'
import type {
  FxRate,
  Instrument,
  KillzoneItem,
  Paginated,
  SessionEnum,
  SessionOption,
  TaxonomyItem,
  Trade,
  TradeDetailsResponse,
  TradeEmotion,
  TradeLeg,
  TradePsychology,
  TradeTag,
  TradeImage,
} from '@/types/trade'

interface TradeFilters {
  pair: string
  direction: '' | 'buy' | 'sell'
  model: string
  strategy_model_id: string
  setup_id: string
  killzone_id: string
  session_enum: string
  tag_ids: string
  date_from: string
  date_to: string
}

export interface TradePayload {
  account_id: number
  instrument_id: number
  symbol: string
  direction: 'buy' | 'sell'
  entry_price: number
  stop_loss: number
  take_profit: number
  actual_exit_price: number
  position_size: number
  commission?: number
  swap?: number
  spread_cost?: number
  slippage_cost?: number
  fx_rate_quote_to_usd?: number | null
  fx_symbol_used?: string | null
  fx_rate_timestamp?: string | null
  legs?: TradeLegPayload[]
  strategy_model_id?: number | null
  setup_id?: number | null
  killzone_id?: number | null
  session_enum?: SessionEnum | null
  tag_ids?: number[]
  risk_override_reason?: string | null
  followed_rules: boolean
  checklist_responses?: Array<{
    checklist_item_id: number
    value: unknown
  }>
  checklist_evaluation?: {
    status: 'not_ready' | 'almost' | 'ready'
    ready: boolean
    completed_required: number
    total_required: number
  }
  precheck_snapshot?: TradePrecheckResult['calculated'] | null
  checklist_incomplete?: boolean
  emotion: TradeEmotion
  session?: string
  strategy_model?: string
  close_date: string
  notes: string | null
}

export interface TradeLegPayload {
  leg_type: 'entry' | 'exit'
  price: number
  quantity_lots: number
  executed_at: string
  fees?: number
  notes?: string | null
}

export interface TradePrecheckViolation {
  code: string
  message: string
  limit: number
  actual: number
}

export interface TradePrecheckResult {
  allowed: boolean
  risk_engine_unavailable?: boolean
  local_only_override_allowed?: boolean
  requires_override_reason: boolean
  policy: {
    account_id: number
    max_risk_per_trade_pct: number
    max_daily_loss_pct: number
    max_total_drawdown_pct: number
    max_open_risk_pct: number
    enforce_hard_limits: boolean
    allow_override: boolean
  }
  violations: TradePrecheckViolation[]
  stats: {
    risk_percent: number
    monetary_risk: number
    daily_realized_loss: number
    projected_daily_loss: number
    projected_daily_loss_pct: number
    projected_drawdown: number
    projected_drawdown_pct: number
  }
  calculated: {
    monetary_risk: number
    monetary_reward: number
    gross_profit_loss: number
    costs_total: number
    profit_loss: number
    risk_percent: number
    r_multiple: number
    realized_r_multiple: number
    avg_entry_price: number
    avg_exit_price: number
    rr: number
    fx_rate_quote_to_usd?: number | null
    fx_symbol_used?: string | null
    fx_rate_timestamp?: string | null
  }
  checklist_gate?: {
    readiness: TradeChecklistReadiness
    failing_rules: Array<{
      checklist_item_id: number
      title: string
      category: string
      reason?: string
    }>
    failed_required_rule_ids: number[]
    failed_rule_reasons: Array<{
      checklist_item_id: number
      title: string
      category: string
      reason?: string
    }>
    checklist_incomplete: boolean
  }
}

interface TradePrecheckOptions {
  signal?: AbortSignal
}

const PROHIBITED_TRADE_FX_FIELDS = [
  'fx_rate_quote_to_usd',
  'fx_symbol_used',
  'fx_rate_timestamp',
] as const

const TRADE_PREFS_NAMESPACE = 'trade-preferences'
const TRADE_PREFS_INCLUDE_DRAFTS_KEY = 'include_drafts_unverified'

const defaultFilters: TradeFilters = {
  pair: '',
  direction: '',
  model: '',
  strategy_model_id: '',
  setup_id: '',
  killzone_id: '',
  session_enum: '',
  tag_ids: '',
  date_from: '',
  date_to: '',
}

export const useTradeStore = defineStore('trades', () => {
  const analyticsStore = useAnalyticsStore()
  const accountStore = useAccountStore()
  const syncStatusStore = useSyncStatusStore()
  const trades = ref<Trade[]>([])
  const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
  })
  const filters = ref<TradeFilters>({ ...defaultFilters })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const instruments = ref<Instrument[]>([])
  const fxRates = ref<FxRate[]>([])
  const strategyModels = ref<TaxonomyItem[]>([])
  const setups = ref<TaxonomyItem[]>([])
  const killzones = ref<KillzoneItem[]>([])
  const tradeTags = ref<TradeTag[]>([])
  const sessionOptions = ref<SessionOption[]>([])
  const includeDraftsUnverified = ref(readTradeQualityPreference())
  const tradeRevisionById = ref<Record<number, number>>({})
  const tradeIdByLegId = ref<Record<number, number>>({})
  const tradeIdByImageId = ref<Record<number, number>>({})
  const requestManager = createRequestManager()
  let fetchTradesRequestVersion = 0

  function readPositiveInt(value: unknown): number | null {
    const numeric = Number(value)
    if (!Number.isInteger(numeric) || numeric <= 0) {
      return null
    }
    return numeric
  }

  function setTradeRevision(tradeId: number, revision: unknown): void {
    const normalizedTradeId = readPositiveInt(tradeId)
    const normalizedRevision = readPositiveInt(revision)
    if (normalizedTradeId === null || normalizedRevision === null) {
      return
    }
    tradeRevisionById.value[normalizedTradeId] = normalizedRevision
  }

  function clearTradeConcurrencyState(tradeId: number): void {
    const normalizedTradeId = readPositiveInt(tradeId)
    if (normalizedTradeId === null) {
      return
    }

    delete tradeRevisionById.value[normalizedTradeId]
    for (const [legId, mappedTradeId] of Object.entries(tradeIdByLegId.value)) {
      if (mappedTradeId !== normalizedTradeId) continue
      delete tradeIdByLegId.value[Number(legId)]
    }
    for (const [imageId, mappedTradeId] of Object.entries(tradeIdByImageId.value)) {
      if (mappedTradeId !== normalizedTradeId) continue
      delete tradeIdByImageId.value[Number(imageId)]
    }
  }

  function indexTradeLegs(tradeId: number, legs?: TradeLeg[] | null): void {
    const normalizedTradeId = readPositiveInt(tradeId)
    if (normalizedTradeId === null || !Array.isArray(legs)) {
      return
    }

    for (const leg of legs) {
      const legId = readPositiveInt(leg?.id)
      if (legId === null) continue
      tradeIdByLegId.value[legId] = normalizedTradeId
    }
  }

  function indexTradeImages(tradeId: number, images?: TradeImage[] | null): void {
    const normalizedTradeId = readPositiveInt(tradeId)
    if (normalizedTradeId === null || !Array.isArray(images)) {
      return
    }

    for (const image of images) {
      const imageId = readPositiveInt(image?.id)
      if (imageId === null) continue
      tradeIdByImageId.value[imageId] = normalizedTradeId
    }
  }

  function captureTradeConcurrencyState(trade: Trade | null | undefined): void {
    if (!trade) return
    setTradeRevision(trade.id, trade.revision)
    indexTradeLegs(trade.id, trade.legs)
    indexTradeImages(trade.id, trade.images)
  }

  function captureTradeDetailsConcurrencyState(payload: TradeDetailsResponse): void {
    captureTradeConcurrencyState(payload.trade)
    indexTradeLegs(payload.trade.id, payload.legs ?? payload.trade.legs ?? [])
    indexTradeImages(payload.trade.id, payload.images ?? payload.trade.images ?? [])
  }

  function resolveTradeIdForLeg(legId: number): number | null {
    const normalizedLegId = readPositiveInt(legId)
    if (normalizedLegId === null) {
      return null
    }

    const mapped = readPositiveInt(tradeIdByLegId.value[normalizedLegId])
    if (mapped !== null) {
      return mapped
    }

    for (const trade of trades.value) {
      if (!Array.isArray(trade.legs)) continue
      if (trade.legs.some((leg) => readPositiveInt(leg.id) === normalizedLegId)) {
        tradeIdByLegId.value[normalizedLegId] = trade.id
        return trade.id
      }
    }

    return null
  }

  function resolveTradeIdForImage(imageId: number): number | null {
    const normalizedImageId = readPositiveInt(imageId)
    if (normalizedImageId === null) {
      return null
    }

    const mapped = readPositiveInt(tradeIdByImageId.value[normalizedImageId])
    if (mapped !== null) {
      return mapped
    }

    for (const trade of trades.value) {
      if (!Array.isArray(trade.images)) continue
      if (trade.images.some((image) => readPositiveInt(image.id) === normalizedImageId)) {
        tradeIdByImageId.value[normalizedImageId] = trade.id
        return trade.id
      }
    }

    return null
  }

  function currentTradeRevision(tradeId: number): number | null {
    const normalizedTradeId = readPositiveInt(tradeId)
    if (normalizedTradeId === null) {
      return null
    }

    const cachedRevision = readPositiveInt(tradeRevisionById.value[normalizedTradeId])
    if (cachedRevision !== null) {
      return cachedRevision
    }

    const fromTradesState = trades.value.find((trade) => trade.id === normalizedTradeId)
    const snapshotRevision = readPositiveInt(fromTradesState?.revision)
    if (snapshotRevision !== null) {
      tradeRevisionById.value[normalizedTradeId] = snapshotRevision
      return snapshotRevision
    }

    return null
  }

  function parseTradeRevisionFromEtag(etagHeader: string | null | undefined): number | null {
    if (typeof etagHeader !== 'string') {
      return null
    }

    let candidate = etagHeader.trim()
    if (candidate === '') return null
    if (candidate.startsWith('W/')) {
      candidate = candidate.slice(2).trim()
    }

    candidate = candidate.replace(/^"+|"+$/g, '')
    const match = candidate.match(/^(\d+):/)
    if (!match) return null

    return readPositiveInt(match[1])
  }

  function readHeaderValue(
    headers: AxiosResponseHeaders | RawAxiosResponseHeaders | undefined,
    name: string
  ): string | null {
    if (!headers) {
      return null
    }

    if (typeof (headers as AxiosResponseHeaders).get === 'function') {
      const value = (headers as AxiosResponseHeaders).get(name)
      return typeof value === 'string' ? value : null
    }

    const lookup = Object.entries(headers).find(([key]) => key.toLowerCase() === name.toLowerCase())
    const raw = lookup?.[1]
    if (Array.isArray(raw)) {
      const first = raw[0]
      return typeof first === 'string' ? first : null
    }
    return typeof raw === 'string' ? raw : null
  }

  function captureRevisionFromResponseHeaders(
    tradeId: number,
    headers: AxiosResponseHeaders | RawAxiosResponseHeaders | undefined
  ): void {
    const etag = readHeaderValue(headers, 'etag')
    const revision = parseTradeRevisionFromEtag(etag)
    if (revision === null) {
      return
    }
    setTradeRevision(tradeId, revision)
  }

  function mergeTradeIntoList(trade: Trade): void {
    const index = trades.value.findIndex((row) => row.id === trade.id)
    if (index < 0) {
      return
    }

    trades.value[index] = trade
    trades.value = filterTradesByQuality(trades.value)
  }

  function getIfMatchHeaders(tradeId: number): { headers: { 'If-Match': string } } {
    const revision = currentTradeRevision(tradeId)
    if (revision === null) {
      throw new Error('Trade revision is unavailable. Reload latest trade data and try again.')
    }

    return {
      headers: {
        'If-Match': String(revision),
      },
    }
  }

  function isTradeConflictError(error: unknown): boolean {
    return normalizeApiError(error).isConflict
  }

  async function refreshTradeAfterConflict(tradeId: number): Promise<void> {
    try {
      const { data } = await api.get<TradeDetailsResponse>(`/trades/${tradeId}`)
      syncStatusStore.markServerHealthy()
      captureTradeDetailsConcurrencyState(data)
      mergeTradeIntoList(data.trade)
      upsertLocalTradeSnapshot({
        ...data.trade,
        local_sync_status: 'synced',
        risk_validation_status: 'verified',
      })
    } catch {
      // Ignore refresh failures and surface original conflict to caller.
    }
  }

  const hasFilters = computed(() => Object.values(filters.value).some((value) => value !== ''))
  const hasActiveFilters = computed(() => hasFilters.value)
  const visibleTrades = computed(() => filterTradesByQuality(trades.value))

  function isSynced(trade: Pick<Trade, 'local_sync_status'>): boolean {
    return (trade.local_sync_status ?? 'synced') === 'synced'
  }

  function isVerified(trade: Pick<Trade, 'risk_validation_status'>): boolean {
    return (trade.risk_validation_status ?? 'verified') === 'verified'
  }

  function isTradeQualityIncluded(trade: Pick<Trade, 'local_sync_status' | 'risk_validation_status'>): boolean {
    if (includeDraftsUnverified.value) return true
    return isSynced(trade) && isVerified(trade)
  }

  function filterTradesByQuality(rows: Trade[]): Trade[] {
    if (includeDraftsUnverified.value) {
      return rows
    }
    return rows.filter((trade) => isTradeQualityIncluded(trade))
  }

  function setIncludeDraftsUnverified(value: boolean): void {
    includeDraftsUnverified.value = Boolean(value)
    writeTradeQualityPreference(includeDraftsUnverified.value)
  }

  function refreshTradeQualityPreference(): void {
    includeDraftsUnverified.value = readTradeQualityPreference()
  }

  function invalidateTradeLoadCaches(): void {
    requestManager.invalidateCacheByPrefix('fetchTrades:')
  }

  async function fetchTrades(page = 1) {
    refreshTradeQualityPreference()
    const requestVersion = ++fetchTradesRequestVersion
    loading.value = true
    error.value = null
    const params = {
      page,
      per_page: pagination.value.per_page,
      include_drafts_unverified: includeDraftsUnverified.value ? 1 : 0,
      local_sync_status: includeDraftsUnverified.value ? undefined : 'synced',
      risk_validation_status: includeDraftsUnverified.value ? undefined : 'verified',
      ...filters.value,
    }
    const fingerprint = stableSerialize(params)
    const cacheKey = `fetchTrades:${fingerprint}`

    try {
      const response = await requestManager.run({
        key: 'fetchTrades',
        fingerprint,
        cacheKey,
        cacheTtlMs: 800,
        execute: async ({ signal }) => {
          const { data } = await api.get<Paginated<Trade>>('/trades', {
            params,
            signal,
          })
          return data
        },
      })
      if (response.stale || requestVersion !== fetchTradesRequestVersion) return
      const data = response.value
      syncStatusStore.markServerHealthy()

      trades.value = filterTradesByQuality(data.data ?? [])
      for (const trade of data.data) {
        captureTradeConcurrencyState(trade)
        upsertLocalTradeSnapshot({
          ...trade,
          local_sync_status: 'synced',
          risk_validation_status: 'verified',
        })
      }
      pagination.value.current_page = data.current_page
      pagination.value.last_page = data.last_page
      pagination.value.per_page = data.per_page
      pagination.value.total = data.total
    } catch (err) {
      if (isAbortError(err)) {
        return
      }

      if (!shouldUseLocalFallback(err)) {
        if (requestVersion === fetchTradesRequestVersion) {
          error.value = normalizeApiError(err).message
        }
        throw normalizeApiError(err)
      }
      if (requestVersion !== fetchTradesRequestVersion) return
      syncStatusStore.markLocalFallback('trades')

      const local = queryLocalTrades({
        page,
        per_page: pagination.value.per_page,
        pair: filters.value.pair,
        direction: filters.value.direction,
        model: filters.value.model,
        date_from: filters.value.date_from,
        date_to: filters.value.date_to,
        include_drafts_unverified: includeDraftsUnverified.value,
      })
      for (const trade of local.data) {
        captureTradeConcurrencyState(trade)
      }
      trades.value = filterTradesByQuality(local.data)
      pagination.value.current_page = local.current_page
      pagination.value.last_page = local.last_page
      pagination.value.per_page = local.per_page
      pagination.value.total = local.total
    } finally {
      if (requestVersion === fetchTradesRequestVersion) {
        loading.value = false
      }
    }
  }

  async function createTrade(payload: TradePayload): Promise<Trade> {
    saving.value = true
    const sanitizedPayload = sanitizeTradePayload(payload)

    const shouldOptimisticallyInsert = pagination.value.current_page === 1 && !hasActiveFilters.value
    const previousTrades = trades.value.slice()
    const previousPagination = { ...pagination.value }
    const tempId = -Date.now()
    const idempotencyKey = createIdempotencyKey()
    const createTradeRequestConfig = {
      headers: {
        'Idempotency-Key': idempotencyKey,
      },
    }

    if (shouldOptimisticallyInsert) {
      const optimistic = toOptimisticTrade(sanitizedPayload, tempId)
      trades.value = filterTradesByQuality([optimistic, ...trades.value]).slice(0, pagination.value.per_page)
      pagination.value.total += 1
    }

    try {
      const { data } = await api.post<Trade>('/trades', sanitizedPayload, createTradeRequestConfig)
      syncStatusStore.markServerHealthy()
      captureTradeConcurrencyState(data)
      invalidateTradeLoadCaches()

      if (shouldOptimisticallyInsert) {
        const index = trades.value.findIndex((trade) => trade.id === tempId)
        if (index >= 0) {
          trades.value[index] = data
        } else {
          trades.value = filterTradesByQuality([data, ...trades.value]).slice(0, pagination.value.per_page)
        }
      } else {
        await fetchTrades(pagination.value.current_page)
      }
      upsertLocalTradeSnapshot({
        ...data,
        local_sync_status: 'synced',
        risk_validation_status: 'verified',
      })

      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
      return data
    } catch (err) {
      if (shouldUseLocalFallback(err)) {
        syncStatusStore.markLocalFallback('trades')
        invalidateTradeLoadCaches()
        const local = createLocalTrade(sanitizedPayload)
        captureTradeConcurrencyState(local)
        enqueueSyncCreate({
          entity: 'trades',
          local_id: local.id,
          payload: pruneUndefined(sanitizedPayload),
          context: 'trades',
          risk_unverified: true,
        })
        setLocalTradeSyncStatus(local.id, 'draft_local', 'unverified')
        void syncStatusStore.refreshQueueState()
        if (shouldOptimisticallyInsert) {
          const index = trades.value.findIndex((trade) => trade.id === tempId)
          if (index >= 0) {
            trades.value[index] = local
          } else {
            trades.value = filterTradesByQuality([local, ...trades.value]).slice(0, pagination.value.per_page)
          }
        } else {
          await fetchTrades(1)
        }

        void analyticsStore.fetchAnalytics().catch(() => undefined)
        void accountStore.fetchAccounts().catch(() => undefined)
        return local
      }

      if (shouldOptimisticallyInsert) {
        trades.value = previousTrades
        pagination.value = previousPagination
      }
      throw normalizeApiError(err)
    } finally {
      saving.value = false
    }
  }

  async function addTrade(payload: TradePayload) {
    return await createTrade(payload)
  }

  async function updateTrade(id: number, payload: Partial<TradePayload>): Promise<Trade> {
    saving.value = true
    const sanitizedPayload = sanitizeTradePayload(payload)

    const index = trades.value.findIndex((trade) => trade.id === id)
    const current = index >= 0 ? trades.value[index] : undefined
    const previous: Trade | null = current ? { ...current } : null

    if (current && index >= 0) {
      trades.value[index] = {
        ...current,
        account_id: sanitizedPayload.account_id ?? current.account_id,
        instrument_id: sanitizedPayload.instrument_id ?? current.instrument_id,
        strategy_model_id: sanitizedPayload.strategy_model_id ?? current.strategy_model_id,
        setup_id: sanitizedPayload.setup_id ?? current.setup_id,
        killzone_id: sanitizedPayload.killzone_id ?? current.killzone_id,
        session_enum: sanitizedPayload.session_enum ?? current.session_enum,
        tag_ids: sanitizedPayload.tag_ids ?? current.tag_ids,
        pair: sanitizedPayload.symbol ? sanitizedPayload.symbol.toUpperCase() : current.pair,
        direction: sanitizedPayload.direction ?? current.direction,
        entry_price: sanitizedPayload.entry_price !== undefined ? String(sanitizedPayload.entry_price) : current.entry_price,
        stop_loss: sanitizedPayload.stop_loss !== undefined ? String(sanitizedPayload.stop_loss) : current.stop_loss,
        take_profit: sanitizedPayload.take_profit !== undefined ? String(sanitizedPayload.take_profit) : current.take_profit,
        actual_exit_price: sanitizedPayload.actual_exit_price !== undefined
          ? String(sanitizedPayload.actual_exit_price)
          : current.actual_exit_price,
        avg_exit_price: sanitizedPayload.actual_exit_price !== undefined
          ? String(sanitizedPayload.actual_exit_price)
          : current.avg_exit_price,
        lot_size: sanitizedPayload.position_size !== undefined ? String(sanitizedPayload.position_size) : current.lot_size,
        avg_entry_price: sanitizedPayload.entry_price !== undefined ? String(sanitizedPayload.entry_price) : current.avg_entry_price,
        commission: sanitizedPayload.commission !== undefined ? String(sanitizedPayload.commission) : current.commission,
        swap: sanitizedPayload.swap !== undefined ? String(sanitizedPayload.swap) : current.swap,
        spread_cost: sanitizedPayload.spread_cost !== undefined ? String(sanitizedPayload.spread_cost) : current.spread_cost,
        slippage_cost: sanitizedPayload.slippage_cost !== undefined ? String(sanitizedPayload.slippage_cost) : current.slippage_cost,
        legs: sanitizedPayload.legs !== undefined
          ? sanitizedPayload.legs.map((leg) => ({
            leg_type: leg.leg_type,
            price: String(leg.price),
            quantity_lots: String(leg.quantity_lots),
            executed_at: leg.executed_at,
            fees: leg.fees !== undefined ? String(leg.fees) : '0',
            notes: leg.notes ?? null,
          }))
          : current.legs,
        risk_override_reason: sanitizedPayload.risk_override_reason !== undefined
          ? sanitizedPayload.risk_override_reason
          : current.risk_override_reason,
        followed_rules: sanitizedPayload.followed_rules ?? current.followed_rules,
        checklist_incomplete: sanitizedPayload.checklist_incomplete ?? current.checklist_incomplete,
        emotion: sanitizedPayload.emotion ?? current.emotion,
        session: sanitizedPayload.session ?? current.session,
        model: sanitizedPayload.strategy_model ?? current.model,
        date: sanitizedPayload.close_date ?? current.date,
        notes: sanitizedPayload.notes !== undefined ? sanitizedPayload.notes : current.notes,
      }
    }

    try {
      const requestConfig = getIfMatchHeaders(id)
      const { data } = await api.put<Trade>(`/trades/${id}`, sanitizedPayload, requestConfig)
      syncStatusStore.markServerHealthy()
      captureTradeConcurrencyState(data)
      invalidateTradeLoadCaches()
      if (index >= 0) {
        trades.value[index] = data
        trades.value = filterTradesByQuality(trades.value)
      } else {
        await fetchTrades(pagination.value.current_page)
      }
      upsertLocalTradeSnapshot({
        ...data,
        local_sync_status: 'synced',
        risk_validation_status: 'verified',
      })

      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
      return data
    } catch (err) {
      if (shouldUseLocalFallback(err)) {
        syncStatusStore.markLocalFallback('trades')
        invalidateTradeLoadCaches()
        if (current) {
          upsertLocalTradeSnapshot(current)
        }
        const data = updateLocalTrade(id, sanitizedPayload)
        captureTradeConcurrencyState(data)
        enqueueSyncUpdate({
          entity: 'trades',
          local_id: data.id,
          server_id: id,
          expected_updated_at: current?.updated_at ?? null,
          expected_revision: current?.revision ?? null,
          payload: pruneUndefined(sanitizedPayload),
          context: 'trades',
          risk_unverified: data.risk_validation_status !== 'verified',
        })
        setLocalTradeSyncStatus(data.id, 'draft_local', data.risk_validation_status ?? 'unverified')
        void syncStatusStore.refreshQueueState()
        if (index >= 0) {
          trades.value[index] = data
          trades.value = filterTradesByQuality(trades.value)
        } else {
          await fetchTrades(pagination.value.current_page)
        }
        void analyticsStore.fetchAnalytics().catch(() => undefined)
        void accountStore.fetchAccounts().catch(() => undefined)
        return data
      }

      if (index >= 0 && previous) {
        trades.value[index] = previous
      }
      if (isTradeConflictError(err)) {
        await refreshTradeAfterConflict(id)
      }
      throw normalizeApiError(err)
    } finally {
      saving.value = false
    }
  }

  async function deleteTrade(id: number) {
    const requestConfig = getIfMatchHeaders(id)
    const index = trades.value.findIndex((trade) => trade.id === id)
    const previous = index >= 0 ? trades.value[index] : null

    if (index >= 0) {
      trades.value.splice(index, 1)
      pagination.value.total = Math.max(0, pagination.value.total - 1)
    }

    try {
      await api.delete(`/trades/${id}`, requestConfig)
      syncStatusStore.markServerHealthy()
      invalidateTradeLoadCaches()
      deleteLocalTrade(id)
      clearTradeConcurrencyState(id)

      if (trades.value.length === 0 && pagination.value.current_page > 1) {
        await fetchTrades(pagination.value.current_page - 1)
      }

      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
    } catch (err) {
      if (shouldUseLocalFallback(err)) {
        syncStatusStore.markLocalFallback('trades')
        invalidateTradeLoadCaches()
        if (previous) {
          upsertLocalTradeSnapshot(previous)
        }
        deleteLocalTrade(id)
        clearTradeConcurrencyState(id)
        enqueueSyncDelete({
          entity: 'trades',
          local_id: id,
          server_id: id,
          expected_updated_at: previous?.updated_at ?? null,
          expected_revision: previous?.revision ?? null,
          context: 'trades',
        })
        void syncStatusStore.refreshQueueState()
        await fetchTrades(Math.max(1, pagination.value.current_page))
        void analyticsStore.fetchAnalytics().catch(() => undefined)
        void accountStore.fetchAccounts().catch(() => undefined)
        return
      }

      if (index >= 0 && previous) {
        trades.value.splice(index, 0, previous)
        pagination.value.total += 1
      }
      if (isTradeConflictError(err)) {
        await refreshTradeAfterConflict(id)
      }
      throw normalizeApiError(err)
    }
  }

  async function fetchTradeDetails(id: number): Promise<TradeDetailsResponse> {
    try {
      const { data } = await api.get<TradeDetailsResponse>(`/trades/${id}`)
      syncStatusStore.markServerHealthy()
      captureTradeDetailsConcurrencyState(data)
      mergeTradeIntoList(data.trade)
      upsertLocalTradeSnapshot({
        ...data.trade,
        local_sync_status: 'synced',
        risk_validation_status: 'verified',
      })
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw normalizeApiError(error)
      }
      syncStatusStore.markLocalFallback('trade-details')
      const fallback = fetchLocalTradeDetails(id)
      captureTradeConcurrencyState(fallback.trade)
      return fallback
    }
  }

  async function fetchTradeLegs(tradeId: number): Promise<TradeLeg[]> {
    try {
      const { data } = await api.get<TradeLeg[]>(`/trades/${tradeId}/legs`)
      syncStatusStore.markServerHealthy()
      const rows = Array.isArray(data) ? data : []
      indexTradeLegs(tradeId, rows)
      return rows
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw normalizeApiError(error)
      }
      syncStatusStore.markLocalFallback('trade-legs')
      return []
    }
  }

  async function addTradeLeg(tradeId: number, payload: TradeLegPayload): Promise<TradeLeg> {
    try {
      const { data, headers } = await api.post<TradeLeg>(`/trades/${tradeId}/legs`, payload, getIfMatchHeaders(tradeId))
      syncStatusStore.markServerHealthy()
      indexTradeLegs(tradeId, [data])
      captureRevisionFromResponseHeaders(tradeId, headers)
      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
      return data
    } catch (error) {
      if (isTradeConflictError(error)) {
        await refreshTradeAfterConflict(tradeId)
      }
      throw normalizeApiError(error)
    }
  }

  async function updateTradeLeg(
    legId: number,
    payload: TradeLegPayload,
    options?: { tradeId?: number }
  ): Promise<TradeLeg> {
    const tradeId = readPositiveInt(options?.tradeId) ?? resolveTradeIdForLeg(legId)
    if (tradeId === null) {
      throw new Error('Trade context unavailable for this leg. Reload latest trade data and try again.')
    }

    try {
      const { data, headers } = await api.put<TradeLeg>(`/trade-legs/${legId}`, payload, getIfMatchHeaders(tradeId))
      syncStatusStore.markServerHealthy()
      indexTradeLegs(tradeId, [{ ...data, id: data.id ?? legId }])
      captureRevisionFromResponseHeaders(tradeId, headers)
      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
      return data
    } catch (error) {
      if (isTradeConflictError(error)) {
        await refreshTradeAfterConflict(tradeId)
      }
      throw normalizeApiError(error)
    }
  }

  async function deleteTradeLeg(legId: number, options?: { tradeId?: number }): Promise<void> {
    const tradeId = readPositiveInt(options?.tradeId) ?? resolveTradeIdForLeg(legId)
    if (tradeId === null) {
      throw new Error('Trade context unavailable for this leg. Reload latest trade data and try again.')
    }

    try {
      const { headers } = await api.delete(`/trade-legs/${legId}`, getIfMatchHeaders(tradeId))
      syncStatusStore.markServerHealthy()
      delete tradeIdByLegId.value[legId]
      captureRevisionFromResponseHeaders(tradeId, headers)
      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
    } catch (error) {
      if (isTradeConflictError(error)) {
        await refreshTradeAfterConflict(tradeId)
      }
      throw normalizeApiError(error)
    }
  }

  async function fetchInstruments() {
    try {
      const { data } = await api.get<Instrument[] | { data?: Instrument[] }>('/instruments')
      syncStatusStore.markServerHealthy()
      const primary = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : [])
      if (primary.length > 0) {
        instruments.value = primary
        return
      }

      const { data: secondaryData } = await api.get<Instrument[] | { data?: Instrument[] }>('/instruments', {
        params: { include_inactive: true },
      })
      const secondary = Array.isArray(secondaryData)
        ? secondaryData
        : (Array.isArray(secondaryData?.data) ? secondaryData.data : [])

      instruments.value = secondary.length > 0 ? secondary : defaultInstrumentsFallback()
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw normalizeApiError(error)
      }
      syncStatusStore.markLocalFallback('instruments')
      instruments.value = defaultInstrumentsFallback()
    }
  }

  async function fetchFxRates() {
    try {
      const { data } = await api.get<FxRate[] | { data?: FxRate[] }>('/fx-rates')
      syncStatusStore.markServerHealthy()
      const rows = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : [])
      fxRates.value = rows.length > 0 ? rows : defaultFxRatesFallback()
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw normalizeApiError(error)
      }
      syncStatusStore.markLocalFallback('fx-rates')
      fxRates.value = defaultFxRatesFallback()
    }
  }

  async function fetchDictionaries() {
    try {
      const [strategyRes, setupRes, killzoneRes, tagRes, sessionRes] = await Promise.all([
        api.get<TaxonomyItem[]>('/dictionaries/strategy-models'),
        api.get<TaxonomyItem[]>('/dictionaries/setups'),
        api.get<KillzoneItem[]>('/dictionaries/killzones'),
        api.get<TradeTag[]>('/dictionaries/trade-tags'),
        api.get<SessionOption[]>('/dictionaries/sessions'),
      ])
      syncStatusStore.markServerHealthy()

      strategyModels.value = Array.isArray(strategyRes.data) ? strategyRes.data : []
      setups.value = Array.isArray(setupRes.data) ? setupRes.data : []
      killzones.value = Array.isArray(killzoneRes.data) ? killzoneRes.data : []
      tradeTags.value = Array.isArray(tagRes.data) ? tagRes.data : []
      sessionOptions.value = Array.isArray(sessionRes.data) ? sessionRes.data : []
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw normalizeApiError(error)
      }
      syncStatusStore.markLocalFallback('dictionaries')
      strategyModels.value = [{ id: 1, name: 'General', slug: 'general', description: null, is_active: true }]
      setups.value = [{ id: 1, name: 'Breakout', slug: 'breakout', description: null, is_active: true }]
      killzones.value = [{ id: 1, name: 'London Open', slug: 'london-open', session_enum: 'london', description: null, is_active: true }]
      tradeTags.value = []
      sessionOptions.value = [
        { value: 'asia', label: 'Asia' },
        { value: 'london', label: 'London' },
        { value: 'new_york', label: 'New York' },
        { value: 'overlap', label: 'London/NY Overlap' },
        { value: 'off_session', label: 'Off Session' },
      ]
    }
  }

  async function fetchTradePsychology(tradeId: number): Promise<TradePsychology> {
    try {
      const { data, headers } = await api.get<TradePsychology>(`/trades/${tradeId}/psychology`)
      syncStatusStore.markServerHealthy()
      captureRevisionFromResponseHeaders(tradeId, headers)
      return data
    } catch (error) {
      throw normalizeApiError(error)
    }
  }

  async function upsertTradePsychology(tradeId: number, payload: Partial<TradePsychology>): Promise<TradePsychology> {
    try {
      const { data, headers } = await api.put<TradePsychology>(
        `/trades/${tradeId}/psychology`,
        payload,
        getIfMatchHeaders(tradeId)
      )
      syncStatusStore.markServerHealthy()
      captureRevisionFromResponseHeaders(tradeId, headers)
      void analyticsStore.fetchAnalytics().catch(() => undefined)
      return data
    } catch (error) {
      if (isTradeConflictError(error)) {
        await refreshTradeAfterConflict(tradeId)
      }
      throw normalizeApiError(error)
    }
  }

  async function precheckTrade(payload: TradePayload, tradeId?: number, options?: TradePrecheckOptions): Promise<TradePrecheckResult> {
    try {
      const body: Record<string, unknown> = sanitizeTradePayload({
        ...payload,
      })
      if (typeof tradeId === 'number' && tradeId > 0) {
        body.trade_id = tradeId
      }

      const fingerprint = stableSerialize(body)
      const response = await requestManager.run({
        key: `precheckTrade:${tradeId ?? 'new'}`,
        fingerprint,
        cacheKey: `precheck:${tradeId ?? 'new'}:${fingerprint}`,
        cacheTtlMs: 1_000,
        externalSignal: options?.signal,
        execute: async ({ signal }) => {
          const { data } = await api.post<TradePrecheckResult>('/trades/precheck', body, {
            signal,
          })
          return data
        },
      })
      if (!response.stale) {
        syncStatusStore.markServerHealthy()
      }
      return response.value
    } catch (error) {
      if (isAbortError(error)) {
        throw error
      }

      if (!shouldUseLocalFallback(error)) {
        throw normalizeApiError(error)
      }
      syncStatusStore.markLocalFallback('trades-precheck')
      return {
        allowed: false,
        risk_engine_unavailable: true,
        local_only_override_allowed: true,
        requires_override_reason: false,
        policy: {
          account_id: payload.account_id,
          max_risk_per_trade_pct: 1,
          max_daily_loss_pct: 5,
          max_total_drawdown_pct: 10,
          max_open_risk_pct: 2,
          enforce_hard_limits: true,
          allow_override: false,
        },
        violations: [
          {
            code: 'risk_engine_unavailable',
            message: 'Risk engine unavailable. Submission is blocked until connectivity is restored or local-only override is confirmed.',
            limit: 0,
            actual: 0,
          },
        ],
        stats: {
          risk_percent: 0,
          monetary_risk: 0,
          daily_realized_loss: 0,
          projected_daily_loss: 0,
          projected_daily_loss_pct: 0,
          projected_drawdown: 0,
          projected_drawdown_pct: 0,
        },
        calculated: {
          monetary_risk: 0,
          monetary_reward: 0,
          gross_profit_loss: 0,
          costs_total: 0,
          profit_loss: 0,
          risk_percent: 0,
          r_multiple: 0,
          realized_r_multiple: 0,
          avg_entry_price: 0,
          avg_exit_price: 0,
          rr: 0,
        },
      }
    }
  }

  async function uploadTradeImage(
    tradeId: number,
    file: File,
    sortOrder?: number,
    metadata?: {
      context_tag?: string | null
      timeframe?: string | null
      annotation_notes?: string | null
    },
    onProgress?: (percent: number) => void
  ): Promise<TradeImage> {
    const formData = new FormData()
    formData.append('image', file)
    if (typeof sortOrder === 'number') {
      formData.append('sort_order', String(sortOrder))
    }
    if (metadata?.context_tag) {
      formData.append('context_tag', metadata.context_tag)
    }
    if (metadata?.timeframe) {
      formData.append('timeframe', metadata.timeframe)
    }
    if (metadata?.annotation_notes) {
      formData.append('annotation_notes', metadata.annotation_notes)
    }

    try {
      const requestConfig = getIfMatchHeaders(tradeId)
      const { data, headers } = await api.post<TradeImage>(`/trades/${tradeId}/images`, formData, {
        ...requestConfig,
        headers: {
          ...requestConfig.headers,
          'Content-Type': 'multipart/form-data',
        },
        onUploadProgress: (progressEvent) => {
          if (!onProgress) return
          const total = progressEvent.total ?? 0
          if (total <= 0) return
          const value = Math.round((progressEvent.loaded / total) * 100)
          onProgress(Math.max(0, Math.min(100, value)))
        },
      })
      syncStatusStore.markServerHealthy()
      captureRevisionFromResponseHeaders(tradeId, headers)
      indexTradeImages(tradeId, [data])

      return data
    } catch (error) {
      if (isTradeConflictError(error)) {
        await refreshTradeAfterConflict(tradeId)
        throw normalizeApiError(error)
      }
      if (!shouldUseLocalFallback(error)) {
        throw normalizeApiError(error)
      }
      syncStatusStore.markLocalFallback('trade-images')

      onProgress?.(100)
      return await uploadLocalTradeImage(tradeId, file, sortOrder)
    }
  }

  async function deleteTradeImage(imageId: number, options?: { tradeId?: number }) {
    const tradeId = readPositiveInt(options?.tradeId) ?? resolveTradeIdForImage(imageId)
    if (tradeId === null) {
      throw new Error('Trade context unavailable for this image. Reload latest trade data and try again.')
    }

    try {
      const { headers } = await api.delete(`/trade-images/${imageId}`, getIfMatchHeaders(tradeId))
      syncStatusStore.markServerHealthy()
      delete tradeIdByImageId.value[imageId]
      captureRevisionFromResponseHeaders(tradeId, headers)
    } catch (error) {
      if (isTradeConflictError(error)) {
        await refreshTradeAfterConflict(tradeId)
        throw normalizeApiError(error)
      }
      if (!shouldUseLocalFallback(error)) {
        throw normalizeApiError(error)
      }
      syncStatusStore.markLocalFallback('trade-images')
      deleteLocalTradeImage(imageId)
    }
  }

  async function updateTradeImageMetadata(
    imageId: number,
    payload: {
      context_tag?: string | null
      timeframe?: string | null
      annotation_notes?: string | null
      sort_order?: number
    },
    options?: { tradeId?: number }
  ): Promise<TradeImage> {
    const tradeId = readPositiveInt(options?.tradeId) ?? resolveTradeIdForImage(imageId)
    if (tradeId === null) {
      throw new Error('Trade context unavailable for this image. Reload latest trade data and try again.')
    }

    try {
      const { data, headers } = await api.put<TradeImage>(
        `/trade-images/${imageId}`,
        payload,
        getIfMatchHeaders(tradeId)
      )
      syncStatusStore.markServerHealthy()
      captureRevisionFromResponseHeaders(tradeId, headers)
      indexTradeImages(tradeId, [data])
      return data
    } catch (error) {
      if (isTradeConflictError(error)) {
        await refreshTradeAfterConflict(tradeId)
      }
      throw normalizeApiError(error)
    }
  }

  function resetFilters() {
    filters.value = { ...defaultFilters }
  }

  return {
    trades,
    visibleTrades,
    tradeRevisionById,
    instruments,
    fxRates,
    strategyModels,
    setups,
    killzones,
    tradeTags,
    sessionOptions,
    pagination,
    filters,
    includeDraftsUnverified,
    loading,
    saving,
    error,
    hasFilters,
    getIfMatchHeaders,
    captureRevisionFromResponseHeaders,
    isSynced,
    isVerified,
    isTradeQualityIncluded,
    setIncludeDraftsUnverified,
    refreshTradeQualityPreference,
    fetchTrades,
    fetchInstruments,
    fetchFxRates,
    fetchDictionaries,
    fetchTradePsychology,
    upsertTradePsychology,
    precheckTrade,
    addTrade,
    createTrade,
    updateTrade,
    deleteTrade,
    fetchTradeDetails,
    fetchTradeLegs,
    addTradeLeg,
    updateTradeLeg,
    deleteTradeLeg,
    uploadTradeImage,
    updateTradeImageMetadata,
    deleteTradeImage,
    resetFilters,
  }
})

function readTradeQualityPreference(): boolean {
  try {
    const key = scopedKey(TRADE_PREFS_NAMESPACE, TRADE_PREFS_INCLUDE_DRAFTS_KEY)
    const raw = localStorage.getItem(key)
    if (!raw) return false
    return raw === '1' || raw.toLowerCase() === 'true'
  } catch {
    return false
  }
}

function writeTradeQualityPreference(value: boolean): void {
  try {
    const key = scopedKey(TRADE_PREFS_NAMESPACE, TRADE_PREFS_INCLUDE_DRAFTS_KEY)
    localStorage.setItem(key, value ? '1' : '0')
  } catch {
    // Ignore storage write failures.
  }
}

function toOptimisticTrade(payload: TradePayload, tempId: number): Trade {
  return {
    id: tempId,
    revision: 1,
    pair: payload.symbol.toUpperCase(),
    account_id: payload.account_id,
    instrument_id: payload.instrument_id,
    strategy_model_id: payload.strategy_model_id ?? null,
    setup_id: payload.setup_id ?? null,
    killzone_id: payload.killzone_id ?? null,
    session_enum: payload.session_enum ?? null,
    tag_ids: payload.tag_ids ?? [],
    direction: payload.direction,
    entry_price: String(payload.entry_price),
    avg_entry_price: String(payload.entry_price),
    stop_loss: String(payload.stop_loss),
    take_profit: String(payload.take_profit),
    actual_exit_price: String(payload.actual_exit_price),
    avg_exit_price: String(payload.actual_exit_price),
    lot_size: String(payload.position_size),
    risk_per_unit: null,
    reward_per_unit: null,
    monetary_risk: null,
    monetary_reward: null,
    gross_profit_loss: null,
    costs_total: null,
    commission: String(payload.commission ?? 0),
    swap: String(payload.swap ?? 0),
    spread_cost: String(payload.spread_cost ?? 0),
    slippage_cost: String(payload.slippage_cost ?? 0),
    profit_loss: '0',
    rr: '0',
    r_multiple: '0',
    realized_r_multiple: '0',
    risk_percent: null,
    account_balance_before_trade: null,
    account_balance_after_trade: null,
    followed_rules: payload.followed_rules,
    checklist_incomplete: payload.checklist_incomplete ?? false,
    emotion: payload.emotion,
    risk_override_reason: payload.risk_override_reason ?? null,
    session: payload.session ?? 'N/A',
    model: payload.strategy_model ?? 'General',
    date: payload.close_date,
    legs: payload.legs?.map((leg) => ({
      leg_type: leg.leg_type,
      price: String(leg.price),
      quantity_lots: String(leg.quantity_lots),
      executed_at: leg.executed_at,
      fees: String(leg.fees ?? 0),
      notes: leg.notes ?? null,
    })) ?? [],
    notes: payload.notes,
    images: [],
    images_count: 0,
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  }
}

function defaultInstrumentsFallback(): Instrument[] {
  type FallbackSeed = {
    symbol: string
    asset_class: string
    base_currency: string
    quote_currency: string
    contract_size: number
    tick_size: number
    tick_value: number
    pip_size: number
    min_lot: number
    lot_step: number
  }

  const forex = (symbol: string, base: string, quote: string): FallbackSeed => ({
    symbol,
    asset_class: 'forex',
    base_currency: base,
    quote_currency: quote,
    contract_size: 100000,
    tick_size: quote === 'JPY' ? 0.001 : 0.00001,
    tick_value: 1,
    pip_size: quote === 'JPY' ? 0.01 : 0.0001,
    min_lot: 0.01,
    lot_step: 0.01,
  })

  const crypto = (symbol: string, base: string, quote = 'USDT'): FallbackSeed => ({
    symbol,
    asset_class: 'crypto',
    base_currency: base,
    quote_currency: quote,
    contract_size: 1,
    tick_size: 0.01,
    tick_value: 0.01,
    pip_size: 1,
    min_lot: 0.01,
    lot_step: 0.01,
  })

  const stock = (symbol: string): FallbackSeed => ({
    symbol,
    asset_class: 'stocks',
    base_currency: symbol,
    quote_currency: 'USD',
    contract_size: 1,
    tick_size: 0.01,
    tick_value: 0.01,
    pip_size: 0.01,
    min_lot: 1,
    lot_step: 1,
  })

  const index = (symbol: string): FallbackSeed => ({
    symbol,
    asset_class: 'indices',
    base_currency: symbol,
    quote_currency: 'USD',
    contract_size: 1,
    tick_size: 0.1,
    tick_value: 0.1,
    pip_size: 1,
    min_lot: 1,
    lot_step: 1,
  })

  const future = (symbol: string): FallbackSeed => ({
    symbol,
    asset_class: 'futures',
    base_currency: symbol,
    quote_currency: 'USD',
    contract_size: 1,
    tick_size: 0.25,
    tick_value: 0.25,
    pip_size: 1,
    min_lot: 1,
    lot_step: 1,
  })

  const commodity = (symbol: string, base: string, quote = 'USD'): FallbackSeed => ({
    symbol,
    asset_class: 'commodities',
    base_currency: base,
    quote_currency: quote,
    contract_size: base === 'XAU' || base === 'XAG' ? 100 : 1,
    tick_size: base === 'XAU' || base === 'XAG' ? 0.01 : 0.01,
    tick_value: 1,
    pip_size: base === 'XAU' || base === 'XAG' ? 0.1 : 0.1,
    min_lot: 0.01,
    lot_step: 0.01,
  })

  const seeds: FallbackSeed[] = [
    forex('EURUSD', 'EUR', 'USD'),
    forex('GBPUSD', 'GBP', 'USD'),
    forex('USDJPY', 'USD', 'JPY'),
    forex('AUDUSD', 'AUD', 'USD'),
    forex('NZDUSD', 'NZD', 'USD'),
    forex('USDCAD', 'USD', 'CAD'),
    forex('USDCHF', 'USD', 'CHF'),
    forex('EURGBP', 'EUR', 'GBP'),
    forex('EURJPY', 'EUR', 'JPY'),
    forex('GBPJPY', 'GBP', 'JPY'),
    forex('AUDJPY', 'AUD', 'JPY'),
    forex('CHFJPY', 'CHF', 'JPY'),
    forex('EURAUD', 'EUR', 'AUD'),
    forex('EURNZD', 'EUR', 'NZD'),
    forex('GBPAUD', 'GBP', 'AUD'),
    forex('GBPCHF', 'GBP', 'CHF'),

    crypto('BTCUSDT', 'BTC'),
    crypto('ETHUSDT', 'ETH'),
    crypto('SOLUSDT', 'SOL'),
    crypto('BNBUSDT', 'BNB'),
    crypto('XRPUSDT', 'XRP'),
    crypto('ADAUSDT', 'ADA'),
    crypto('DOGEUSDT', 'DOGE'),
    crypto('LTCUSDT', 'LTC'),

    stock('AAPL'),
    stock('MSFT'),
    stock('NVDA'),
    stock('TSLA'),
    stock('AMZN'),
    stock('META'),
    stock('GOOGL'),
    stock('NFLX'),

    index('US30'),
    index('NAS100'),
    index('SPX500'),
    index('DAX40'),
    index('UK100'),
    index('JP225'),
    index('HK50'),

    future('NQ'),
    future('ES'),
    future('YM'),
    future('CL'),
    future('GC'),
    future('SI'),

    commodity('XAUUSD', 'XAU'),
    commodity('XAGUSD', 'XAG'),
    commodity('WTI', 'WTI'),
    commodity('BRENT', 'BRENT'),
    commodity('NATGAS', 'NATGAS'),
  ]

  return seeds.map((seed, index) => ({
    id: index + 1,
    symbol: seed.symbol,
    asset_class: seed.asset_class,
    base_currency: seed.base_currency,
    quote_currency: seed.quote_currency,
    contract_size: seed.contract_size.toFixed(8),
    tick_size: seed.tick_size.toFixed(10),
    tick_value: seed.tick_value.toFixed(8),
    pip_size: seed.pip_size.toFixed(10),
    min_lot: seed.min_lot.toFixed(4),
    lot_step: seed.lot_step.toFixed(4),
    is_active: true,
  }))
}

function defaultFxRatesFallback(): FxRate[] {
  const now = new Date().toISOString()
  const rows: Array<{ from_currency: string; to_currency: string; rate: number }> = [
    { from_currency: 'USD', to_currency: 'JPY', rate: 150.0 },
    { from_currency: 'GBP', to_currency: 'USD', rate: 1.27 },
    { from_currency: 'EUR', to_currency: 'USD', rate: 1.08 },
    { from_currency: 'USD', to_currency: 'CHF', rate: 0.88 },
    { from_currency: 'USD', to_currency: 'CAD', rate: 1.35 },
    { from_currency: 'AUD', to_currency: 'USD', rate: 0.66 },
    { from_currency: 'NZD', to_currency: 'USD', rate: 0.61 },
    { from_currency: 'EUR', to_currency: 'GBP', rate: 0.85 },
  ]

  return rows.map((row, index) => ({
    id: index + 1,
    from_currency: row.from_currency,
    to_currency: row.to_currency,
    rate: row.rate.toFixed(10),
    rate_updated_at: now,
    created_at: now,
    updated_at: now,
  }))
}

function pruneUndefined<T extends object>(payload: T): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(payload as Record<string, unknown>).filter(([, value]) => value !== undefined)
  )
}

function sanitizeTradePayload<T extends object>(input: T): T {
  const payload = { ...(input as Record<string, unknown>) }
  for (const key of PROHIBITED_TRADE_FX_FIELDS) {
    delete payload[key]
  }
  return payload as T
}
