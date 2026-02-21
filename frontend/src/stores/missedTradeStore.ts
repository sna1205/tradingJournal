import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import type { MissedTrade, MissedTradeImage, Paginated } from '@/types/trade'

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
      const { data } = await api.post<MissedTrade>('/missed-trades', payload)
      await fetchMissedTrades(1)
      return data
    } finally {
      saving.value = false
    }
  }

  async function updateMissedTrade(id: number, payload: Partial<MissedTradePayload>) {
    saving.value = true
    try {
      const { data } = await api.put<MissedTrade>(`/missed-trades/${id}`, payload)
      await fetchMissedTrades(pagination.value.current_page)
      return data
    } finally {
      saving.value = false
    }
  }

  async function deleteMissedTrade(id: number) {
    await api.delete(`/missed-trades/${id}`)
    await fetchMissedTrades(pagination.value.current_page)
  }

  async function fetchMissedTrade(id: number) {
    const { data } = await api.get<MissedTrade>(`/missed-trades/${id}`)
    return data
  }

  async function uploadMissedTradeImage(
    missedTradeId: number,
    file: File,
    sortOrder?: number,
    onProgress?: (percent: number) => void
  ): Promise<MissedTradeImage> {
    const formData = new FormData()
    formData.append('image', file)
    if (typeof sortOrder === 'number') {
      formData.append('sort_order', String(sortOrder))
    }

    const { data } = await api.post<MissedTradeImage>(`/missed-trades/${missedTradeId}/images`, formData, {
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

    return data
  }

  async function deleteMissedTradeImage(imageId: number) {
    await api.delete(`/missed-trade-images/${imageId}`)
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
    fetchMissedTrade,
    uploadMissedTradeImage,
    deleteMissedTradeImage,
    resetFilters,
  }
})
