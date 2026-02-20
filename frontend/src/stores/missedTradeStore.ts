import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import type { MissedTrade, Paginated } from '@/types/trade'

interface MissedTradeFilters {
  pair: string
  model: string
  reason: string
  date_from: string
  date_to: string
}

export interface MissedTradePayload {
  pair: string
  model: string
  reason: string
  date: string
  notes: string | null
}

const defaultFilters: MissedTradeFilters = {
  pair: '',
  model: '',
  reason: '',
  date_from: '',
  date_to: '',
}

export const useMissedTradeStore = defineStore('missed-trades', () => {
  const missedTrades = ref<MissedTrade[]>([])
  const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
  })
  const filters = ref<MissedTradeFilters>({ ...defaultFilters })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  const hasFilters = computed(() => Object.values(filters.value).some((value) => value !== ''))

  async function fetchMissedTrades(page = 1) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get<Paginated<MissedTrade>>('/missed-trades', {
        params: {
          page,
          per_page: pagination.value.per_page,
          ...filters.value,
        },
      })

      missedTrades.value = data.data
      pagination.value.current_page = data.current_page
      pagination.value.last_page = data.last_page
      pagination.value.per_page = data.per_page
      pagination.value.total = data.total
    } catch (err) {
      error.value = 'Failed to load missed trades.'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createMissedTrade(payload: MissedTradePayload) {
    saving.value = true
    try {
      await api.post('/missed-trades', payload)
      await fetchMissedTrades(1)
    } finally {
      saving.value = false
    }
  }

  async function updateMissedTrade(id: number, payload: Partial<MissedTradePayload>) {
    saving.value = true
    try {
      await api.put(`/missed-trades/${id}`, payload)
      await fetchMissedTrades(pagination.value.current_page)
    } finally {
      saving.value = false
    }
  }

  async function deleteMissedTrade(id: number) {
    await api.delete(`/missed-trades/${id}`)
    await fetchMissedTrades(pagination.value.current_page)
  }

  function resetFilters() {
    filters.value = { ...defaultFilters }
  }

  return {
    missedTrades,
    pagination,
    filters,
    loading,
    saving,
    error,
    hasFilters,
    fetchMissedTrades,
    createMissedTrade,
    updateMissedTrade,
    deleteMissedTrade,
    resetFilters,
  }
})
