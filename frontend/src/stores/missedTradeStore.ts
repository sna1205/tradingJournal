import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import {
  createLocalMissedTrade,
  deleteLocalMissedTrade,
  deleteLocalMissedTradeImage,
  fetchLocalMissedTrade,
  queryLocalMissedTrades,
  shouldUseLocalFallback,
  updateLocalMissedTrade,
  uploadLocalMissedTradeImage,
} from '@/services/localFallback'
import { useSyncStatusStore } from '@/stores/syncStatusStore'
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
  const syncStatusStore = useSyncStatusStore()
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
      syncStatusStore.markServerHealthy()

      missedTrades.value = data.data
      pagination.value.current_page = data.current_page
      pagination.value.last_page = data.last_page
      pagination.value.per_page = data.per_page
      pagination.value.total = data.total
    } catch (err) {
      if (!shouldUseLocalFallback(err)) {
        error.value = 'Failed to load missed trades.'
        throw err
      }
      syncStatusStore.markLocalFallback('missed-trades')

      const local = queryLocalMissedTrades({
        page,
        per_page: pagination.value.per_page,
        pair: filters.value.pair,
        model: filters.value.model,
        reason: filters.value.reason,
        date_from: filters.value.date_from,
        date_to: filters.value.date_to,
      })

      missedTrades.value = local.data
      pagination.value.current_page = local.current_page
      pagination.value.last_page = local.last_page
      pagination.value.per_page = local.per_page
      pagination.value.total = local.total
    } finally {
      loading.value = false
    }
  }

  async function createMissedTrade(payload: MissedTradePayload) {
    saving.value = true
    try {
      const { data } = await api.post<MissedTrade>('/missed-trades', payload)
      syncStatusStore.markServerHealthy()
      await fetchMissedTrades(1)
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('missed-trades')

      const data = createLocalMissedTrade(payload)
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
      syncStatusStore.markServerHealthy()
      await fetchMissedTrades(pagination.value.current_page)
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('missed-trades')

      const data = updateLocalMissedTrade(id, payload)
      await fetchMissedTrades(pagination.value.current_page)
      return data
    } finally {
      saving.value = false
    }
  }

  async function deleteMissedTrade(id: number) {
    try {
      await api.delete(`/missed-trades/${id}`)
      syncStatusStore.markServerHealthy()
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('missed-trades')
      deleteLocalMissedTrade(id)
    }
    await fetchMissedTrades(pagination.value.current_page)
  }

  async function fetchMissedTrade(id: number) {
    try {
      const { data } = await api.get<MissedTrade>(`/missed-trades/${id}`)
      syncStatusStore.markServerHealthy()
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('missed-trade-details')
      return fetchLocalMissedTrade(id)
    }
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

    try {
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
      syncStatusStore.markServerHealthy()

      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('missed-trade-images')
      onProgress?.(100)
      return await uploadLocalMissedTradeImage(missedTradeId, file, sortOrder)
    }
  }

  async function deleteMissedTradeImage(imageId: number) {
    try {
      await api.delete(`/missed-trade-images/${imageId}`)
      syncStatusStore.markServerHealthy()
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('missed-trade-images')
      deleteLocalMissedTradeImage(imageId)
    }
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
