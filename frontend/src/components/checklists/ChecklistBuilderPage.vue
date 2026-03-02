<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ChecklistListPanel from '@/components/checklists/ChecklistListPanel.vue'
import ChecklistEditor from '@/components/checklists/ChecklistEditor.vue'
import { useChecklistStore } from '@/stores/checklistStore'
import { useAccountStore } from '@/stores/accountStore'
import { useUiStore } from '@/stores/uiStore'
import type { ChecklistItemType, ChecklistScope } from '@/types/checklist'

const checklistStore = useChecklistStore()
const accountStore = useAccountStore()
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

const scopeFilter = ref<'' | ChecklistScope>('')
const search = ref('')
let filterTimer: ReturnType<typeof setTimeout> | null = null
const bootstrapAttempted = ref(false)

const accountRows = computed(() =>
  accounts.value.map((account) => ({ id: account.id, name: account.name }))
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
  is_active: boolean
}) {
  try {
    await checklistStore.createChecklist(payload)
    if (checklistStore.selectedChecklistId) {
      await checklistStore.fetchChecklistItems(checklistStore.selectedChecklistId)
    }
    await preloadChecklistStats()
    uiStore.toast({ type: 'success', title: 'Checklist created' })
  } catch {
    uiStore.toast({ type: 'error', title: 'Failed to create checklist' })
  }
}

async function updateChecklist(checklistId: number, payload: Record<string, unknown>) {
  try {
    await checklistStore.updateChecklist(checklistId, payload)
  } catch {
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
  try {
    await checklistStore.createItem(checklistId, payload)
    uiStore.toast({ type: 'success', title: 'Rule added' })
  } catch {
    uiStore.toast({ type: 'error', title: 'Failed to add rule' })
  }
}

async function updateItem(itemId: number, payload: Record<string, unknown>) {
  try {
    await checklistStore.updateItem(itemId, payload)
  } catch {
    uiStore.toast({ type: 'error', title: 'Failed to update rule' })
  }
}

async function removeItem(checklistId: number, itemId: number) {
  try {
    await checklistStore.removeItem(checklistId, itemId)
    uiStore.toast({ type: 'success', title: 'Rule archived' })
  } catch {
    uiStore.toast({ type: 'error', title: 'Failed to archive rule' })
  }
}

async function reorderItems(checklistId: number, orderedIds: number[]) {
  try {
    await checklistStore.reorderItems(checklistId, orderedIds)
  } catch {
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
        category: 'Context',
      },
      {
        title: 'No high-impact news conflict',
        type: 'checkbox',
        required: true,
        category: 'Context',
      },
      {
        title: 'Setup trigger is valid',
        type: 'checkbox',
        required: true,
        category: 'Setup',
      },
      {
        title: 'Risk per trade (%)',
        type: 'number',
        required: true,
        category: 'Risk',
        config: { min: 0.1, max: 2, step: 0.1, unit: '%' },
      },
      {
        title: 'Stop loss placed at invalidation',
        type: 'checkbox',
        required: true,
        category: 'Risk',
      },
      {
        title: 'Execution timing quality',
        type: 'dropdown',
        required: false,
        category: 'Execution',
        config: { options: ['A+', 'A', 'B', 'C'] },
      },
      {
        title: 'Emotional state before entry',
        type: 'scale',
        required: true,
        category: 'Psychology',
        config: {
          min: 1,
          max: 5,
          labels: { 1: 'Tilted', 3: 'Neutral', 5: 'Calm' },
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
    await accountStore.fetchAccounts()
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
  <div class="space-y-4 checklist-control-page">
    <header class="checklist-page-header">
      <h1 class="section-title">Pre-Trade Control System</h1>
      <p class="section-note">Discipline-first rule editor with fast execution flow.</p>
    </header>

    <div class="checklist-builder-layout">
      <ChecklistListPanel
        :checklists="checklists"
        :selected-checklist-id="selectedChecklistId"
        :scope-filter="scopeFilter"
        :search="search"
        :loading="loading"
        :saving="saving"
        :accounts="accountRows"
        :stats-by-checklist-id="checklistStatsByChecklistId"
        @select="checklistStore.setSelectedChecklist"
        @scope-change="scopeFilter = $event"
        @search-change="search = String($event)"
        @create="createChecklist"
      />

      <ChecklistEditor
        :checklist="selectedChecklist"
        :items="selectedItems"
        :loading="loading"
        :saving="saving"
        :accounts="accountRows"
        @update-checklist="updateChecklist"
        @duplicate-checklist="duplicateChecklist"
        @remove-checklist="removeChecklist"
        @create-item="createItem"
        @update-item="updateItem"
        @remove-item="removeItem"
        @reorder-items="reorderItems"
      />
    </div>
  </div>
</template>
