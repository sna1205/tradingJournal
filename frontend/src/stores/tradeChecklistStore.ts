import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import api from '@/services/api'
import type {
  Checklist,
  ChecklistItem,
  TradeChecklistItemWithResponse,
  TradeChecklistReadiness,
  TradeChecklistResponsePayload,
  TradeChecklistResponseRecord,
} from '@/types/checklist'

interface ResponseWriteRow {
  checklist_item_id: number
  value: unknown
}

function emptyReadiness(): TradeChecklistReadiness {
  return {
    status: 'ready',
    completed_required: 0,
    total_required: 0,
    missing_required: [],
    ready: true,
  }
}

function defaultValueForType(type: ChecklistItem['type']): unknown {
  if (type === 'checkbox') return false
  if (type === 'number' || type === 'scale') return null
  return ''
}

function normalizeValue(item: ChecklistItem, value: unknown): unknown {
  if (item.type === 'checkbox') return Boolean(value)
  if (item.type === 'number' || item.type === 'scale') {
    return value === '' || value === null || value === undefined ? null : Number(value)
  }
  if (value === null || value === undefined) return ''
  return String(value)
}

function isCompleted(item: ChecklistItem, value: unknown): boolean {
  if (item.type === 'checkbox') return Boolean(value)
  if (item.type === 'number' || item.type === 'scale') {
    return typeof value === 'number' && Number.isFinite(value)
  }

  return typeof value === 'string' && value.trim().length > 0
}

function buildReadiness(items: TradeChecklistItemWithResponse[]): TradeChecklistReadiness {
  const requiredItems = items.filter((item) => item.required && item.is_active)
  const completedRequired = requiredItems.filter((item) => item.response.is_completed).length
  const totalRequired = requiredItems.length

  let status: TradeChecklistReadiness['status'] = 'ready'
  if (totalRequired > 0 && completedRequired === 0) {
    status = 'not_ready'
  } else if (totalRequired > 0 && completedRequired < totalRequired) {
    status = 'almost'
  }

  return {
    status,
    completed_required: completedRequired,
    total_required: totalRequired,
    missing_required: requiredItems
      .filter((item) => !item.response.is_completed)
      .map((item) => ({
        checklist_item_id: item.id,
        title: item.title,
        category: item.category,
      })),
    ready: totalRequired === 0 || completedRequired >= totalRequired,
  }
}

export const useTradeChecklistStore = defineStore('tradeChecklist', () => {
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const checklist = ref<Checklist | null>(null)
  const items = ref<TradeChecklistItemWithResponse[]>([])
  const archivedResponses = ref<TradeChecklistResponseRecord[]>([])
  const readiness = ref<TradeChecklistReadiness>(emptyReadiness())
  const submitAttempted = ref(false)
  const contextTradeId = ref<number | null>(null)
  const contextAccountId = ref<number | null>(null)
  let persistTimer: ReturnType<typeof setTimeout> | null = null
  let queuedPersist = false

  const requiredItems = computed(() =>
    items.value.filter((item) => item.required && item.is_active)
  )
  const optionalItems = computed(() =>
    items.value.filter((item) => !item.required && item.is_active)
  )
  const enforcementMode = computed(() => checklist.value?.enforcement_mode ?? 'soft')
  const isStrict = computed(() => enforcementMode.value === 'strict')
  const checklistIncomplete = computed(() => !readiness.value.ready)
  const hasChecklist = computed(() => checklist.value !== null)

  function clearPersistTimer() {
    if (persistTimer) {
      clearTimeout(persistTimer)
      persistTimer = null
    }
  }

  function resetState() {
    checklist.value = null
    items.value = []
    archivedResponses.value = []
    readiness.value = emptyReadiness()
    error.value = null
    contextTradeId.value = null
    contextAccountId.value = null
    clearPersistTimer()
    queuedPersist = false
  }

  function applyPayload(payload: TradeChecklistResponsePayload) {
    checklist.value = payload.responses.checklist
    items.value = payload.responses.items
    archivedResponses.value = payload.responses.archived_responses ?? []
    readiness.value = payload.readiness
  }

  function rebuildReadinessLocal() {
    readiness.value = buildReadiness(items.value)
  }

  async function resolveChecklistForCreate(accountId: number | null): Promise<Checklist | null> {
    if (accountId !== null && accountId > 0) {
      const { data: accountScoped } = await api.get<Checklist[]>('/checklists', {
        params: {
          scope: 'account',
          accountId,
        },
      })
      const accountChecklist = (Array.isArray(accountScoped) ? accountScoped : [])
        .find((entry) => entry.is_active)
      if (accountChecklist) return accountChecklist
    }

    const { data: globalScoped } = await api.get<Checklist[]>('/checklists', {
      params: {
        scope: 'global',
      },
    })
    return (Array.isArray(globalScoped) ? globalScoped : []).find((entry) => entry.is_active) ?? null
  }

  async function loadForCreate(accountId: number | null) {
    loading.value = true
    error.value = null
    contextTradeId.value = null
    contextAccountId.value = accountId
    clearPersistTimer()

    try {
      const resolvedChecklist = await resolveChecklistForCreate(accountId)
      if (!resolvedChecklist) {
        resetState()
        return
      }

      const { data } = await api.get<ChecklistItem[]>(`/checklists/${resolvedChecklist.id}/items`)
      const sourceItems = (Array.isArray(data) ? data : [])
        .filter((item) => item.is_active)
        .sort((left, right) => left.order_index - right.order_index || left.id - right.id)

      checklist.value = resolvedChecklist
      items.value = sourceItems.map((item) => {
        const value = defaultValueForType(item.type)
        return {
          ...item,
          response: {
            checklist_item_id: item.id,
            value,
            is_completed: isCompleted(item, value),
            completed_at: null,
            archived: false,
          },
        }
      })
      archivedResponses.value = []
      rebuildReadinessLocal()
    } catch {
      resetState()
      error.value = 'Failed to load pre-trade checklist.'
    } finally {
      loading.value = false
    }
  }

  async function loadForTrade(tradeId: number) {
    loading.value = true
    error.value = null
    contextTradeId.value = tradeId
    clearPersistTimer()

    try {
      const { data } = await api.get<TradeChecklistResponsePayload>(`/trades/${tradeId}/checklist-responses`)
      applyPayload(data)
    } catch {
      resetState()
      error.value = 'Failed to load trade checklist responses.'
    } finally {
      loading.value = false
    }
  }

  function buildWritePayload(): ResponseWriteRow[] {
    return items.value.map((item) => ({
      checklist_item_id: item.id,
      value: item.response.value,
    }))
  }

  async function persistNow(tradeIdOverride?: number) {
    const activeTradeId = tradeIdOverride ?? contextTradeId.value
    if (!activeTradeId || !checklist.value) return

    if (saving.value) {
      queuedPersist = true
      return
    }

    saving.value = true
    error.value = null
    try {
      const { data } = await api.put<TradeChecklistResponsePayload>(`/trades/${activeTradeId}/checklist-responses`, {
        checklist_id: checklist.value.id,
        responses: buildWritePayload(),
      })
      applyPayload(data)
    } catch {
      error.value = 'Failed to persist checklist responses.'
    } finally {
      saving.value = false
      if (queuedPersist) {
        queuedPersist = false
        await persistNow(activeTradeId)
      }
    }
  }

  function schedulePersist() {
    if (!contextTradeId.value) return
    clearPersistTimer()
    persistTimer = setTimeout(() => {
      void persistNow()
    }, 400)
  }

  function updateResponse(checklistItemId: number, nextRawValue: unknown, shouldPersist = true) {
    const index = items.value.findIndex((item) => item.id === checklistItemId)
    if (index < 0) return

    const item = items.value[index]!
    const value = normalizeValue(item, nextRawValue)
    const completed = isCompleted(item, value)

    items.value[index] = {
      ...item,
      response: {
        ...item.response,
        value,
        is_completed: completed,
        completed_at: completed ? new Date().toISOString() : null,
        archived: false,
      },
    }

    rebuildReadinessLocal()
    if (shouldPersist) {
      schedulePersist()
    }
  }

  function markSubmitAttempted(value = true) {
    submitAttempted.value = value
  }

  function clearSubmitAttempted() {
    submitAttempted.value = false
  }

  async function persistForTrade(tradeId: number) {
    contextTradeId.value = tradeId
    clearPersistTimer()
    await persistNow(tradeId)
  }

  return {
    loading,
    saving,
    error,
    checklist,
    items,
    archivedResponses,
    readiness,
    submitAttempted,
    contextTradeId,
    contextAccountId,
    requiredItems,
    optionalItems,
    enforcementMode,
    isStrict,
    checklistIncomplete,
    hasChecklist,
    resetState,
    loadForCreate,
    loadForTrade,
    updateResponse,
    markSubmitAttempted,
    clearSubmitAttempted,
    persistForTrade,
  }
})
