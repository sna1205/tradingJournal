import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
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
}

interface TradePrecheckOptions {
  signal?: AbortSignal
}

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
  const requestManager = createRequestManager()
  let fetchTradesRequestVersion = 0

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
          error.value = 'Failed to load trades.'
        }
        throw err
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

    const shouldOptimisticallyInsert = pagination.value.current_page === 1 && !hasActiveFilters.value
    const previousTrades = trades.value.slice()
    const previousPagination = { ...pagination.value }
    const tempId = -Date.now()

    if (shouldOptimisticallyInsert) {
      const optimistic = toOptimisticTrade(payload, tempId)
      trades.value = filterTradesByQuality([optimistic, ...trades.value]).slice(0, pagination.value.per_page)
      pagination.value.total += 1
    }

    try {
      const { data } = await api.post<Trade>('/trades', payload)
      syncStatusStore.markServerHealthy()
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
        const local = createLocalTrade(payload)
        enqueueSyncCreate({
          entity: 'trades',
          local_id: local.id,
          payload: pruneUndefined(payload),
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
      throw err
    } finally {
      saving.value = false
    }
  }

  async function addTrade(payload: TradePayload) {
    return await createTrade(payload)
  }

  async function updateTrade(id: number, payload: Partial<TradePayload>): Promise<Trade> {
    saving.value = true

    const index = trades.value.findIndex((trade) => trade.id === id)
    const current = index >= 0 ? trades.value[index] : undefined
    const previous: Trade | null = current ? { ...current } : null

    if (current && index >= 0) {
      trades.value[index] = {
        ...current,
        account_id: payload.account_id ?? current.account_id,
        instrument_id: payload.instrument_id ?? current.instrument_id,
        strategy_model_id: payload.strategy_model_id ?? current.strategy_model_id,
        setup_id: payload.setup_id ?? current.setup_id,
        killzone_id: payload.killzone_id ?? current.killzone_id,
        session_enum: payload.session_enum ?? current.session_enum,
        tag_ids: payload.tag_ids ?? current.tag_ids,
        pair: payload.symbol ? payload.symbol.toUpperCase() : current.pair,
        direction: payload.direction ?? current.direction,
        entry_price: payload.entry_price !== undefined ? String(payload.entry_price) : current.entry_price,
        stop_loss: payload.stop_loss !== undefined ? String(payload.stop_loss) : current.stop_loss,
        take_profit: payload.take_profit !== undefined ? String(payload.take_profit) : current.take_profit,
        actual_exit_price: payload.actual_exit_price !== undefined
          ? String(payload.actual_exit_price)
          : current.actual_exit_price,
        avg_exit_price: payload.actual_exit_price !== undefined
          ? String(payload.actual_exit_price)
          : current.avg_exit_price,
        lot_size: payload.position_size !== undefined ? String(payload.position_size) : current.lot_size,
        avg_entry_price: payload.entry_price !== undefined ? String(payload.entry_price) : current.avg_entry_price,
        commission: payload.commission !== undefined ? String(payload.commission) : current.commission,
        swap: payload.swap !== undefined ? String(payload.swap) : current.swap,
        spread_cost: payload.spread_cost !== undefined ? String(payload.spread_cost) : current.spread_cost,
        slippage_cost: payload.slippage_cost !== undefined ? String(payload.slippage_cost) : current.slippage_cost,
        legs: payload.legs !== undefined
          ? payload.legs.map((leg) => ({
            leg_type: leg.leg_type,
            price: String(leg.price),
            quantity_lots: String(leg.quantity_lots),
            executed_at: leg.executed_at,
            fees: leg.fees !== undefined ? String(leg.fees) : '0',
            notes: leg.notes ?? null,
          }))
          : current.legs,
        risk_override_reason: payload.risk_override_reason !== undefined
          ? payload.risk_override_reason
          : current.risk_override_reason,
        followed_rules: payload.followed_rules ?? current.followed_rules,
        checklist_incomplete: payload.checklist_incomplete ?? current.checklist_incomplete,
        emotion: payload.emotion ?? current.emotion,
        session: payload.session ?? current.session,
        model: payload.strategy_model ?? current.model,
        date: payload.close_date ?? current.date,
        notes: payload.notes !== undefined ? payload.notes : current.notes,
      }
    }

    try {
      const { data } = await api.put<Trade>(`/trades/${id}`, payload)
      syncStatusStore.markServerHealthy()
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
        const data = updateLocalTrade(id, payload)
        enqueueSyncUpdate({
          entity: 'trades',
          local_id: data.id,
          server_id: id,
          expected_updated_at: current?.updated_at ?? null,
          payload: pruneUndefined(payload),
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
      throw err
    } finally {
      saving.value = false
    }
  }

  async function deleteTrade(id: number) {
    const index = trades.value.findIndex((trade) => trade.id === id)
    const previous = index >= 0 ? trades.value[index] : null

    if (index >= 0) {
      trades.value.splice(index, 1)
      pagination.value.total = Math.max(0, pagination.value.total - 1)
    }

    try {
      await api.delete(`/trades/${id}`)
      syncStatusStore.markServerHealthy()
      invalidateTradeLoadCaches()
      deleteLocalTrade(id)

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
        enqueueSyncDelete({
          entity: 'trades',
          local_id: id,
          server_id: id,
          expected_updated_at: previous?.updated_at ?? null,
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
      throw err
    }
  }

  async function fetchTradeDetails(id: number): Promise<TradeDetailsResponse> {
    try {
      const { data } = await api.get<TradeDetailsResponse>(`/trades/${id}`)
      syncStatusStore.markServerHealthy()
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('trade-details')
      return fetchLocalTradeDetails(id)
    }
  }

  async function fetchTradeLegs(tradeId: number): Promise<TradeLeg[]> {
    try {
      const { data } = await api.get<TradeLeg[]>(`/trades/${tradeId}/legs`)
      syncStatusStore.markServerHealthy()
      return Array.isArray(data) ? data : []
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('trade-legs')
      return []
    }
  }

  async function addTradeLeg(tradeId: number, payload: TradeLegPayload): Promise<TradeLeg> {
    const { data } = await api.post<TradeLeg>(`/trades/${tradeId}/legs`, payload)
    syncStatusStore.markServerHealthy()
    void analyticsStore.fetchAnalytics().catch(() => undefined)
    void accountStore.fetchAccounts().catch(() => undefined)
    return data
  }

  async function updateTradeLeg(legId: number, payload: TradeLegPayload): Promise<TradeLeg> {
    const { data } = await api.put<TradeLeg>(`/trade-legs/${legId}`, payload)
    syncStatusStore.markServerHealthy()
    void analyticsStore.fetchAnalytics().catch(() => undefined)
    void accountStore.fetchAccounts().catch(() => undefined)
    return data
  }

  async function deleteTradeLeg(legId: number): Promise<void> {
    await api.delete(`/trade-legs/${legId}`)
    syncStatusStore.markServerHealthy()
    void analyticsStore.fetchAnalytics().catch(() => undefined)
    void accountStore.fetchAccounts().catch(() => undefined)
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
        throw error
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
        throw error
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
        throw error
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
    const { data } = await api.get<TradePsychology>(`/trades/${tradeId}/psychology`)
    syncStatusStore.markServerHealthy()
    return data
  }

  async function upsertTradePsychology(tradeId: number, payload: Partial<TradePsychology>): Promise<TradePsychology> {
    const { data } = await api.put<TradePsychology>(`/trades/${tradeId}/psychology`, payload)
    syncStatusStore.markServerHealthy()
    void analyticsStore.fetchAnalytics().catch(() => undefined)
    return data
  }

  async function precheckTrade(payload: TradePayload, tradeId?: number, options?: TradePrecheckOptions): Promise<TradePrecheckResult> {
    try {
      const body: Record<string, unknown> = {
        ...payload,
      }
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
        throw error
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
      const { data } = await api.post<TradeImage>(`/trades/${tradeId}/images`, formData, {
        headers: {
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

      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('trade-images')

      onProgress?.(100)
      return await uploadLocalTradeImage(tradeId, file, sortOrder)
    }
  }

  async function deleteTradeImage(imageId: number) {
    try {
      await api.delete(`/trade-images/${imageId}`)
      syncStatusStore.markServerHealthy()
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
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
    }
  ): Promise<TradeImage> {
    const { data } = await api.put<TradeImage>(`/trade-images/${imageId}`, payload)
    syncStatusStore.markServerHealthy()
    return data
  }

  function resetFilters() {
    filters.value = { ...defaultFilters }
  }

  return {
    trades,
    visibleTrades,
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
