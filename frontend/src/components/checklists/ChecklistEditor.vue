<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ChevronDown, Plus } from 'lucide-vue-next'
import BaseSelect from '@/components/form/BaseSelect.vue'
import type { Checklist, ChecklistItem, ChecklistItemType } from '@/types/checklist'
import AddChecklistItemModal from '@/components/checklists/AddChecklistItemModal.vue'
import ChecklistItemRow from '@/components/checklists/ChecklistItemRow.vue'

const props = withDefaults(
  defineProps<{
    checklist: Checklist | null
    items: ChecklistItem[]
    loading?: boolean
    saving?: boolean
    accounts: Array<{ id: number; name: string }>
  }>(),
  {
    loading: false,
    saving: false,
  }
)

const emit = defineEmits<{
  (event: 'update-checklist', checklistId: number, payload: Record<string, unknown>): void
  (event: 'duplicate-checklist', checklistId: number): void
  (event: 'remove-checklist', checklistId: number): void
  (event: 'create-item', checklistId: number, payload: {
    title: string
    type: ChecklistItemType
    required?: boolean
    category?: string
    help_text?: string | null
    config?: Record<string, unknown>
    is_active?: boolean
  }): void
  (event: 'update-item', itemId: number, payload: Record<string, unknown>): void
  (event: 'remove-item', checklistId: number, itemId: number): void
  (event: 'reorder-items', checklistId: number, orderedIds: number[]): void
}>()

const checklistForm = reactive({
  name: '',
  scope: 'global',
  enforcement_mode: 'soft',
  account_id: '',
})

const addItemOpen = ref(false)
const draggedItemId = ref<number | null>(null)
const expandedRuleId = ref<number | null>(null)
const showChecklistSettings = ref(false)

const disciplineCategories = ['Context', 'Setup', 'Risk', 'Execution', 'Psychology'] as const
const collapsedCategories = reactive<Record<string, boolean>>({
  Context: false,
  Setup: false,
  Risk: false,
  Execution: false,
  Psychology: false,
})

const scopeOptions = [
  { label: 'Global', value: 'global' },
  { label: 'Account', value: 'account' },
  { label: 'Strategy', value: 'strategy' },
]

const accountOptions = computed(() =>
  props.accounts.map((account) => ({
    label: account.name,
    value: String(account.id),
  }))
)

const modeLabel = computed(() => checklistForm.enforcement_mode === 'strict' ? 'Strict' : 'Soft')

const categoryOptions = computed(() => {
  const values = new Set<string>(disciplineCategories)
  for (const item of props.items) {
    if (item.category.trim()) {
      values.add(normalizeCategory(item.category))
    }
  }
  return Array.from(values)
})

const sortedItems = computed(() =>
  props.items
    .slice()
    .sort((left, right) => left.order_index - right.order_index || left.id - right.id)
)

const groupedItems = computed<Record<string, ChecklistItem[]>>(() => {
  const groups: Record<string, ChecklistItem[]> = {}
  for (const category of disciplineCategories) {
    groups[category] = []
  }

  for (const item of sortedItems.value) {
    const normalizedCategory = normalizeCategory(item.category)
    groups[normalizedCategory] ??= []
    groups[normalizedCategory].push(item)
  }

  return groups
})

const totalRules = computed(() => sortedItems.value.filter((item) => item.is_active).length)
const requiredRules = computed(() => sortedItems.value.filter((item) => item.is_active && item.required).length)

watch(
  () => props.checklist,
  (value) => {
    checklistForm.name = value?.name ?? ''
    checklistForm.scope = value?.scope ?? 'global'
    checklistForm.enforcement_mode = value?.enforcement_mode ?? 'soft'
    checklistForm.account_id = value?.account_id ? String(value.account_id) : ''
    expandedRuleId.value = null
  },
  { immediate: true }
)

function normalizeCategory(value: string | undefined | null): string {
  const normalized = (value ?? '').trim().toLowerCase()
  if (!normalized) return 'Context'

  if (normalized.includes('psych') || normalized.includes('emotion') || normalized.includes('mind')) {
    return 'Psychology'
  }
  if (normalized.includes('execut')) {
    return 'Execution'
  }
  if (normalized.includes('risk')) {
    return 'Risk'
  }
  if (normalized.includes('setup') || normalized.includes('trigger') || normalized.includes('entry')) {
    return 'Setup'
  }
  if (normalized.includes('context') || normalized.includes('structure') || normalized.includes('news') || normalized.includes('session')) {
    return 'Context'
  }

  return 'Setup'
}

function setEnforcement(mode: 'soft' | 'strict') {
  checklistForm.enforcement_mode = mode
  saveChecklist()
}

function saveChecklist() {
  const checklist = props.checklist
  if (!checklist) return

  emit('update-checklist', checklist.id, {
    name: checklistForm.name.trim() || checklist.name,
    scope: checklistForm.scope,
    enforcement_mode: checklistForm.enforcement_mode,
    account_id: checklistForm.scope === 'account'
      ? (checklistForm.account_id ? Number(checklistForm.account_id) : null)
      : null,
    is_active: checklist.is_active,
  })
}

function onDragStart(itemId: number) {
  draggedItemId.value = itemId
}

function onDrop(targetItemId: number) {
  const dragged = draggedItemId.value
  draggedItemId.value = null
  if (!props.checklist || !dragged || dragged === targetItemId) return

  const ordered = sortedItems.value.map((item) => item.id)
  const fromIndex = ordered.findIndex((id) => id === dragged)
  const toIndex = ordered.findIndex((id) => id === targetItemId)
  if (fromIndex < 0 || toIndex < 0) return

  const next = ordered.slice()
  const [moved] = next.splice(fromIndex, 1)
  if (!moved) return
  next.splice(toIndex, 0, moved)
  emit('reorder-items', props.checklist.id, next)
}

function onCreateItem(payload: {
  title: string
  type: ChecklistItemType
  required?: boolean
  category?: string
  help_text?: string | null
  config?: Record<string, unknown>
  is_active?: boolean
}) {
  if (!props.checklist) return
  emit('create-item', props.checklist.id, {
    ...payload,
    category: normalizeCategory(payload.category),
  })
  addItemOpen.value = false
}

function toggleCategory(category: string) {
  collapsedCategories[category] = !collapsedCategories[category]
}

function isCategoryCollapsed(category: string) {
  return Boolean(collapsedCategories[category])
}

function toggleRuleExpand(ruleId: number) {
  expandedRuleId.value = expandedRuleId.value === ruleId ? null : ruleId
}
</script>

<template>
  <section class="panel checklist-editor-panel">
    <template v-if="checklist">
      <div class="checklist-editor-content">
        <header class="checklist-control-header">
          <div class="checklist-control-summary">
            <h2>Pre-Trade Control System</h2>
            <p>Mode: <strong>{{ modeLabel }}</strong></p>
            <p>{{ totalRules }} Rules • {{ requiredRules }} Required</p>
          </div>

          <div class="checklist-control-actions">
            <button type="button" class="btn btn-primary px-3 py-2 text-sm" @click="addItemOpen = true">
              <Plus class="h-4 w-4" />
              Add Rule
            </button>
            <button type="button" class="btn btn-ghost px-3 py-2 text-sm" @click="emit('duplicate-checklist', checklist.id)">
              Duplicate
            </button>
            <button type="button" class="btn btn-ghost is-danger px-3 py-2 text-sm" @click="emit('remove-checklist', checklist.id)">
              Archive
            </button>
          </div>
        </header>

        <section class="checklist-editor-header compact">
          <input
            v-model="checklistForm.name"
            type="text"
            class="checklist-inline-name"
            placeholder="Checklist name"
            @blur="saveChecklist"
            @keydown.enter.prevent="saveChecklist"
          >

          <div class="checklist-control-row">
            <div class="checklist-enforcement-toggle" role="group" aria-label="Enforcement mode">
              <button
                type="button"
                class="checklist-enforcement-option"
                :class="{ active: checklistForm.enforcement_mode === 'soft' }"
                @click="setEnforcement('soft')"
              >
                Soft
              </button>
              <button
                type="button"
                class="checklist-enforcement-option"
                :class="{ active: checklistForm.enforcement_mode === 'strict' }"
                @click="setEnforcement('strict')"
              >
                Strict
              </button>
            </div>

            <button type="button" class="btn btn-ghost px-3 py-2 text-sm" @click="showChecklistSettings = !showChecklistSettings">
              {{ showChecklistSettings ? 'Hide Settings' : 'Checklist Settings' }}
            </button>

            <span class="checklist-autosave-note">Autosave on change</span>
          </div>

          <div v-if="showChecklistSettings" class="checklist-meta-row">
            <BaseSelect
              v-model="checklistForm.scope"
              label="Scope"
              :options="scopeOptions"
              size="sm"
              @update:model-value="saveChecklist"
            />

            <BaseSelect
              v-if="checklistForm.scope === 'account'"
              v-model="checklistForm.account_id"
              label="Account"
              :options="accountOptions"
              size="sm"
              @update:model-value="saveChecklist"
            />
          </div>
        </section>

        <section class="checklist-items-section slim">
          <div v-if="loading" class="space-y-3">
            <div class="skeleton-shimmer h-16 rounded-xl" />
            <div class="skeleton-shimmer h-16 rounded-xl" />
          </div>

          <div v-else-if="sortedItems.length === 0" class="panel p-4 text-sm muted">
            No rules yet. Add the first discipline gate.
          </div>

          <div v-else class="checklist-category-stack">
            <section
              v-for="category in disciplineCategories"
              :key="`category-${category}`"
              class="checklist-category-block"
            >
              <button
                type="button"
                class="checklist-category-head"
                @click="toggleCategory(category)"
              >
                <span class="checklist-category-title">{{ category }} ({{ groupedItems[category]?.length ?? 0 }})</span>
                <ChevronDown class="h-4 w-4" :class="{ 'is-collapsed': isCategoryCollapsed(category) }" />
              </button>

              <div v-show="!isCategoryCollapsed(category)" class="checklist-category-content">
                <ChecklistItemRow
                  v-for="item in groupedItems[category]"
                  :key="item.id"
                  :item="item"
                  :expanded="expandedRuleId === item.id"
                  :categories="categoryOptions"
                  @toggle-expand="toggleRuleExpand"
                  @update="(itemId, payload) => emit('update-item', itemId, payload)"
                  @remove="emit('remove-item', checklist.id, $event)"
                  @drag-start="onDragStart"
                  @drag-over="() => undefined"
                  @drop="onDrop"
                />

                <p v-if="(groupedItems[category]?.length ?? 0) === 0" class="section-note">No rules.</p>
              </div>
            </section>
          </div>
        </section>
      </div>

      <AddChecklistItemModal
        :open="addItemOpen"
        :categories="categoryOptions"
        @close="addItemOpen = false"
        @create="onCreateItem"
      />
    </template>

    <div v-else class="checklist-editor-empty">
      <p class="section-title">Select a checklist</p>
      <p class="section-note">Create or choose one from the library to begin editing.</p>
    </div>
  </section>
</template>
