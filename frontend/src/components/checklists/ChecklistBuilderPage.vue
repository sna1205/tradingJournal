<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ChecklistListPanel from '@/components/checklists/ChecklistListPanel.vue'
import ChecklistEditor from '@/components/checklists/ChecklistEditor.vue'
import { useChecklistStore } from '@/stores/checklistStore'
import { useAccountStore } from '@/stores/accountStore'
import { useTradeStore } from '@/stores/tradeStore'
import { useUiStore } from '@/stores/uiStore'
import type { ChecklistItemType, ChecklistScope } from '@/types/checklist'

const checklistStore = useChecklistStore()
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
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load checklists',
      message: 'Please refresh and try again.',
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
    uiStore.toast({ type: 'success', title: 'Checklist created' })
  } catch {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to create checklist' })
  }
}

async function updateChecklist(checklistId: number, payload: Record<string, unknown>) {
  unsavedChanges.value = true
  try {
    await checklistStore.updateChecklist(checklistId, payload)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
  } catch {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to update checklist' })
  }
}

async function duplicateChecklist(checklistId: number) {
  try {
    await checklistStore.duplicateChecklist(checklistId)
    await preloadChecklistStats()
    uiStore.toast({ type: 'success', title: 'Checklist duplicated' })
  } catch {
    uiStore.toast({ type: 'error', title: 'Failed to duplicate checklist' })
  }
}

async function removeChecklist(checklistId: number) {
  const confirmed = await uiStore.askConfirmation({
    title: 'Archive checklist?',
    message: 'This checklist will be set inactive and hidden from default selection.',
    confirmText: 'Archive',
  })
  if (!confirmed) return

  try {
    await checklistStore.removeChecklist(checklistId)
    uiStore.toast({ type: 'success', title: 'Checklist archived' })
  } catch {
    uiStore.toast({ type: 'error', title: 'Failed to archive checklist' })
  }
}

async function createItem(checklistId: number, payload: {
  title: string
  type: ChecklistItemType
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
  } catch {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to add rule' })
  }
}

async function updateItem(itemId: number, payload: Record<string, unknown>) {
  unsavedChanges.value = true
  try {
    await checklistStore.updateItem(itemId, payload)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
  } catch {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to update rule' })
  }
}

async function removeItem(checklistId: number, itemId: number) {
  unsavedChanges.value = true
  try {
    await checklistStore.removeItem(checklistId, itemId)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
    uiStore.toast({ type: 'success', title: 'Rule archived' })
  } catch {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to archive rule' })
  }
}

async function reorderItems(checklistId: number, orderedIds: number[]) {
  unsavedChanges.value = true
  try {
    await checklistStore.reorderItems(checklistId, orderedIds)
    lastSavedAt.value = new Date().toISOString()
    unsavedChanges.value = false
  } catch {
    unsavedChanges.value = true
    uiStore.toast({ type: 'error', title: 'Failed to reorder rules' })
  }
}

async function ensureStarterChecklistIfEmpty() {
  if (bootstrapAttempted.value) return
  if (scopeFilter.value !== '' || search.value.trim() !== '') return
  if (checklists.value.length > 0) return

  bootstrapAttempted.value = true

  try {
    const created = await checklistStore.createChecklist({
      name: 'Default Pre-Trade Checklist',
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
        title: 'Market context aligns with thesis',
        type: 'checkbox',
        required: true,
        category: 'Market Context',
      },
      {
        title: 'No high-impact news conflict',
        type: 'checkbox',
        required: true,
        category: 'Market Context',
      },
      {
        title: 'Setup trigger is valid',
        type: 'checkbox',
        required: true,
        category: 'Setup Validation',
      },
      {
        title: 'Risk per trade (%)',
        type: 'number',
        required: true,
        category: 'Risk & Compliance',
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
        title: 'Stop loss placed at invalidation',
        type: 'checkbox',
        required: true,
        category: 'Risk & Compliance',
      },
      {
        title: 'Execution timing quality',
        type: 'dropdown',
        required: false,
        category: 'Setup Validation',
        config: { options: ['A+', 'A', 'B', 'C'] },
      },
      {
        title: 'Emotional state before entry',
        type: 'scale',
        required: true,
        category: 'Psychology',
        config: {
          min: 1,
          max: 3,
          labels: { 1: 'Calm', 2: 'Neutral', 3: 'Tilted' },
        },
      },
      {
        title: 'One-line execution intent',
        type: 'text',
        required: false,
        category: 'Psychology',
        config: { maxLength: 240 },
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
      title: 'Starter checklist created',
      message: 'You can edit or replace it anytime.',
    })
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to create starter checklist',
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
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to initialize checklist builder',
    })
  }
})
</script>

<template>
  <section class="checklist-premium-page">
    <div class="checklist-premium-layout">
      <ChecklistListPanel
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

      <ChecklistEditor
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
    color-mix(in srgb, #02070d 70%, var(--bg) 30%);
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
