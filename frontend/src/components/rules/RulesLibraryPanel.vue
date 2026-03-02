<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ChevronDown, Plus } from 'lucide-vue-next'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseSelect from '@/components/form/BaseSelect.vue'
import type { Checklist, ChecklistEnforcementMode, ChecklistScope } from '@/types/rules'

const props = withDefaults(
  defineProps<{
    checklists: Checklist[]
    selectedChecklistId: number | null
    scopeFilter: '' | ChecklistScope
    search: string
    loading?: boolean
    saving?: boolean
    accounts: Array<{ id: number; name: string }>
    strategyModels: Array<{ id: number; name: string }>
    statsByChecklistId: Record<number, { total: number; required: number | null }>
  }>(),
  {
    loading: false,
    saving: false,
  }
)

const emit = defineEmits<{
  (event: 'select', checklistId: number): void
  (event: 'scope-change', scope: '' | ChecklistScope): void
  (event: 'search-change', value: string): void
  (event: 'create', payload: {
    name: string
    scope: ChecklistScope
    enforcement_mode: ChecklistEnforcementMode
    account_id?: number | null
    strategy_model_id?: number | null
    is_active: boolean
  }): void
}>()

const createOpen = ref(false)
const folded = ref(true)
const createForm = reactive({
  name: '',
  scope: 'global' as ChecklistScope,
  enforcement_mode: 'soft' as ChecklistEnforcementMode,
  account_id: '',
  strategy_model_id: '',
  is_active: true,
})

const scopeOptions = [
  { label: 'All scopes', value: '' },
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

function openCreate() {
  folded.value = false
  createOpen.value = true
  createForm.name = ''
  createForm.scope = 'global'
  createForm.enforcement_mode = 'soft'
  createForm.account_id = ''
  createForm.strategy_model_id = ''
  createForm.is_active = true
}

function submitCreate() {
  if (!createForm.name.trim()) return
  emit('create', {
    name: createForm.name.trim(),
    scope: createForm.scope,
    enforcement_mode: createForm.enforcement_mode,
    account_id: createForm.scope === 'account'
      ? (createForm.account_id ? Number(createForm.account_id) : null)
      : null,
    strategy_model_id: createForm.scope === 'strategy'
      ? (createForm.strategy_model_id ? Number(createForm.strategy_model_id) : null)
      : null,
    is_active: createForm.is_active,
  })
  createOpen.value = false
}

function enforcementClass(mode: ChecklistEnforcementMode) {
  return mode === 'strict' ? 'is-strict' : 'is-soft'
}

function statLabel(checklistId: number, field: 'total' | 'required') {
  const stats = props.statsByChecklistId[checklistId]
  if (!stats) return '-'
  if (field === 'required' && stats.required === null) return '-'
  return String(field === 'required' ? stats.required : stats.total)
}

const selectedChecklistName = computed(() =>
  normalizeRuleSetName(props.checklists.find((item) => item.id === props.selectedChecklistId)?.name ?? 'No rule set selected')
)

function normalizeRuleSetName(value: string): string {
  return value
    .replace(/\bChecklist\b/gi, 'Rule Set')
    .replace(/\bChecklists\b/gi, 'Rule Sets')
}
</script>

<template>
  <section class="panel checklist-library-shell" :class="{ folded }">
    <header class="checklist-library-headline">
      <div>
        <h2 class="checklist-library-title">Rules Library</h2>
        <p v-if="!folded" class="checklist-library-note">Execution control templates by scope.</p>
        <p v-else class="checklist-library-compact-note">{{ selectedChecklistName }}</p>
      </div>
      <div class="checklist-library-actions">
        <button type="button" class="checklist-library-fold" @click="folded = !folded">
          <ChevronDown class="h-4 w-4" :class="{ rotated: !folded }" />
        </button>
        <button type="button" class="checklist-library-new" @click="openCreate">
          <Plus class="h-4 w-4" />
          New Rule Set
        </button>
      </div>
    </header>

    <Transition name="fade">
      <div v-if="!folded || createOpen">
        <Transition name="fade" mode="out-in">
          <section v-if="createOpen" key="create-mode" class="checklist-library-create">
            <div class="rule-set-create-card">
              <div class="rule-set-create-head">
                <h3 class="section-title">Create New Rule Set</h3>
                <p class="section-note">Start with a clear scope, then add rules in the editor.</p>
              </div>

              <div class="grid grid-premium md:grid-cols-2">
                <BaseInput v-model="createForm.name" label="Rule Set Name" placeholder="e.g. London Session Rules" />

                <div class="rule-set-create-field">
                  <p class="rule-set-create-label">Scope</p>
                  <div class="rule-set-segmented">
                    <button
                      type="button"
                      class="rule-set-segmented-btn"
                      :class="{ active: createForm.scope === 'global' }"
                      @click="createForm.scope = 'global'"
                    >
                      Global
                    </button>
                    <button
                      type="button"
                      class="rule-set-segmented-btn"
                      :class="{ active: createForm.scope === 'account' }"
                      @click="createForm.scope = 'account'"
                    >
                      Account
                    </button>
                    <button
                      type="button"
                      class="rule-set-segmented-btn"
                      :class="{ active: createForm.scope === 'strategy' }"
                      @click="createForm.scope = 'strategy'"
                    >
                      Strategy
                    </button>
                  </div>
                </div>

                <div class="rule-set-create-field md:col-span-2">
                  <p class="rule-set-create-label">Mode</p>
                  <div class="rule-set-segmented compact">
                    <button
                      type="button"
                      class="rule-set-segmented-btn"
                      :class="{ active: createForm.enforcement_mode === 'soft' }"
                      @click="createForm.enforcement_mode = 'soft'"
                    >
                      Soft
                    </button>
                    <button
                      type="button"
                      class="rule-set-segmented-btn"
                      :class="{ active: createForm.enforcement_mode === 'strict' }"
                      @click="createForm.enforcement_mode = 'strict'"
                    >
                      Strict
                    </button>
                  </div>
                </div>

                <BaseSelect
                  v-if="createForm.scope === 'account'"
                  v-model="createForm.account_id"
                  label="Account"
                  :options="accountOptions"
                />
                <BaseSelect
                  v-if="createForm.scope === 'strategy'"
                  v-model="createForm.strategy_model_id"
                  label="Strategy"
                  :options="strategyModelOptions"
                />

                <label class="checklist-inline-toggle md:col-span-2">
                  <input v-model="createForm.is_active" type="checkbox">
                  Set as active
                </label>
              </div>
            </div>

            <div class="flex items-center justify-end gap-2 mt-3">
              <button type="button" class="btn btn-ghost px-3 py-2 text-sm" @click="createOpen = false">Cancel</button>
              <button type="button" class="btn btn-primary px-3 py-2 text-sm" :disabled="saving || !createForm.name.trim()" @click="submitCreate">
                {{ saving ? 'Creating...' : 'Create' }}
              </button>
            </div>
          </section>
          <div v-else key="library-mode">
            <section class="checklist-library-filters">
              <BaseInput
                :model-value="search"
                label="Search"
                placeholder="Find rule set"
                @update:model-value="emit('search-change', String($event))"
              />
              <BaseSelect
                :model-value="scopeFilter"
                label="Scope"
                :options="scopeOptions"
                @update:model-value="emit('scope-change', $event as '' | ChecklistScope)"
              />
            </section>

            <div v-if="loading" class="space-y-2 mt-3">
              <div class="skeleton-shimmer h-14 rounded-xl" />
              <div class="skeleton-shimmer h-14 rounded-xl" />
            </div>

            <section v-else class="checklist-library-list">
              <button
                v-for="item in checklists"
                :key="item.id"
                type="button"
                class="checklist-library-item"
                :class="{ active: item.id === selectedChecklistId }"
                @click="emit('select', item.id)"
              >
                <div class="checklist-library-item-top">
                  <p class="checklist-library-item-title">{{ normalizeRuleSetName(item.name) }}</p>
                  <span class="checklist-library-item-state" :class="{ on: item.is_active }">
                    {{ item.is_active ? 'Active' : 'Archived' }}
                  </span>
                </div>

                <div class="checklist-library-item-tags">
                  <span class="library-pill">{{ item.scope }}</span>
                  <span class="library-pill" :class="enforcementClass(item.enforcement_mode)">{{ item.enforcement_mode }}</span>
                </div>

                <div class="checklist-library-item-stats">
                  <span>Total {{ statLabel(item.id, 'total') }}</span>
                  <span>Required {{ statLabel(item.id, 'required') }}</span>
                </div>
              </button>

              <p v-if="checklists.length === 0" class="section-note mt-2">No rule sets found.</p>
            </section>
          </div>
        </Transition>
      </div>
    </Transition>
  </section>
</template>

<style scoped>
.checklist-library-shell {
  padding: 0.95rem;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--border) 22%, transparent 78%) !important;
  background:
    radial-gradient(circle at 18% -14%, color-mix(in srgb, var(--primary-soft) 30%, transparent 70%), transparent 45%),
    color-mix(in srgb, var(--panel-soft) 72%, var(--panel) 28%) !important;
  box-shadow: 0 12px 26px color-mix(in srgb, var(--text) 10%, transparent 90%);
}

.checklist-library-headline {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.7rem;
}

.checklist-library-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.checklist-library-title {
  margin: 0;
  font-size: 1.35rem;
  line-height: 1;
}

.checklist-library-note {
  margin: 0.32rem 0 0;
  font-size: 0.8rem;
  color: var(--muted);
  line-height: 1.35;
}

.checklist-library-compact-note {
  margin: 0.25rem 0 0;
  color: var(--muted);
  font-size: 0.76rem;
}

.checklist-library-fold {
  width: 2.2rem;
  min-height: 2.2rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  background: color-mix(in srgb, var(--panel-soft) 72%, var(--panel) 28%);
  color: var(--muted);
  display: inline-grid;
  place-items: center;
}

.checklist-library-fold .rotated {
  transform: rotate(180deg);
}

.checklist-library-new {
  min-height: 2.2rem;
  padding: 0 0.9rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--primary) 42%, var(--border) 58%);
  background: color-mix(in srgb, var(--primary-soft) 76%, transparent 24%);
  color: color-mix(in srgb, var(--primary) 84%, var(--text) 16%);
  font-size: 0.78rem;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}

.checklist-library-shell.folded {
  padding: 0.75rem 0.9rem;
}

.checklist-library-filters {
  margin-top: 0.8rem;
  display: grid;
  gap: 0.46rem;
}

.checklist-library-filters :deep(.field-label) {
  font-size: 0.68rem;
}

.checklist-library-list {
  margin-top: 0.72rem;
  display: grid;
  gap: 0.5rem;
}

.checklist-library-item {
  border: 1px solid color-mix(in srgb, var(--border) 22%, transparent 78%);
  border-radius: 13px;
  background: color-mix(in srgb, var(--panel-soft) 72%, var(--panel) 28%);
  padding: 0.66rem 0.7rem;
  text-align: left;
  display: grid;
  gap: 0.46rem;
  transition: border-color var(--transition-fast), transform var(--transition-fast);
}

.checklist-library-item:hover {
  transform: translateY(-1px);
  border-color: color-mix(in srgb, var(--primary) 34%, var(--border) 66%);
}

.checklist-library-item.active {
  border-color: color-mix(in srgb, var(--primary) 46%, var(--border) 54%);
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--primary) 20%, transparent 80%);
}

.checklist-library-item-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.45rem;
}

.checklist-library-item-title {
  margin: 0;
  font-size: 0.86rem;
  font-weight: 700;
  line-height: 1.2;
}

.checklist-library-item-state {
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  border-radius: 999px;
  padding: 0.12rem 0.45rem;
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.checklist-library-item-state.on {
  color: color-mix(in srgb, var(--primary) 82%, var(--text) 18%);
  border-color: color-mix(in srgb, var(--primary) 38%, var(--border) 62%);
}

.checklist-library-item-tags {
  display: inline-flex;
  align-items: center;
  gap: 0.32rem;
  flex-wrap: wrap;
}

.library-pill {
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  border-radius: 999px;
  padding: 0.12rem 0.42rem;
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--muted);
}

.library-pill.is-soft {
  color: color-mix(in srgb, var(--warning) 78%, var(--text) 22%);
  border-color: color-mix(in srgb, var(--warning) 34%, var(--border) 66%);
}

.library-pill.is-strict {
  color: color-mix(in srgb, var(--danger) 78%, var(--text) 22%);
  border-color: color-mix(in srgb, var(--danger) 34%, var(--border) 66%);
}

.checklist-library-item-stats {
  display: inline-flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
  color: var(--muted);
  font-size: 0.72rem;
}

.checklist-library-create {
  margin-top: 0.85rem;
  padding-top: 0.7rem;
  border-top: 1px solid color-mix(in srgb, var(--border) 20%, transparent 80%);
}

.rule-set-create-card {
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--border) 20%, transparent 80%);
  background: color-mix(in srgb, var(--panel-soft) 74%, var(--panel) 26%);
  padding: 0.75rem;
}

.rule-set-create-head {
  margin-bottom: 0.6rem;
}

.rule-set-create-label {
  margin: 0 0 0.35rem;
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.rule-set-create-field {
  display: grid;
}

.rule-set-segmented {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.rule-set-segmented.compact {
  width: fit-content;
}

.rule-set-segmented-btn {
  min-height: 2.1rem;
  padding: 0 0.72rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  background: color-mix(in srgb, var(--panel) 74%, var(--panel-soft) 26%);
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 700;
}

.rule-set-segmented-btn.active {
  color: color-mix(in srgb, var(--primary) 82%, var(--text) 18%);
  border-color: color-mix(in srgb, var(--primary) 44%, var(--border) 56%);
  background: color-mix(in srgb, var(--primary-soft) 72%, transparent 28%);
}

@media (max-width: 1220px) {
  .checklist-library-shell {
    position: static;
  }
}
</style>
