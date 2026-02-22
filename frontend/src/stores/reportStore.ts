import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/services/api'
import type { SavedReport } from '@/types/trade'

export const useReportStore = defineStore('reports', () => {
  const reports = ref<SavedReport[]>([])
  const loading = ref(false)

  async function fetchReports(scope: 'trades' | 'dashboard') {
    loading.value = true
    try {
      const { data } = await api.get<SavedReport[]>('/reports', { params: { scope } })
      reports.value = Array.isArray(data) ? data : []
    } finally {
      loading.value = false
    }
  }

  async function createReport(payload: {
    name: string
    scope: 'trades' | 'dashboard'
    filters_json: Record<string, unknown>
    columns_json?: string[] | null
    is_default?: boolean
  }): Promise<SavedReport> {
    const { data } = await api.post<SavedReport>('/reports', payload)
    return data
  }

  async function updateReport(
    id: number,
    payload: Partial<{
      name: string
      scope: 'trades' | 'dashboard'
      filters_json: Record<string, unknown>
      columns_json: string[] | null
      is_default: boolean
    }>
  ): Promise<SavedReport> {
    const { data } = await api.put<SavedReport>(`/reports/${id}`, payload)
    return data
  }

  async function runReport(id: number, params?: Record<string, unknown>) {
    const { data } = await api.get(`/reports/${id}/run`, { params })
    return data
  }

  function exportReportCsv(id: number, params?: Record<string, unknown>) {
    const search = new URLSearchParams()
    if (params) {
      Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return
        search.set(key, String(value))
      })
    }
    const query = search.toString()
    const path = `/api/reports/${id}/export.csv${query ? `?${query}` : ''}`
    window.open(path, '_blank')
  }

  function exportAdHocCsv(params?: Record<string, unknown>) {
    const search = new URLSearchParams()
    if (params) {
      Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return
        search.set(key, String(value))
      })
    }
    const query = search.toString()
    const path = `/api/reports/export.csv${query ? `?${query}` : ''}`
    window.open(path, '_blank')
  }

  return {
    reports,
    loading,
    fetchReports,
    createReport,
    updateReport,
    runReport,
    exportReportCsv,
    exportAdHocCsv,
  }
})
