<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Plus } from 'lucide-vue-next'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseSelect from '@/components/form/BaseSelect.vue'
import type { Checklist, ChecklistEnforcementMode, ChecklistScope } from '@/types/checklist'

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

const createScopeOptions = [
  { label: 'Global', value: 'global' },
  { label: 'Account', value: 'account' },
  { label: 'Strategy', value: 'strategy' },
]

const enforcementOptions = [
  { label: 'Soft', value: 'soft' },
  { label: 'Strict', value: 'strict' },
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
</script>

<template>
  <section class="panel checklist-library-shell">
    <header class="checklist-library-headline">
      <div>
        <h2 class="checklist-library-title">Checklist Library</h2>
        <p class="checklist-library-note">Execution control templates by scope.</p>
      </div>
      <button type="button" class="checklist-library-new" @click="openCreate">
        <Plus class="h-4 w-4" />
        New Checklist
      </button>
    </header>

    <section class="checklist-library-filters">
      <BaseInput
        :model-value="search"
        label="Search"
        placeholder="Find checklist"
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
          <p class="checklist-library-item-title">{{ item.name }}</p>
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

      <p v-if="checklists.length === 0" class="section-note mt-2">No checklists found.</p>
    </section>

    <Transition name="fade">
      <section v-if="createOpen" class="checklist-library-create">
        <div class="section-head">
          <h3 class="section-title">New Checklist</h3>
        </div>

        <div class="grid grid-premium md:grid-cols-2">
          <BaseInput v-model="createForm.name" label="Name" />
          <BaseSelect v-model="createForm.scope" label="Scope" :options="createScopeOptions" />
          <BaseSelect v-model="createForm.enforcement_mode" label="Mode" :options="enforcementOptions" />
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
            Active
          </label>
        </div>

        <div class="flex items-center justify-end gap-2 mt-3">
          <button type="button" class="btn btn-ghost px-3 py-2 text-sm" @click="createOpen = false">Cancel</button>
          <button type="button" class="btn btn-primary px-3 py-2 text-sm" :disabled="saving" @click="submitCreate">
            {{ saving ? 'Creating...' : 'Create' }}
          </button>
        </div>
      </section>
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
    color-mix(in srgb, #02070d 78%, var(--panel) 22%) !important;
  box-shadow: 0 12px 26px color-mix(in srgb, #000 28%, transparent 72%);
}

.checklist-library-headline {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.7rem;
}

.checklist-library-title {
  margin: 0;
  font-size: 1.8rem;
  line-height: 1;
}

.checklist-library-note {
  margin: 0.32rem 0 0;
  font-size: 0.8rem;
  color: var(--muted);
  line-height: 1.35;
}

.checklist-library-new {
  min-height: 2.55rem;
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
  background: color-mix(in srgb, #01050a 72%, var(--panel) 28%);
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
  color: color-mix(in srgb, #d7a84f 74%, var(--text) 26%);
  border-color: color-mix(in srgb, #d7a84f 34%, var(--border) 66%);
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

@media (max-width: 1220px) {
  .checklist-library-shell {
    position: static;
  }
}
</style>
