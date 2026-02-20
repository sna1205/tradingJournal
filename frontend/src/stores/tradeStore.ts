import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import { useAnalyticsStore } from '@/stores/analyticsStore'
import type { Paginated, Trade } from '@/types/trade'

interface TradeFilters {
  pair: string
  direction: '' | 'buy' | 'sell'
  model: string
  date_from: string
  date_to: string
}

export interface TradePayload {
  pair: string
  direction: 'buy' | 'sell'
  entry_price: number
  stop_loss: number
  take_profit: number
  lot_size: number
  profit_loss: number
  rr: number
  session: string
  model: string
  date: string
  notes: string | null
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

  const hasFilters = computed(() => Object.values(filters.value).some((value) => value !== ''))

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

      trades.value = data.data
      pagination.value.current_page = data.current_page
      pagination.value.last_page = data.last_page
      pagination.value.per_page = data.per_page
      pagination.value.total = data.total
    } catch (err) {
      error.value = 'Failed to load trades.'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createTrade(payload: TradePayload) {
    saving.value = true
    try {
      await api.post('/trades', payload)
      await fetchTrades(1)
      await analyticsStore.fetchAnalytics()
    } finally {
      saving.value = false
    }
  }

  async function addTrade(payload: TradePayload) {
    await createTrade(payload)
  }

  async function updateTrade(id: number, payload: Partial<TradePayload>) {
    saving.value = true
    try {
      await api.put(`/trades/${id}`, payload)
      await fetchTrades(pagination.value.current_page)
      await analyticsStore.fetchAnalytics()
    } finally {
      saving.value = false
    }
  }

  async function deleteTrade(id: number) {
    await api.delete(`/trades/${id}`)
    await fetchTrades(pagination.value.current_page)
    await analyticsStore.fetchAnalytics()
  }

  function resetFilters() {
    filters.value = { ...defaultFilters }
  }

  return {
    trades,
    pagination,
    filters,
    loading,
    saving,
    error,
    hasFilters,
    fetchTrades,
    addTrade,
    createTrade,
    updateTrade,
    deleteTrade,
    resetFilters,
  }
})
