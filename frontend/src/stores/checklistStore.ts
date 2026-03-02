import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import type { Checklist, ChecklistEnforcementMode, ChecklistItem, ChecklistItemType, ChecklistScope } from '@/types/checklist'

interface ChecklistFilter {
  scope?: ChecklistScope | ''
  accountId?: number | null
  search?: string
}

interface CreateChecklistPayload {
  name: string
  scope: ChecklistScope
  account_id?: number | null
  enforcement_mode: ChecklistEnforcementMode
  is_active?: boolean
}

interface CreateChecklistItemPayload {
  title: string
  type: ChecklistItemType
  required?: boolean
  category?: string
  help_text?: string | null
  config?: Record<string, unknown>
  is_active?: boolean
}

export const useChecklistStore = defineStore('checklists', () => {
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
      const { data } = await api.get<Checklist[]>('/checklists', {
        params: {
          scope: filter.scope || undefined,
          accountId: filter.accountId ?? undefined,
          search: filter.search || undefined,
        },
      })
      checklists.value = Array.isArray(data) ? data : []
      if (checklists.value.length > 0 && !selectedChecklistId.value) {
        selectedChecklistId.value = checklists.value[0]!.id
      }
    } catch (err) {
      error.value = 'Failed to load checklists.'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchChecklistItems(checklistId: number) {
    const { data } = await api.get<ChecklistItem[]>(`/checklists/${checklistId}/items`)
    itemsByChecklist.value = {
      ...itemsByChecklist.value,
      [checklistId]: Array.isArray(data) ? data : [],
    }
    return itemsByChecklist.value[checklistId]!
  }

  async function createChecklist(payload: CreateChecklistPayload) {
    saving.value = true
    try {
      const { data } = await api.post<Checklist>('/checklists', payload)
      checklists.value = [data, ...checklists.value]
      selectedChecklistId.value = data.id
      itemsByChecklist.value[data.id] = []
      return data
    } finally {
      saving.value = false
    }
  }

  async function duplicateChecklist(checklistId: number) {
    saving.value = true
    try {
      const { data } = await api.post<Checklist>(`/checklists/${checklistId}/duplicate`)
      checklists.value = [data, ...checklists.value]
      selectedChecklistId.value = data.id
      await fetchChecklistItems(data.id)
      return data
    } finally {
      saving.value = false
    }
  }

  async function updateChecklist(checklistId: number, payload: Partial<CreateChecklistPayload>) {
    saving.value = true
    try {
      const { data } = await api.put<Checklist>(`/checklists/${checklistId}`, payload)
      const index = checklists.value.findIndex((item) => item.id === checklistId)
      if (index >= 0) {
        checklists.value[index] = data
      }
      return data
    } finally {
      saving.value = false
    }
  }

  async function removeChecklist(checklistId: number) {
    saving.value = true
    try {
      await api.delete(`/checklists/${checklistId}`)
      checklists.value = checklists.value.filter((item) => item.id !== checklistId)
      if (selectedChecklistId.value === checklistId) {
        selectedChecklistId.value = checklists.value[0]?.id ?? null
      }
    } finally {
      saving.value = false
    }
  }

  async function createItem(checklistId: number, payload: CreateChecklistItemPayload) {
    saving.value = true
    try {
      const { data } = await api.post<ChecklistItem>(`/checklists/${checklistId}/items`, payload)
      const current = itemsByChecklist.value[checklistId] ?? []
      itemsByChecklist.value[checklistId] = [...current, data]
        .sort((a, b) => a.order_index - b.order_index || a.id - b.id)
      return data
    } finally {
      saving.value = false
    }
  }

  async function updateItem(itemId: number, payload: Partial<CreateChecklistItemPayload & { order_index: number }>) {
    saving.value = true
    try {
      const { data } = await api.put<ChecklistItem>(`/checklist-items/${itemId}`, payload)
      const checklistId = data.checklist_id
      const current = itemsByChecklist.value[checklistId] ?? []
      const index = current.findIndex((item) => item.id === data.id)
      if (index >= 0) {
        current[index] = data
        itemsByChecklist.value[checklistId] = [...current].sort((a, b) => a.order_index - b.order_index || a.id - b.id)
      }
      return data
    } finally {
      saving.value = false
    }
  }

  async function removeItem(checklistId: number, itemId: number) {
    saving.value = true
    try {
      await api.delete(`/checklist-items/${itemId}`)
      itemsByChecklist.value[checklistId] = (itemsByChecklist.value[checklistId] ?? []).map((item) =>
        item.id === itemId
          ? { ...item, is_active: false }
          : item
      )
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
      const { data } = await api.put<{ items: ChecklistItem[] }>(`/checklists/${checklistId}/items/reorder`, {
        item_ids: orderedItemIds,
      })
      itemsByChecklist.value[checklistId] = data.items
    } catch (error) {
      itemsByChecklist.value[checklistId] = current
      throw error
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
