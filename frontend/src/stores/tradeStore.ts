import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import {
  createLocalTrade,
  deleteLocalTrade,
  deleteLocalTradeImage,
  fetchLocalTradeDetails,
  queryLocalTrades,
  shouldUseLocalFallback,
  updateLocalTrade,
  uploadLocalTradeImage,
} from '@/services/localFallback'
import { useAnalyticsStore } from '@/stores/analyticsStore'
import { useAccountStore } from '@/stores/accountStore'
import { useSyncStatusStore } from '@/stores/syncStatusStore'
import type {
  Instrument,
  Paginated,
  Trade,
  TradeDetailsResponse,
  TradeEmotion,
  TradeImage,
} from '@/types/trade'

interface TradeFilters {
  pair: string
  direction: '' | 'buy' | 'sell'
  model: string
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
  risk_override_reason?: string | null
  followed_rules: boolean
  emotion: TradeEmotion
  session?: string
  strategy_model?: string
  close_date: string
  notes: string | null
}

export interface TradePrecheckViolation {
  code: string
  message: string
  limit: number
  actual: number
}

export interface TradePrecheckResult {
  allowed: boolean
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
    rr: number
  }
}

const defaultFilters: TradeFilters = {
  pair: '',
  direction: '',
  model: '',
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

  const hasFilters = computed(() => Object.values(filters.value).some((value) => value !== ''))
  const hasActiveFilters = computed(() => hasFilters.value)

  async function fetchTrades(page = 1) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get<Paginated<Trade>>('/trades', {
        params: {
          page,
          per_page: pagination.value.per_page,
          ...filters.value,
        },
      })
      syncStatusStore.markServerHealthy()

      trades.value = data.data
      pagination.value.current_page = data.current_page
      pagination.value.last_page = data.last_page
      pagination.value.per_page = data.per_page
      pagination.value.total = data.total
    } catch (err) {
      if (!shouldUseLocalFallback(err)) {
        error.value = 'Failed to load trades.'
        throw err
      }
      syncStatusStore.markLocalFallback('trades')

      const local = queryLocalTrades({
        page,
        per_page: pagination.value.per_page,
        pair: filters.value.pair,
        direction: filters.value.direction,
        model: filters.value.model,
        date_from: filters.value.date_from,
        date_to: filters.value.date_to,
      })
      trades.value = local.data
      pagination.value.current_page = local.current_page
      pagination.value.last_page = local.last_page
      pagination.value.per_page = local.per_page
      pagination.value.total = local.total
    } finally {
      loading.value = false
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
      trades.value = [optimistic, ...trades.value].slice(0, pagination.value.per_page)
      pagination.value.total += 1
    }

    try {
      const { data } = await api.post<Trade>('/trades', payload)
      syncStatusStore.markServerHealthy()

      if (shouldOptimisticallyInsert) {
        const index = trades.value.findIndex((trade) => trade.id === tempId)
        if (index >= 0) {
          trades.value[index] = data
        } else {
          trades.value = [data, ...trades.value].slice(0, pagination.value.per_page)
        }
      } else {
        await fetchTrades(pagination.value.current_page)
      }

      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
      return data
    } catch (err) {
      if (shouldUseLocalFallback(err)) {
        syncStatusStore.markLocalFallback('trades')
        const local = createLocalTrade(payload)
        if (shouldOptimisticallyInsert) {
          const index = trades.value.findIndex((trade) => trade.id === tempId)
          if (index >= 0) {
            trades.value[index] = local
          } else {
            trades.value = [local, ...trades.value].slice(0, pagination.value.per_page)
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
        pair: payload.symbol ? payload.symbol.toUpperCase() : current.pair,
        direction: payload.direction ?? current.direction,
        entry_price: payload.entry_price !== undefined ? String(payload.entry_price) : current.entry_price,
        stop_loss: payload.stop_loss !== undefined ? String(payload.stop_loss) : current.stop_loss,
        take_profit: payload.take_profit !== undefined ? String(payload.take_profit) : current.take_profit,
        actual_exit_price: payload.actual_exit_price !== undefined
          ? String(payload.actual_exit_price)
          : current.actual_exit_price,
        lot_size: payload.position_size !== undefined ? String(payload.position_size) : current.lot_size,
        commission: payload.commission !== undefined ? String(payload.commission) : current.commission,
        swap: payload.swap !== undefined ? String(payload.swap) : current.swap,
        spread_cost: payload.spread_cost !== undefined ? String(payload.spread_cost) : current.spread_cost,
        slippage_cost: payload.slippage_cost !== undefined ? String(payload.slippage_cost) : current.slippage_cost,
        risk_override_reason: payload.risk_override_reason !== undefined
          ? payload.risk_override_reason
          : current.risk_override_reason,
        followed_rules: payload.followed_rules ?? current.followed_rules,
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
      if (index >= 0) {
        trades.value[index] = data
      } else {
        await fetchTrades(pagination.value.current_page)
      }

      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
      return data
    } catch (err) {
      if (shouldUseLocalFallback(err)) {
        syncStatusStore.markLocalFallback('trades')
        const data = updateLocalTrade(id, payload)
        if (index >= 0) {
          trades.value[index] = data
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

      if (trades.value.length === 0 && pagination.value.current_page > 1) {
        await fetchTrades(pagination.value.current_page - 1)
      }

      void analyticsStore.fetchAnalytics().catch(() => undefined)
      void accountStore.fetchAccounts().catch(() => undefined)
    } catch (err) {
      if (shouldUseLocalFallback(err)) {
        syncStatusStore.markLocalFallback('trades')
        deleteLocalTrade(id)
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

  async function fetchInstruments() {
    try {
      const { data } = await api.get<Instrument[]>('/instruments')
      syncStatusStore.markServerHealthy()
      instruments.value = Array.isArray(data) ? data : []
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('instruments')
      instruments.value = defaultInstrumentsFallback()
    }
  }

  async function precheckTrade(payload: TradePayload, tradeId?: number): Promise<TradePrecheckResult> {
    try {
      const body: Record<string, unknown> = {
        ...payload,
      }
      if (typeof tradeId === 'number' && tradeId > 0) {
        body.trade_id = tradeId
      }

      const { data } = await api.post<TradePrecheckResult>('/trades/precheck', body)
      syncStatusStore.markServerHealthy()
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('trades-precheck')
      return {
        allowed: true,
        requires_override_reason: false,
        policy: {
          account_id: payload.account_id,
          max_risk_per_trade_pct: 1,
          max_daily_loss_pct: 5,
          max_total_drawdown_pct: 10,
          max_open_risk_pct: 2,
          enforce_hard_limits: false,
          allow_override: false,
        },
        violations: [],
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
          rr: 0,
        },
      }
    }
  }

  async function uploadTradeImage(
    tradeId: number,
    file: File,
    sortOrder?: number,
    onProgress?: (percent: number) => void
  ): Promise<TradeImage> {
    const formData = new FormData()
    formData.append('image', file)
    if (typeof sortOrder === 'number') {
      formData.append('sort_order', String(sortOrder))
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

  function resetFilters() {
    filters.value = { ...defaultFilters }
  }

  return {
    trades,
    instruments,
    pagination,
    filters,
    loading,
    saving,
    error,
    hasFilters,
    fetchTrades,
    fetchInstruments,
    precheckTrade,
    addTrade,
    createTrade,
    updateTrade,
    deleteTrade,
    fetchTradeDetails,
    uploadTradeImage,
    deleteTradeImage,
    resetFilters,
  }
})

function toOptimisticTrade(payload: TradePayload, tempId: number): Trade {
  return {
    id: tempId,
    pair: payload.symbol.toUpperCase(),
    account_id: payload.account_id,
    instrument_id: payload.instrument_id,
    direction: payload.direction,
    entry_price: String(payload.entry_price),
    stop_loss: String(payload.stop_loss),
    take_profit: String(payload.take_profit),
    actual_exit_price: String(payload.actual_exit_price),
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
    risk_percent: null,
    account_balance_before_trade: null,
    account_balance_after_trade: null,
    followed_rules: payload.followed_rules,
    emotion: payload.emotion,
    risk_override_reason: payload.risk_override_reason ?? null,
    session: payload.session ?? 'N/A',
    model: payload.strategy_model ?? 'General',
    date: payload.close_date,
    notes: payload.notes,
    images: [],
    images_count: 0,
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  }
}

function defaultInstrumentsFallback(): Instrument[] {
  return [
    {
      id: 1,
      symbol: 'EURUSD',
      asset_class: 'forex',
      base_currency: 'EUR',
      quote_currency: 'USD',
      contract_size: '100000.00000000',
      tick_size: '0.0000100000',
      tick_value: '1.00000000',
      pip_size: '0.0001000000',
      min_lot: '0.0100',
      lot_step: '0.0100',
      is_active: true,
    },
    {
      id: 4,
      symbol: 'XAUUSD',
      asset_class: 'metal',
      base_currency: 'XAU',
      quote_currency: 'USD',
      contract_size: '100.00000000',
      tick_size: '0.0100000000',
      tick_value: '1.00000000',
      pip_size: '0.1000000000',
      min_lot: '0.0100',
      lot_step: '0.0100',
      is_active: true,
    },
  ]
}
