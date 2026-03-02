<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import RulesLibraryPanel from '@/components/rules/RulesLibraryPanel.vue'
import RulesBoardEditor from '@/components/rules/RulesBoardEditor.vue'
import { useRulesStore } from '@/stores/rulesStore'
import { useAccountStore } from '@/stores/accountStore'
import { useTradeStore } from '@/stores/tradeStore'
import { useUiStore } from '@/stores/uiStore'
import type { ChecklistItemType, ChecklistRuleDefinition, ChecklistScope } from '@/types/rules'
import { normalizeApiError } from '@/utils/apiError'

const checklistStore = useRulesStore()
const accountStore = useAccountStore()
const tradeStore = useTradeStore()
const uiStore = useUiStore()

const {
  checklists,
  selectedChecklistId,
  selectedChecklist,
  selectedItems,
  itemsByChecklist,
  loading,
  saving,
} = storeToRefs(checklistStore)
const { accounts } = storeToRefs(accountStore)
const { strategyModels } = storeToRefs(tradeStore)

const scopeFilter = ref<'' | ChecklistScope>('')
const search = ref('')
const unsavedChanges = ref(false)
const lastSavedAt = ref<string | null>(null)
let filterTimer: ReturnType<typeof setTimeout> | null = null
const bootstrapAttempted = ref(false)

const accountRows = computed(() =>
  accounts.value.map((account) => ({ id: account.id, name: account.name }))
)

const strategyModelRows = computed(() =>
  strategyModels.value.map((strategy) => ({ id: strategy.id, name: strategy.name }))
)

const checklistStatsByChecklistId = computed<Record<number, {
  total: number
  required: number | null
}>>(() => {
  const stats: Record<number, { total: number; required: number | null }> = {}

  for (const checklist of checklists.value) {
    const loadedItems = itemsByChecklist.value[checklist.id]
    if (loadedItems) {
      const activeItems = loadedItems.filter((item) => item.is_active)
      stats[checklist.id] = {
        total: activeItems.length,
        required: activeItems.filter((item) => item.required).length,
      }
      continue
    }

    stats[checklist.id] = {
      total: checklist.active_items_count ?? 0,
      required: null,
    }
  }

  return stats
})

function errorMessage(error: unknown): string {
  return normalizeApiError(error).message
}

async function loadChecklists() {
  try {
    await checklistStore.fetchChecklists({
      scope: scopeFilter.value,
      search: search.value,
    })
    if (checklistStore.selectedChecklistId) {
      await checklistStore.fetchChecklistItems(checklistStore.selectedChecklistId)
    }
    await ensureStarterChecklistIfEmpty()
    await preloadChecklistStats()
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load rule sets',
      message: errorMessage(error),
    })
  }
}

function scheduleFilterReload() {
  if (filterTimer) {
    clearTimeout(filterTimer)
  }
  filterTimer = setTimeout(() => {
    void loadChecklists()
  }, 250)
}

watch(
  () => selectedChecklistId.value,
  (value) => {
    if (!value) return
    void checklistStore.fetchChecklistItems(value)
  }
)

watch(
  () => [scopeFilter.value, search.value],
  () => {
    scheduleFilterReload()
  }
)

async function createChecklist(payload: {
  name: string
  scope: ChecklistScope
  enforcement_mode: 'soft' | 'strict'
  account_id?: number | null
  strategy_model_id?: number | null
  is_active: boolean
}) {
  unsavedChanges.value = true
  try {
    await checklistStore.createChecklist(payload)
    if (checklistStore.selectedChecklistId) {
      await checklistStore.fetchChecklistItems(checklistStore.selectedChecklistId)
    }
    await preloadChecklistStats()
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
    uiStore.toast({ type: 'success', title: 'Rule set created' })
  } catch (error) {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to create rule set', message: errorMessage(error) })
  }
}

async function updateChecklist(checklistId: number, payload: Record<string, unknown>) {
  unsavedChanges.value = true
  try {
    await checklistStore.updateChecklist(checklistId, payload)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
  } catch (error) {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to update rule set', message: errorMessage(error) })
  }
}

async function duplicateChecklist(checklistId: number) {
  try {
    await checklistStore.duplicateChecklist(checklistId)
    await preloadChecklistStats()
    uiStore.toast({ type: 'success', title: 'Rule set duplicated' })
  } catch (error) {
    uiStore.toast({ type: 'error', title: 'Failed to duplicate rule set', message: errorMessage(error) })
  }
}

async function removeChecklist(checklistId: number) {
  const confirmed = await uiStore.askConfirmation({
    title: 'Archive rule set?',
    message: 'This rule set will be set inactive and hidden from default selection.',
    confirmText: 'Archive',
  })
  if (!confirmed) return

  try {
    await checklistStore.removeChecklist(checklistId)
    uiStore.toast({ type: 'success', title: 'Rule set archived' })
  } catch (error) {
    uiStore.toast({ type: 'error', title: 'Failed to archive rule set', message: errorMessage(error) })
  }
}

async function createItem(checklistId: number, payload: {
  title: string
  type: ChecklistItemType
  rule?: ChecklistRuleDefinition
  required?: boolean
  category?: string
  help_text?: string | null
  config?: Record<string, unknown>
  is_active?: boolean
}) {
  unsavedChanges.value = true
  try {
    await checklistStore.createItem(checklistId, payload)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
    uiStore.toast({ type: 'success', title: 'Rule added' })
  } catch (error) {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to add rule', message: errorMessage(error) })
  }
}

async function updateItem(itemId: number, payload: Record<string, unknown>) {
  unsavedChanges.value = true
  try {
    await checklistStore.updateItem(itemId, payload)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
  } catch (error) {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to update rule', message: errorMessage(error) })
  }
}

async function removeItem(checklistId: number, itemId: number) {
  const ruleTitle = selectedItems.value.find((item) => item.id === itemId)?.title ?? 'this rule'
  const confirmed = await uiStore.askConfirmation({
    title: 'Delete rule?',
    message: `This will permanently remove "${ruleTitle}".`,
    confirmText: 'Delete',
  })
  if (!confirmed) return

  unsavedChanges.value = true
  try {
    await checklistStore.removeItem(checklistId, itemId)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
    uiStore.toast({ type: 'success', title: 'Rule deleted' })
  } catch (error) {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to delete rule', message: errorMessage(error) })
  }
}

async function reorderItems(checklistId: number, orderedIds: number[]) {
  unsavedChanges.value = true
  try {
    await checklistStore.reorderItems(checklistId, orderedIds)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
  } catch (error) {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to reorder rules', message: errorMessage(error) })
  }
}

async function ensureStarterChecklistIfEmpty() {
  if (bootstrapAttempted.value) return
  if (scopeFilter.value !== '' || search.value.trim() !== '') return
  if (checklists.value.length > 0) return

  bootstrapAttempted.value = true

  try {
    const created = await checklistStore.createChecklist({
      name: 'Default Trading Rules',
      scope: 'global',
      enforcement_mode: 'soft',
      is_active: true,
    })

    const starterItems: Array<{
      title: string
      type: ChecklistItemType
      required: boolean
      category: string
      help_text?: string
      config?: Record<string, unknown>
    }> = [
      {
        title: 'Market regime matches setup',
        type: 'checkbox',
        required: true,
        category: 'Before Trading',
        help_text: 'Pass only when current regime supports the planned setup.',
      },
      {
        title: 'No high-impact news conflict',
        type: 'checkbox',
        required: true,
        category: 'Before Trading',
        help_text: 'No planned entry within your defined high-impact news window.',
      },
      {
        title: 'Daily risk available (drawdown check)',
        type: 'checkbox',
        required: true,
        category: 'Before Trading',
        help_text: 'Account is above stop-for-day and drawdown limits.',
      },
      {
        title: 'Emotional readiness score (1-5)',
        type: 'scale',
        required: true,
        category: 'Before Trading',
        config: {
          min: 1,
          max: 5,
          labels: { 1: 'Distracted', 3: 'Neutral', 5: 'Calm/Focused' },
        },
      },
      {
        title: 'Setup + thesis recorded (1 line)',
        type: 'checkbox',
        required: true,
        category: 'Before Trading',
        help_text: 'Single-line setup and thesis is written before entry.',
      },
      {
        title: 'Risk per trade (%)',
        type: 'number',
        required: true,
        category: 'During Trading',
        config: {
          min: 0.1,
          max: 2,
          step: 0.1,
          unit: '%',
          comparator: '<=',
          threshold: 1,
          auto: 'risk_engine',
          auto_metric: 'risk_percent',
          risk_linked: true,
          weight: 'hard',
        },
      },
      {
        title: 'Position size calculated from stop distance',
        type: 'checkbox',
        required: true,
        category: 'During Trading',
      },
      {
        title: 'Stop-loss at invalidation before/at entry',
        type: 'checkbox',
        required: true,
        category: 'During Trading',
      },
      {
        title: 'Target + R multiple defined before entry (R)',
        type: 'number',
        required: true,
        category: 'During Trading',
        config: {
          min: 0.5,
          max: 10,
          step: 0.1,
          unit: 'R',
          comparator: '>=',
          threshold: 1,
          weight: 'hard',
        },
      },
      {
        title: 'No revenge/risk increase after loss',
        type: 'checkbox',
        required: true,
        category: 'During Trading',
      },
      {
        title: 'Max trades / stop-for-day rule active',
        type: 'checkbox',
        required: true,
        category: 'During Trading',
      },
      {
        title: 'Plan followed (yes/no)',
        type: 'checkbox',
        required: true,
        category: 'After Trading',
      },
      {
        title: 'Plan adherence score (%)',
        type: 'number',
        required: true,
        category: 'After Trading',
        config: {
          min: 0,
          max: 100,
          step: 5,
          unit: '%',
        },
      },
      {
        title: 'Execution timing quality score (1-5)',
        type: 'scale',
        required: true,
        category: 'After Trading',
        config: {
          min: 1,
          max: 5,
          labels: { 1: 'Poor', 3: 'Average', 5: 'Excellent' },
        },
      },
      {
        title: 'Screenshot + annotations saved',
        type: 'checkbox',
        required: true,
        category: 'After Trading',
      },
      {
        title: 'Mistake category selected',
        type: 'dropdown',
        required: true,
        category: 'After Trading',
        config: {
          options: ['No Mistake', 'Execution', 'Risk', 'Discipline', 'Setup'],
        },
      },
      {
        title: 'Repeat/change actions logged (0-2)',
        type: 'number',
        required: true,
        category: 'After Trading',
        config: {
          min: 0,
          max: 2,
          step: 1,
          comparator: '>=',
          threshold: 2,
        },
      },
      {
        title: 'Next-session rule update status',
        type: 'dropdown',
        required: false,
        category: 'After Trading',
        config: {
          options: ['Not needed', 'Updated', 'Pending'],
        },
      },
    ]

    for (const item of starterItems) {
      await checklistStore.createItem(created.id, {
        title: item.title,
        type: item.type,
        required: item.required,
        category: item.category,
        help_text: item.help_text ?? null,
        config: item.config ?? {},
        is_active: true,
      })
    }

    await checklistStore.fetchChecklistItems(created.id)
    uiStore.toast({
      type: 'info',
      title: 'Starter rule set created',
      message: 'You can edit or replace it anytime.',
    })
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Failed to create starter rule set',
      message: errorMessage(error),
    })
  }
}

async function preloadChecklistStats() {
  const missingChecklistIds = checklists.value
    .map((checklist) => checklist.id)
    .filter((checklistId) => !itemsByChecklist.value[checklistId])

  if (missingChecklistIds.length === 0) return

  await Promise.all(
    missingChecklistIds.map((checklistId) =>
      checklistStore.fetchChecklistItems(checklistId).catch(() => undefined)
    )
  )
}

onMounted(async () => {
  try {
    await Promise.all([
      accountStore.fetchAccounts(),
      tradeStore.fetchDictionaries(),
    ])
    await loadChecklists()
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Failed to initialize rules builder',
      message: errorMessage(error),
    })
  }
})
</script>

<template>
  <section class="checklist-premium-page">
    <div class="checklist-premium-layout">
      <RulesLibraryPanel
        :checklists="checklists"
        :selected-checklist-id="selectedChecklistId"
        :scope-filter="scopeFilter"
        :search="search"
        :loading="loading"
        :saving="saving"
        :accounts="accountRows"
        :strategy-models="strategyModelRows"
        :stats-by-checklist-id="checklistStatsByChecklistId"
        @select="checklistStore.setSelectedChecklist"
        @scope-change="scopeFilter = $event"
        @search-change="search = String($event)"
        @create="createChecklist"
        class="checklist-premium-library"
      />

      <RulesBoardEditor
        :checklist="selectedChecklist"
        :items="selectedItems"
        :loading="loading"
        :saving="saving"
        :unsaved-changes="unsavedChanges"
        :last-saved-at="lastSavedAt"
        :accounts="accountRows"
        :strategy-models="strategyModelRows"
        @update-checklist="updateChecklist"
        @duplicate-checklist="duplicateChecklist"
        @remove-checklist="removeChecklist"
        @create-item="createItem"
        @update-item="updateItem"
        @remove-item="removeItem"
        @reorder-items="reorderItems"
        class="checklist-premium-editor"
      />
    </div>
  </section>
</template>

<style scoped>
.checklist-premium-page {
  position: relative;
  display: block;
  border-radius: 18px;
  padding: 1rem;
  overflow: clip;
  background:
    radial-gradient(circle at 22% -6%, color-mix(in srgb, var(--primary-soft) 28%, transparent 72%), transparent 42%),
    radial-gradient(circle at 80% 3%, color-mix(in srgb, var(--primary-soft) 16%, transparent 84%), transparent 34%),
    color-mix(in srgb, var(--bg-soft) 74%, var(--bg) 26%);
}

.checklist-premium-page::before {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  opacity: 0.14;
  background-image:
    linear-gradient(to right, color-mix(in srgb, var(--border) 66%, transparent 34%) 1px, transparent 1px),
    linear-gradient(to bottom, color-mix(in srgb, var(--border) 62%, transparent 38%) 1px, transparent 1px);
  background-size: 52px 52px;
}

.checklist-premium-layout {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}

.checklist-premium-library {
  position: static;
}

@media (max-width: 760px) {
  .checklist-premium-page {
    padding: 0.8rem;
  }
}
</style>
