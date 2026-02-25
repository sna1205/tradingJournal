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
    is_active: boolean
  }): void
}>()

const createOpen = ref(false)
const createForm = reactive({
  name: '',
  scope: 'global' as ChecklistScope,
  enforcement_mode: 'soft' as ChecklistEnforcementMode,
  account_id: '',
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

function openCreate() {
  createOpen.value = true
  createForm.name = ''
  createForm.scope = 'global'
  createForm.enforcement_mode = 'soft'
  createForm.account_id = ''
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
  <section class="panel checklist-list-panel">
    <div class="checklist-library-head">
      <div>
        <h2 class="section-title">Checklist Library</h2>
        <p class="section-note">Execution control templates by scope.</p>
      </div>
      <button type="button" class="btn btn-primary px-3 py-2 text-sm" @click="openCreate">
        <Plus class="h-4 w-4" />
        New Checklist
      </button>
    </div>

    <div class="checklist-library-filters">
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
    </div>

    <div v-if="loading" class="space-y-2 mt-3">
      <div class="skeleton-shimmer h-14 rounded-xl" />
      <div class="skeleton-shimmer h-14 rounded-xl" />
    </div>

    <div v-else class="checklist-list-items">
      <button
        v-for="item in checklists"
        :key="item.id"
        type="button"
        class="checklist-library-card"
        :class="{ active: item.id === selectedChecklistId }"
        @click="emit('select', item.id)"
      >
        <div class="checklist-library-card-head">
          <p class="checklist-library-card-title">{{ item.name }}</p>
          <span class="checklist-active-state" :class="{ on: item.is_active }">
            {{ item.is_active ? 'Active' : 'Archived' }}
          </span>
        </div>

        <div class="checklist-library-card-badges">
          <span class="discipline-badge">{{ item.scope }}</span>
          <span class="discipline-badge" :class="enforcementClass(item.enforcement_mode)">
            {{ item.enforcement_mode }}
          </span>
        </div>

        <div class="checklist-library-card-stats">
          <span>Total {{ statLabel(item.id, 'total') }}</span>
          <span>Required {{ statLabel(item.id, 'required') }}</span>
        </div>
      </button>

      <p v-if="checklists.length === 0" class="section-note mt-2">No checklists found.</p>
    </div>

    <Transition name="fade">
      <div v-if="createOpen" class="checklist-create-inline">
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
      </div>
    </Transition>
  </section>
</template>
