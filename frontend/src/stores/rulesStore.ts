import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import { normalizeApiError } from '@/utils/apiError'
import type {
  Checklist,
  ChecklistEnforcementMode,
  ChecklistItem,
  ChecklistItemType,
  ChecklistRuleDefinition,
  ChecklistScope,
} from '@/types/rules'

interface ChecklistFilter {
  scope?: ChecklistScope | ''
  accountId?: number | null
  strategyModelId?: number | null
  search?: string
}

interface CreateChecklistPayload {
  name: string
  scope: ChecklistScope
  account_id?: number | null
  strategy_model_id?: number | null
  enforcement_mode: ChecklistEnforcementMode
  is_active?: boolean
}

interface CreateChecklistItemPayload {
  title: string
  type: ChecklistItemType
  rule?: ChecklistRuleDefinition
  required?: boolean
  category?: string
  help_text?: string | null
  config?: Record<string, unknown>
  is_active?: boolean
}

export const useRulesStore = defineStore('rules', () => {
  const checklists = ref<Checklist[]>([])
  const itemsByChecklist = ref<Record<number, ChecklistItem[]>>({})
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const selectedChecklistId = ref<number | null>(null)

  const selectedChecklist = computed(() =>
    checklists.value.find((item) => item.id === selectedChecklistId.value) ?? null
  )
  const selectedItems = computed(() =>
    selectedChecklistId.value ? (itemsByChecklist.value[selectedChecklistId.value] ?? []) : []
  )

  async function fetchChecklists(filter: ChecklistFilter = {}) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get<Checklist[]>('/rules', {
        params: {
          scope: filter.scope || undefined,
          accountId: filter.accountId ?? undefined,
          strategyModelId: filter.strategyModelId ?? undefined,
          search: filter.search || undefined,
        },
      })
      checklists.value = Array.isArray(data) ? data : []
      if (checklists.value.length > 0 && !selectedChecklistId.value) {
        selectedChecklistId.value = checklists.value[0]!.id
      }
    } catch (err) {
      const normalized = normalizeApiError(err)
      error.value = normalized.message
      throw normalized
    } finally {
      loading.value = false
    }
  }

  async function fetchChecklistItems(checklistId: number) {
    try {
      const { data } = await api.get<ChecklistItem[]>(`/rules/${checklistId}/items`)
      itemsByChecklist.value = {
        ...itemsByChecklist.value,
        [checklistId]: Array.isArray(data) ? data : [],
      }
      return itemsByChecklist.value[checklistId]!
    } catch (errorValue) {
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    }
  }

  async function createChecklist(payload: CreateChecklistPayload) {
    saving.value = true
    try {
      const { data } = await api.post<Checklist>('/rules', payload)
      checklists.value = [data, ...checklists.value]
      selectedChecklistId.value = data.id
      itemsByChecklist.value[data.id] = []
      return data
    } catch (errorValue) {
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    } finally {
      saving.value = false
    }
  }

  async function duplicateChecklist(checklistId: number) {
    saving.value = true
    try {
      const { data } = await api.post<Checklist>(`/rules/${checklistId}/duplicate`)
      checklists.value = [data, ...checklists.value]
      selectedChecklistId.value = data.id
      await fetchChecklistItems(data.id)
      return data
    } catch (errorValue) {
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    } finally {
      saving.value = false
    }
  }

  async function updateChecklist(checklistId: number, payload: Partial<CreateChecklistPayload>) {
    saving.value = true
    try {
      const { data } = await api.put<Checklist>(`/rules/${checklistId}`, payload)
      const index = checklists.value.findIndex((item) => item.id === checklistId)
      if (index >= 0) {
        checklists.value[index] = data
      }
      return data
    } catch (errorValue) {
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    } finally {
      saving.value = false
    }
  }

  async function removeChecklist(checklistId: number) {
    saving.value = true
    try {
      await api.delete(`/rules/${checklistId}`)
      checklists.value = checklists.value.filter((item) => item.id !== checklistId)
      if (selectedChecklistId.value === checklistId) {
        selectedChecklistId.value = checklists.value[0]?.id ?? null
      }
    } catch (errorValue) {
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    } finally {
      saving.value = false
    }
  }

  async function createItem(checklistId: number, payload: CreateChecklistItemPayload) {
    saving.value = true
    try {
      const { data } = await api.post<ChecklistItem>(`/rules/${checklistId}/items`, payload)
      const current = itemsByChecklist.value[checklistId] ?? []
      itemsByChecklist.value[checklistId] = [...current, data]
        .sort((a, b) => a.order_index - b.order_index || a.id - b.id)
      return data
    } catch (errorValue) {
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    } finally {
      saving.value = false
    }
  }

  async function updateItem(itemId: number, payload: Partial<CreateChecklistItemPayload & { order_index: number }>) {
    saving.value = true
    try {
      const { data } = await api.put<ChecklistItem>(`/rule-items/${itemId}`, payload)
      const checklistId = data.checklist_id
      const current = itemsByChecklist.value[checklistId] ?? []
      const index = current.findIndex((item) => item.id === data.id)
      if (index >= 0) {
        current[index] = data
        itemsByChecklist.value[checklistId] = [...current].sort((a, b) => a.order_index - b.order_index || a.id - b.id)
      }
      return data
    } catch (errorValue) {
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    } finally {
      saving.value = false
    }
  }

  async function removeItem(checklistId: number, itemId: number) {
    saving.value = true
    try {
      await api.delete(`/rule-items/${itemId}`)
      itemsByChecklist.value[checklistId] = (itemsByChecklist.value[checklistId] ?? [])
        .filter((item) => item.id !== itemId)
    } catch (errorValue) {
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    } finally {
      saving.value = false
    }
  }

  async function reorderItems(checklistId: number, orderedItemIds: number[]) {
    const current = itemsByChecklist.value[checklistId] ?? []
    const byId = new Map(current.map((item) => [item.id, item]))
    const optimistic = orderedItemIds
      .map((id, index) => {
        const item = byId.get(id)
        if (!item) return null
        return { ...item, order_index: index }
      })
      .filter((item): item is ChecklistItem => Boolean(item))

    itemsByChecklist.value[checklistId] = optimistic

    try {
      const { data } = await api.put<{ items: ChecklistItem[] }>(`/rules/${checklistId}/items/reorder`, {
        item_ids: orderedItemIds,
      })
      itemsByChecklist.value[checklistId] = data.items
    } catch (errorValue) {
      itemsByChecklist.value[checklistId] = current
      const normalized = normalizeApiError(errorValue)
      error.value = normalized.message
      throw normalized
    }
  }

  function setSelectedChecklist(checklistId: number | null) {
    selectedChecklistId.value = checklistId
  }

  return {
    checklists,
    itemsByChecklist,
    loading,
    saving,
    error,
    selectedChecklistId,
    selectedChecklist,
    selectedItems,
    fetchChecklists,
    fetchChecklistItems,
    createChecklist,
    duplicateChecklist,
    updateChecklist,
    removeChecklist,
    createItem,
    updateItem,
    removeItem,
    reorderItems,
    setSelectedChecklist,
  }
})
