import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import api from '@/services/api'
import { createRequestManager, isAbortError, stableSerialize } from '@/services/requestManager'
import { normalizeApiError } from '@/utils/apiError'
import type {
  Checklist,
  ChecklistItem,
  TradeChecklistExecutionSnapshot,
  TradeChecklistItemWithResponse,
  TradeChecklistReadiness,
  TradeChecklistResponsePayload,
  TradeChecklistResolverContext,
  TradeChecklistResponseRecord,
} from '@/types/rules'

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
        reason: typeof item.response.reason === 'string' ? item.response.reason : undefined,
      })),
    ready: totalRequired === 0 || completedRequired >= totalRequired,
  }
}

function normalizeNullableId(value: number | null | undefined): number | null {
  if (typeof value !== 'number' || !Number.isInteger(value) || value <= 0) return null
  return value
}

function buildFallbackResolverContext(
  accountId: number | null,
  strategyModelId: number | null,
  tradeId: number | null
): TradeChecklistResolverContext {
  return {
    requested_account_id: accountId,
    requested_strategy_model_id: strategyModelId,
    resolved_scope: null,
    resolved_checklist_id: null,
    resolved_account_id: null,
    resolved_strategy_model_id: null,
    trade_id: tradeId,
  }
}

type ServerReadinessReason = NonNullable<TradeChecklistResponsePayload['failing_rules']>[number]

export const useTradeRulesStore = defineStore('tradeRules', () => {
  const requestManager = createRequestManager()
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const checklist = ref<Checklist | null>(null)
  const items = ref<TradeChecklistItemWithResponse[]>([])
  const archivedResponses = ref<TradeChecklistResponseRecord[]>([])
  const readiness = ref<TradeChecklistReadiness>(emptyReadiness())
  const serverReadiness = ref<TradeChecklistReadiness>(emptyReadiness())
  const serverFailingRules = ref<ServerReadinessReason[]>([])
  const resolverContext = ref<TradeChecklistResolverContext | null>(null)
  const executionSnapshot = ref<TradeChecklistExecutionSnapshot | null>(null)
  const submitAttempted = ref(false)
  const contextTradeId = ref<number | null>(null)
  const contextAccountId = ref<number | null>(null)
  const contextStrategyModelId = ref<number | null>(null)
  let persistTimer: ReturnType<typeof setTimeout> | null = null
  let queuedPersist = false
  let loadRequestVersion = 0

  const requiredItems = computed(() =>
    items.value.filter((item) => item.required && item.is_active)
  )
  const optionalItems = computed(() =>
    items.value.filter((item) => !item.required && item.is_active)
  )
  const enforcementMode = computed(() => checklist.value?.enforcement_mode ?? 'soft')
  const isStrict = computed(() => enforcementMode.value === 'strict')
  const checklistIncomplete = computed(() => !readiness.value.ready)
  const strictSubmitBlocked = computed(() =>
    isStrict.value
      && hasChecklist.value
      && !serverReadiness.value.ready
  )
  const serverReadinessMismatch = computed(() =>
    readiness.value.ready !== serverReadiness.value.ready
      || readiness.value.completed_required !== serverReadiness.value.completed_required
      || readiness.value.total_required !== serverReadiness.value.total_required
  )
  const serverReadinessReasons = computed(() => {
    if (serverFailingRules.value.length > 0) {
      return serverFailingRules.value.map((entry) => ({
        checklist_item_id: entry.checklist_item_id,
        title: entry.title,
        category: entry.category,
        reason: entry.reason ?? 'Rule requirement not met by server evaluation.',
      }))
    }

    const fromReadiness = serverReadiness.value.missing_required.map((entry) => ({
      checklist_item_id: entry.checklist_item_id,
      title: entry.title,
      category: entry.category,
      reason: entry.reason ?? 'Rule requirement not met by server evaluation.',
    }))
    if (fromReadiness.length > 0) return fromReadiness

    const snapshot = executionSnapshot.value
    if (!snapshot || snapshot.failed_rule_ids.length === 0) return []

    return snapshot.failed_rule_ids.map((ruleId, index) => ({
      checklist_item_id: ruleId,
      title: snapshot.failed_rule_titles[index] || `Rule #${ruleId}`,
      category: 'Checklist',
      reason: 'Failed in server execution artifact.',
    }))
  })
  const hasChecklist = computed(() => checklist.value !== null)
  const resolverContextCurrent = computed(() => {
    const context = resolverContext.value
    if (!context) return false
    return context.requested_account_id === contextAccountId.value
      && context.requested_strategy_model_id === contextStrategyModelId.value
      && context.trade_id === contextTradeId.value
  })

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
    serverReadiness.value = emptyReadiness()
    serverFailingRules.value = []
    resolverContext.value = null
    executionSnapshot.value = null
    error.value = null
    contextTradeId.value = null
    contextAccountId.value = null
    contextStrategyModelId.value = null
    clearPersistTimer()
    queuedPersist = false
  }

  function applyPayload(payload: TradeChecklistResponsePayload) {
    checklist.value = payload.responses.checklist
    items.value = payload.responses.items
    archivedResponses.value = payload.responses.archived_responses ?? []
    readiness.value = buildReadiness(payload.responses.items)
    serverReadiness.value = payload.readiness
    serverFailingRules.value = payload.failing_rules ?? []
    resolverContext.value = payload.context ?? buildFallbackResolverContext(
      contextAccountId.value,
      contextStrategyModelId.value,
      contextTradeId.value
    )
    executionSnapshot.value = payload.execution_snapshot ?? null
  }

  function rebuildReadinessLocal() {
    readiness.value = buildReadiness(items.value)
  }

  function applyServerReadinessPreview(payload: {
    readiness?: TradeChecklistReadiness
    failing_rules?: TradeChecklistResponsePayload['failing_rules']
  } | null | undefined) {
    if (!payload?.readiness) return
    serverReadiness.value = payload.readiness
    serverFailingRules.value = payload.failing_rules ?? []
  }

  async function loadForContext(
    accountId: number | null,
    strategyModelId: number | null,
    tradeId: number | null
  ) {
    loading.value = true
    error.value = null
    contextTradeId.value = normalizeNullableId(tradeId)
    contextAccountId.value = normalizeNullableId(accountId)
    contextStrategyModelId.value = normalizeNullableId(strategyModelId)
    clearPersistTimer()

    const requestVersion = ++loadRequestVersion
    const requestParams = {
      trade_id: contextTradeId.value ?? undefined,
      account_id: contextAccountId.value ?? undefined,
      strategy_model_id: contextStrategyModelId.value ?? undefined,
    }
    const fingerprint = stableSerialize(requestParams)

    try {
      const response = await requestManager.run({
        key: 'loadForContext',
        fingerprint,
        cacheKey: `tradeChecklist:resolve:${fingerprint}`,
        cacheTtlMs: 1_500,
        execute: async ({ signal }) => {
          const { data } = await api.get<TradeChecklistResponsePayload>('/trade-rules/resolve', {
            params: requestParams,
            signal,
          })
          return data
        },
      })
      if (response.stale || requestVersion !== loadRequestVersion) return
      const data = response.value

      applyPayload(data)
    } catch (err) {
      if (isAbortError(err)) {
        return
      }
      if (requestVersion !== loadRequestVersion) return
      const normalized = normalizeApiError(err)

      checklist.value = null
      items.value = []
      archivedResponses.value = []
      readiness.value = emptyReadiness()
      serverReadiness.value = emptyReadiness()
      serverFailingRules.value = []
      resolverContext.value = buildFallbackResolverContext(
        contextAccountId.value,
        contextStrategyModelId.value,
        contextTradeId.value
      )
      executionSnapshot.value = null
      error.value = normalized.message
    } finally {
      if (requestVersion === loadRequestVersion) {
        loading.value = false
      }
    }
  }

  async function loadForCreate(accountId: number | null, strategyModelId: number | null = null) {
    await loadForContext(accountId, strategyModelId, null)
  }

  async function loadForTrade(
    tradeId: number,
    accountId: number | null = null,
    strategyModelId: number | null = null
  ) {
    await loadForContext(accountId, strategyModelId, tradeId)
  }

  function buildWritePayload(): ResponseWriteRow[] {
    return items.value.map((item) => ({
      checklist_item_id: item.id,
      value: item.response.value,
    }))
  }

  async function persistNow(tradeIdOverride?: number) {
    const activeTradeId = normalizeNullableId(tradeIdOverride) ?? contextTradeId.value
    if (!activeTradeId || !checklist.value) return

    if (saving.value) {
      queuedPersist = true
      return
    }

    saving.value = true
    error.value = null
    try {
      const { data } = await api.put<TradeChecklistResponsePayload>(
        `/trades/${activeTradeId}/rule-responses`,
        {
          account_id: contextAccountId.value,
          strategy_model_id: contextStrategyModelId.value,
          responses: buildWritePayload(),
        }
      )
      applyPayload(data)
    } catch (err) {
      error.value = normalizeApiError(err).message
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
    serverReadiness,
    resolverContext,
    executionSnapshot,
    resolverContextCurrent,
    submitAttempted,
    contextTradeId,
    contextAccountId,
    contextStrategyModelId,
    requiredItems,
    optionalItems,
    enforcementMode,
    isStrict,
    checklistIncomplete,
    strictSubmitBlocked,
    serverReadinessMismatch,
    serverReadinessReasons,
    hasChecklist,
    resetState,
    loadForContext,
    loadForCreate,
    loadForTrade,
    applyServerReadinessPreview,
    updateResponse,
    markSubmitAttempted,
    clearSubmitAttempted,
    persistForTrade,
  }
})
