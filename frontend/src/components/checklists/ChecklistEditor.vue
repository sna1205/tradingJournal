<script setup lang="ts">
import { computed, reactive, ref, watch, type Component } from 'vue'
import { CircleCheck, Plus, ShieldCheck, Zap } from 'lucide-vue-next'
import BaseSelect from '@/components/form/BaseSelect.vue'
import type { Checklist, ChecklistItem, ChecklistItemType } from '@/types/checklist'
import AddChecklistItemModal from '@/components/checklists/AddChecklistItemModal.vue'
import ChecklistItemRow from '@/components/checklists/ChecklistItemRow.vue'
import {
  CHECKLIST_CATEGORIES,
  type ChecklistCategoryKey,
  checklistCategoryLabel,
  normalizeChecklistCategory,
} from '@/utils/checklistSchema'

type RuleLaneKey = 'before' | 'during' | 'after'

interface RuleLane {
  key: RuleLaneKey
  label: string
  icon: Component
  defaultCategory: ChecklistCategoryKey
}

const RULE_LANES: RuleLane[] = [
  { key: 'before', label: 'Before Trading', icon: ShieldCheck, defaultCategory: 'market_context' },
  { key: 'during', label: 'During Trading', icon: Zap, defaultCategory: 'risk_compliance' },
  { key: 'after', label: 'After Trading', icon: CircleCheck, defaultCategory: 'psychology' },
]

const props = withDefaults(
  defineProps<{
    checklist: Checklist | null
    items: ChecklistItem[]
    loading?: boolean
    saving?: boolean
    unsavedChanges?: boolean
    lastSavedAt?: string | null
    accounts: Array<{ id: number; name: string }>
    strategyModels: Array<{ id: number; name: string }>
  }>(),
  {
    loading: false,
    saving: false,
    unsavedChanges: false,
    lastSavedAt: null,
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
  strategy_model_id: '',
})

const addItemOpen = ref(false)
const addItemCategory = ref<ChecklistCategoryKey>('market_context')
const draggedItemId = ref<number | null>(null)
const expandedRuleId = ref<number | null>(null)

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

const strategyModelOptions = computed(() =>
  props.strategyModels.map((strategy) => ({
    label: strategy.name,
    value: String(strategy.id),
  }))
)

const sortedItems = computed(() =>
  props.items
    .slice()
    .sort((left, right) => left.order_index - right.order_index || left.id - right.id)
)

const totalRules = computed(() => sortedItems.value.filter((item) => item.is_active).length)
const requiredRules = computed(() => sortedItems.value.filter((item) => item.is_active && item.required).length)
const modeLabel = computed(() => (checklistForm.enforcement_mode === 'strict' ? 'Strict mode' : 'Soft mode'))

function laneForItem(item: ChecklistItem): RuleLaneKey {
  const category = normalizeChecklistCategory(item.category)
  const text = `${item.title} ${item.category ?? ''}`.toLowerCase()

  if (/journal|review|post|after|debrief/.test(text)) return 'after'
  if (item.type === 'text' && !item.required) return 'after'

  if (category === 'market_context' || category === 'setup_validation') return 'before'
  return 'during'
}

const laneItems = computed<Record<RuleLaneKey, ChecklistItem[]>>(() => {
  const result: Record<RuleLaneKey, ChecklistItem[]> = {
    before: [],
    during: [],
    after: [],
  }

  for (const item of sortedItems.value) {
    result[laneForItem(item)].push(item)
  }

  return result
})

watch(
  () => props.checklist,
  (value) => {
    checklistForm.name = value?.name ?? ''
    checklistForm.scope = value?.scope ?? 'global'
    checklistForm.enforcement_mode = value?.enforcement_mode ?? 'soft'
    checklistForm.account_id = value?.account_id ? String(value.account_id) : ''
    checklistForm.strategy_model_id = value?.strategy_model_id ? String(value.strategy_model_id) : ''
    expandedRuleId.value = null
  },
  { immediate: true }
)

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
    strategy_model_id: checklistForm.scope === 'strategy'
      ? (checklistForm.strategy_model_id ? Number(checklistForm.strategy_model_id) : null)
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

  const sourceItem = sortedItems.value.find((item) => item.id === dragged)
  const targetItem = sortedItems.value.find((item) => item.id === targetItemId)
  if (!sourceItem || !targetItem) return

  const sourceCategory = normalizeChecklistCategory(sourceItem.category)
  const targetCategory = normalizeChecklistCategory(targetItem.category)
  if (sourceCategory !== targetCategory) return

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

function openAddRuleForLane(lane: RuleLane) {
  addItemCategory.value = lane.defaultCategory
  addItemOpen.value = true
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
    category: checklistCategoryLabel(addItemCategory.value),
  })
  addItemOpen.value = false
}

function toggleRuleExpand(ruleId: number) {
  expandedRuleId.value = expandedRuleId.value === ruleId ? null : ruleId
}
</script>

<template>
  <section class="panel rules-builder-shell">
    <template v-if="checklist">
      <div class="rules-builder-content">
        <header class="rules-board-head">
          <div>
            <p class="kicker-label">My Trading Rules</p>
            <input
              v-model="checklistForm.name"
              type="text"
              class="rules-board-name"
              placeholder="Checklist name"
              @blur="saveChecklist"
              @keydown.enter.prevent="saveChecklist"
            >
            <p class="rules-board-summary">
              <span>{{ totalRules }} rules</span>
              <span aria-hidden="true">&middot;</span>
              <span>{{ requiredRules }} required</span>
              <span aria-hidden="true">&middot;</span>
              <span>{{ modeLabel }}</span>
            </p>
          </div>

          <div class="rules-board-head-actions">
            <button type="button" class="btn btn-ghost px-3 py-2 text-sm" @click="emit('duplicate-checklist', checklist.id)">
              Duplicate
            </button>
            <button type="button" class="btn btn-ghost is-danger px-3 py-2 text-sm" @click="emit('remove-checklist', checklist.id)">
              Archive
            </button>
          </div>
        </header>

        <section class="rules-board-controls">
          <div class="rules-board-controls-left">
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

            <BaseSelect
              v-if="checklistForm.scope === 'strategy'"
              v-model="checklistForm.strategy_model_id"
              label="Strategy"
              :options="strategyModelOptions"
              size="sm"
              @update:model-value="saveChecklist"
            />
          </div>

          <div class="rules-board-mode-toggle" role="group" aria-label="Enforcement mode">
            <button
              type="button"
              class="rules-board-mode-btn"
              :class="{ active: checklistForm.enforcement_mode === 'soft' }"
              @click="setEnforcement('soft')"
            >
              Soft mode
            </button>
            <button
              type="button"
              class="rules-board-mode-btn"
              :class="{ active: checklistForm.enforcement_mode === 'strict' }"
              @click="setEnforcement('strict')"
            >
              Strict mode
            </button>
          </div>
        </section>

        <section class="rules-board-grid" v-if="!loading">
          <article
            v-for="lane in RULE_LANES"
            :key="lane.key"
            class="rules-lane"
          >
            <header class="rules-lane-head">
              <p class="rules-lane-title">
                <component :is="lane.icon" class="h-4 w-4" />
                {{ lane.label }}
              </p>
              <button type="button" class="rules-lane-add" @click="openAddRuleForLane(lane)">
                <Plus class="h-4 w-4" />
                Add
              </button>
            </header>

            <div class="rules-lane-body">
              <ChecklistItemRow
                v-for="item in laneItems[lane.key]"
                :key="item.id"
                :item="item"
                :expanded="expandedRuleId === item.id"
                :categories="CHECKLIST_CATEGORIES.map((entry) => entry.label)"
                @toggle-expand="toggleRuleExpand"
                @update="(itemId, payload) => emit('update-item', itemId, payload)"
                @remove="emit('remove-item', checklist.id, $event)"
                @drag-start="onDragStart"
                @drag-over="() => undefined"
                @drop="onDrop"
              />

              <p v-if="laneItems[lane.key].length === 0" class="rules-lane-empty">
                No rules yet in this stage.
              </p>
            </div>
          </article>
        </section>

        <div v-else class="space-y-3">
          <div class="skeleton-shimmer h-20 rounded-xl" />
          <div class="skeleton-shimmer h-20 rounded-xl" />
        </div>
      </div>

      <AddChecklistItemModal
        :open="addItemOpen"
        :categories="CHECKLIST_CATEGORIES.map((entry) => entry.label)"
        @close="addItemOpen = false"
        @create="onCreateItem"
      />
    </template>

    <div v-else class="rules-builder-empty">
      <p class="section-title">Select a checklist</p>
      <p class="section-note">Create or choose one from the library to begin editing.</p>
    </div>
  </section>
</template>

<style scoped>
.rules-builder-shell {
  position: relative;
  padding: 1rem;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--border) 20%, transparent 80%) !important;
  background:
    linear-gradient(180deg, color-mix(in srgb, var(--primary-soft) 30%, transparent 70%) 0%, transparent 24%),
    color-mix(in srgb, #02070d 76%, var(--panel) 24%) !important;
  box-shadow: 0 14px 30px color-mix(in srgb, #000 26%, transparent 74%) !important;
}

.rules-builder-content {
  display: grid;
  gap: 0.9rem;
}

.rules-board-head {
  padding: 0.2rem 0.1rem 0.1rem;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.65rem;
}

.rules-board-name {
  margin-top: 0.25rem;
  min-height: 2.2rem;
  min-width: min(33rem, 100%);
  font-size: 1rem;
  font-weight: 700;
  padding: 0.48rem 0.64rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--border) 16%, transparent 84%);
  background: color-mix(in srgb, #01050a 78%, var(--panel) 22%);
}

.rules-board-summary {
  margin: 0.4rem 0 0;
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.74rem;
  color: var(--muted);
}

.rules-board-head-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.rules-board-controls {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 0.6rem;
  padding: 0.55rem 0.1rem 0.65rem;
  border-bottom: 1px solid color-mix(in srgb, var(--border) 16%, transparent 84%);
}

.rules-board-controls-left {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.45rem;
  width: min(420px, 100%);
}

.rules-board-mode-toggle {
  display: inline-flex;
  border-radius: 999px;
  padding: 0.18rem 0.2rem;
  background: color-mix(in srgb, #01050a 72%, var(--panel-soft) 28%);
  border: 1px solid color-mix(in srgb, var(--border) 14%, transparent 86%);
}

.rules-board-mode-btn {
  border: none;
  border-radius: 999px;
  min-height: 1.85rem;
  padding: 0 0.72rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--muted);
  background: transparent;
}

.rules-board-mode-btn.active {
  color: var(--text);
  background: color-mix(in srgb, var(--panel) 88%, transparent 12%);
}

.rules-board-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.74rem;
}

.rules-lane {
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--border) 14%, transparent 86%);
  background: color-mix(in srgb, #01040a 80%, var(--panel) 20%);
  padding: 0.72rem;
  display: grid;
  gap: 0.6rem;
  min-height: 17.5rem;
}

.rules-lane-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.rules-lane-title {
  margin: 0;
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.8rem;
  font-weight: 700;
}

.rules-lane-add {
  border: none;
  background: transparent;
  color: color-mix(in srgb, var(--text) 84%, var(--muted) 16%);
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.74rem;
  font-weight: 600;
}

.rules-lane-body {
  display: grid;
  gap: 0.42rem;
  align-content: start;
}

.rules-lane-empty {
  margin: 0;
  padding: 0.8rem 0.72rem;
  border-radius: 12px;
  border: 1px dashed color-mix(in srgb, var(--border) 16%, transparent 84%);
  color: var(--muted);
  font-size: 0.72rem;
}

.rules-builder-empty {
  display: grid;
  gap: 0.25rem;
}

@media (max-width: 1160px) {
  .rules-board-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 860px) {
  .rules-board-head {
    display: grid;
  }

  .rules-board-name {
    min-width: 0;
    width: 100%;
  }

  .rules-board-controls {
    display: grid;
  }

  .rules-board-controls-left {
    grid-template-columns: minmax(0, 1fr);
    width: 100%;
  }

  .rules-board-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
