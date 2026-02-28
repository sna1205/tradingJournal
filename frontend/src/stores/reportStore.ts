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

  async function exportReportCsv(id: number, params?: Record<string, unknown>) {
    await downloadCsv(
      `/reports/${id}/export.csv`,
      params,
      `report-${id}.csv`
    )
  }

  async function exportAdHocCsv(params?: Record<string, unknown>) {
    const scope = String(params?.scope ?? '').toLowerCase()
    const fallbackName = scope === 'dashboard'
      ? 'dashboard-export.csv'
      : 'trade-log-export.csv'

    await downloadCsv('/reports/export.csv', params, fallbackName)
  }

  async function downloadCsv(
    endpoint: string,
    params: Record<string, unknown> | undefined,
    fallbackFilename: string
  ): Promise<void> {
    const response = await api.get<Blob>(endpoint, {
      params: normalizeExportParams(params),
      responseType: 'blob',
      withCredentials: true,
      headers: {
        Accept: 'text/csv,application/octet-stream',
      },
    })

    const fileName = resolveCsvFilename(
      response.headers?.['content-disposition'] as string | undefined,
      fallbackFilename
    )

    triggerBlobDownload(response.data, fileName)
  }

  function normalizeExportParams(params?: Record<string, unknown>): Record<string, string> {
    if (!params) {
      return {}
    }

    const normalized: Record<string, string> = {}
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') {
        return
      }

      if (Array.isArray(value)) {
        if (value.length === 0) {
          return
        }
        normalized[key] = value.map((entry) => String(entry)).join(',')
        return
      }

      normalized[key] = String(value)
    })

    return normalized
  }

  function resolveCsvFilename(contentDisposition: string | undefined, fallback: string): string {
    if (!contentDisposition) {
      return sanitizeFilename(fallback)
    }

    const utf8Match = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)
    if (utf8Match?.[1]) {
      try {
        return sanitizeFilename(decodeURIComponent(utf8Match[1].trim()))
      } catch {
        return sanitizeFilename(utf8Match[1].trim())
      }
    }

    const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/i)
    if (filenameMatch?.[1]) {
      return sanitizeFilename(filenameMatch[1].trim())
    }

    return sanitizeFilename(fallback)
  }

  function sanitizeFilename(value: string): string {
    const cleaned = value
      .replace(/[<>:"/\\|?*\u0000-\u001F]/g, '_')
      .replace(/\s+/g, ' ')
      .trim()

    if (cleaned === '') {
      return 'export.csv'
    }

    return cleaned.toLowerCase().endsWith('.csv')
      ? cleaned
      : `${cleaned}.csv`
  }

  function triggerBlobDownload(blob: Blob, filename: string): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
      return
    }

    const objectUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = objectUrl
    link.download = filename
    link.rel = 'noopener'
    link.style.display = 'none'
    document.body.appendChild(link)
    link.click()
    link.remove()

    window.setTimeout(() => {
      window.URL.revokeObjectURL(objectUrl)
    }, 0)
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
