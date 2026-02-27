<script setup lang="ts">
import { computed, reactive, watch, type Component } from 'vue'
import {
  Hash,
  List,
  MoreHorizontal,
  SlidersHorizontal,
  ToggleLeft,
  Trash2,
  Type,
} from 'lucide-vue-next'
import type {
  ChecklistItem,
  ChecklistItemType,
  ChecklistNumberComparator,
  ChecklistRuleWeight,
} from '@/types/checklist'
import {
  COMPARATOR_OPTIONS,
  checklistRuleWeight,
  ruleComparatorSymbol,
} from '@/utils/checklistSchema'

const props = defineProps<{
  item: ChecklistItem
  categories: string[]
  expanded: boolean
}>()

const emit = defineEmits<{
  (event: 'toggle-expand', itemId: number): void
  (event: 'update', itemId: number, payload: Record<string, unknown>): void
  (event: 'remove', itemId: number): void
  (event: 'drag-start', itemId: number): void
  (event: 'drag-over', itemId: number): void
  (event: 'drop', itemId: number): void
}>()

const form = reactive({
  title: '',
  type: 'checkbox' as ChecklistItemType,
  category: '',
  required: false,
  weight: 'soft' as ChecklistRuleWeight,
  help_text: '',
  auto_validate: false,
  auto_metric: 'risk_percent',
  dropdown_options: '',
  number_min: '',
  number_max: '',
  number_threshold: '',
  number_comparator: '<=' as ChecklistNumberComparator,
  number_unit: '',
})

let hydrating = false

const typeIcon = computed<Component>(() => {
  if (form.type === 'checkbox') return ToggleLeft
  if (form.type === 'dropdown') return List
  if (form.type === 'number') return Hash
  if (form.type === 'scale') return SlidersHorizontal
  return Type
})

const typeLabel = computed(() => {
  if (form.type === 'checkbox') return 'Toggle'
  if (form.type === 'dropdown') return 'Select'
  if (form.type === 'number') return 'Numeric'
  if (form.type === 'scale') return 'Scale'
  return 'Text'
})

function hydrateFromProps() {
  hydrating = true
  form.title = props.item.title
  form.type = props.item.type
  form.category = props.item.category || 'Risk & Compliance'
  form.required = props.item.required
  form.weight = checklistRuleWeight(props.item)
  form.help_text = props.item.help_text ?? ''

  const config = props.item.config as Record<string, unknown>
  form.auto_validate = Boolean(config.auto || config.auto_metric)
  form.auto_metric = typeof config.auto_metric === 'string' ? config.auto_metric : 'risk_percent'
  form.dropdown_options = Array.isArray(config.options)
    ? config.options.map((entry) => String(entry)).join(', ')
    : ''
  form.number_min = config.min !== undefined ? String(config.min) : ''
  form.number_max = config.max !== undefined ? String(config.max) : ''
  form.number_threshold = config.threshold !== undefined ? String(config.threshold) : ''
  form.number_comparator = ruleComparatorSymbol(config.comparator)
  form.number_unit = typeof config.unit === 'string' ? config.unit : ''

  queueMicrotask(() => {
    hydrating = false
  })
}

watch(() => props.item, hydrateFromProps, { immediate: true, deep: true })

function toConfig(): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    weight: form.weight,
  }

  if (form.type === 'dropdown') {
    return {
      ...payload,
      options: form.dropdown_options
        .split(',')
        .map((value) => value.trim())
        .filter((value) => value.length > 0),
    }
  }

  if (form.type === 'number') {
    if (form.number_min.trim()) payload.min = Number(form.number_min)
    if (form.number_max.trim()) payload.max = Number(form.number_max)
    if (form.number_threshold.trim()) payload.threshold = Number(form.number_threshold)
    payload.comparator = form.number_comparator
    payload.unit = form.number_unit.trim() || '%'

    if (form.auto_validate) {
      payload.auto = 'risk_engine'
      payload.auto_metric = form.auto_metric
      payload.risk_linked = true
    }

    return payload
  }

  if (form.type === 'scale') {
    return {
      ...payload,
      min: 1,
      max: 3,
      labels: {
        1: 'Calm',
        2: 'Neutral',
        3: 'Tilted',
      },
    }
  }

  if (form.type === 'text') {
    return {
      ...payload,
      maxLength: 240,
    }
  }

  return payload
}

function saveNow() {
  if (hydrating) return

  emit('update', props.item.id, {
    title: form.title.trim() || props.item.title,
    type: form.type,
    category: form.category.trim() || 'Risk & Compliance',
    required: form.required,
    help_text: form.help_text.trim() ? form.help_text.trim() : null,
    config: toConfig(),
  })
}

function onDragStart(event: DragEvent) {
  event.dataTransfer?.setData('text/plain', String(props.item.id))
  emit('drag-start', props.item.id)
}

function onDragOver(event: DragEvent) {
  event.preventDefault()
  emit('drag-over', props.item.id)
}

function onDrop(event: DragEvent) {
  event.preventDefault()
  emit('drop', props.item.id)
}

function toggleExpand() {
  emit('toggle-expand', props.item.id)
}

function closeModal() {
  emit('toggle-expand', props.item.id)
}
</script>

<template>
  <article class="checklist-item-row" draggable="true" @dragstart="onDragStart" @dragover="onDragOver" @drop="onDrop">
    <div>
      <p class="checklist-rule-title">{{ form.title || 'Untitled rule' }}</p>
      <p v-if="form.help_text" class="checklist-rule-note">{{ form.help_text }}</p>
    </div>

    <div class="checklist-rule-meta">
      <component :is="typeIcon" class="checklist-type-icon" :title="typeLabel" />
      <span class="required-dot" :class="{ on: form.required }" title="Required rule" />
      <button type="button" class="checklist-rule-menu" aria-label="Rule settings" @click.stop="toggleExpand">
        <MoreHorizontal class="h-4 w-4" />
      </button>
    </div>
  </article>

  <Transition name="fade">
    <div v-if="expanded" class="checklist-settings-overlay" @click.self="closeModal">
      <aside class="checklist-settings-panel">
        <header class="checklist-settings-head">
          <div>
            <p class="kicker-label">Rule Settings</p>
            <p class="settings-title">{{ form.title || 'Untitled rule' }}</p>
          </div>
          <button type="button" class="btn btn-ghost p-2" @click="closeModal">Close</button>
        </header>

        <div class="checklist-settings-body">
          <label>
            <span>Rule title</span>
            <input v-model="form.title" type="text" class="checklist-mini-input" @blur="saveNow" @keydown.enter.prevent="saveNow">
          </label>

          <label>
            <span>Category</span>
            <select v-model="form.category" class="checklist-mini-select" @change="saveNow">
              <option v-for="category in categories" :key="`category-${item.id}-${category}`" :value="category">{{ category }}</option>
            </select>
          </label>

          <label>
            <span>Rule type</span>
            <select v-model="form.type" class="checklist-mini-select" @change="saveNow">
              <option value="checkbox">Toggle</option>
              <option value="dropdown">Select</option>
              <option value="number">Number</option>
              <option value="text">Text</option>
              <option value="scale">Scale</option>
            </select>
          </label>

          <label class="checklist-pill-toggle subtle">
            <input v-model="form.required" type="checkbox" @change="saveNow">
            Required
          </label>

          <label>
            <span>Weight</span>
            <select v-model="form.weight" class="checklist-mini-select" @change="saveNow">
              <option value="hard">Hard</option>
              <option value="soft">Soft</option>
            </select>
          </label>

          <input
            v-model="form.help_text"
            type="text"
            class="checklist-mini-input"
            placeholder="Help text"
            @blur="saveNow"
            @keydown.enter.prevent="saveNow"
          >

          <template v-if="form.type === 'dropdown'">
            <input
              v-model="form.dropdown_options"
              type="text"
              class="checklist-mini-input"
              placeholder="Options: A+, A, B"
              @blur="saveNow"
              @keydown.enter.prevent="saveNow"
            >
          </template>

          <template v-if="form.type === 'number'">
            <div class="settings-inline-grid">
              <select v-model="form.number_comparator" class="checklist-mini-select" @change="saveNow">
                <option v-for="option in COMPARATOR_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
              <input v-model="form.number_threshold" type="number" class="checklist-mini-input" placeholder="Threshold" @blur="saveNow" @keydown.enter.prevent="saveNow">
            </div>
            <div class="settings-inline-grid">
              <input v-model="form.number_min" type="number" class="checklist-mini-input" placeholder="Min" @blur="saveNow" @keydown.enter.prevent="saveNow">
              <input v-model="form.number_max" type="number" class="checklist-mini-input" placeholder="Max" @blur="saveNow" @keydown.enter.prevent="saveNow">
            </div>
            <input v-model="form.number_unit" type="text" class="checklist-mini-input" placeholder="Unit (%, $, R)" @blur="saveNow" @keydown.enter.prevent="saveNow">

            <label class="checklist-pill-toggle subtle">
              <input v-model="form.auto_validate" type="checkbox" @change="saveNow">
              Auto-validate
            </label>
            <select v-model="form.auto_metric" class="checklist-mini-select" :disabled="!form.auto_validate" @change="saveNow">
              <option value="risk_percent">Risk %</option>
              <option value="risk_amount">Risk $</option>
              <option value="rr">R:R</option>
            </select>
          </template>
        </div>

        <footer class="checklist-settings-foot">
          <button type="button" class="btn btn-ghost is-danger px-3 py-2 text-sm" @click="emit('remove', item.id)">
            <Trash2 class="h-4 w-4" />
            Delete rule
          </button>
        </footer>
      </aside>
    </div>
  </Transition>
</template>

<style scoped>
.checklist-item-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: flex-start;
  gap: 0.55rem;
  padding: 0.72rem 0.7rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--border) 12%, transparent 88%);
  border-left: 2px solid color-mix(in srgb, #b89530 58%, transparent 42%);
  background: color-mix(in srgb, #01050a 70%, var(--panel) 30%);
  cursor: grab;
}

.checklist-item-row:active {
  cursor: grabbing;
}

.checklist-rule-title {
  margin: 0;
  min-width: 0;
  font-size: 0.9rem;
  font-weight: 700;
  line-height: 1.25;
}

.checklist-rule-note {
  margin: 0.26rem 0 0;
  color: var(--muted);
  font-size: 0.72rem;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
  overflow: hidden;
  text-overflow: ellipsis;
}

.checklist-rule-meta {
  display: inline-flex;
  align-items: center;
  gap: 0.42rem;
}

.checklist-type-icon {
  width: 0.88rem;
  height: 0.88rem;
  color: var(--muted);
}

.required-dot {
  width: 0.42rem;
  height: 0.42rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--muted) 30%, transparent 70%);
}

.required-dot.on {
  background: color-mix(in srgb, var(--danger) 66%, transparent 34%);
}

.checklist-rule-menu {
  width: 1.65rem;
  height: 1.65rem;
  border: none;
  border-radius: 999px;
  background: transparent;
  color: var(--muted);
  display: inline-grid;
  place-items: center;
}

.checklist-rule-menu:hover {
  background: color-mix(in srgb, var(--panel-soft) 30%, transparent 70%);
}

.checklist-settings-overlay {
  position: fixed;
  inset: 0;
  background: color-mix(in srgb, #000 34%, transparent 66%);
  z-index: var(--z-modal);
  display: flex;
  justify-content: flex-end;
}

.checklist-settings-panel {
  width: min(460px, 100vw);
  height: 100%;
  background: color-mix(in srgb, var(--panel) 94%, var(--panel-soft) 6%);
  border-left: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  padding: 0.86rem;
  display: grid;
  grid-template-rows: auto 1fr auto;
  gap: 0.66rem;
}

.checklist-settings-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.6rem;
}

.settings-title {
  margin: 0.16rem 0 0;
  font-size: 0.9rem;
  font-weight: 700;
}

.checklist-settings-body {
  overflow: auto;
  display: grid;
  gap: 0.55rem;
}

.checklist-settings-body label {
  display: grid;
  gap: 0.18rem;
}

.checklist-settings-body label span {
  font-size: 0.67rem;
  color: var(--muted);
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.settings-inline-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.4rem;
}

.checklist-settings-foot {
  display: flex;
  justify-content: flex-end;
}
</style>
