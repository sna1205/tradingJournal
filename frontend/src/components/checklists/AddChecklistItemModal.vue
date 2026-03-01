<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { X } from 'lucide-vue-next'
import type { ChecklistItemType, ChecklistNumberComparator, ChecklistRuleWeight } from '@/types/checklist'
import {
  CHECKLIST_CATEGORIES,
  COMPARATOR_OPTIONS,
  checklistCategoryLabel,
  normalizeChecklistCategory,
} from '@/utils/checklistSchema'

const props = withDefaults(
  defineProps<{
    open: boolean
    categories: string[]
    saving?: boolean
  }>(),
  {
    open: false,
    saving: false,
  }
)

const emit = defineEmits<{
  (event: 'close'): void
  (event: 'create', payload: {
    title: string
    type: ChecklistItemType
    required: boolean
    category: string
    help_text: string | null
    config: Record<string, unknown>
    is_active: boolean
  }): void
}>()

const typeOptions: Array<{ label: string; value: ChecklistItemType }> = [
  { label: 'Toggle', value: 'checkbox' },
  { label: 'Numeric', value: 'number' },
  { label: 'Scale', value: 'scale' },
  { label: 'Select', value: 'dropdown' },
  { label: 'Text', value: 'text' },
]

const quickExamples = [
  'I will not enter without a defined stop loss.',
  'I only trade in line with my higher-timeframe bias.',
  'I stop trading after two consecutive losses.',
  'I do not increase risk after a losing trade.',
  'I execute only A-grade setups from my plan.',
]

const categoryOptions = computed(() => {
  const explicit = props.categories
    .filter((value) => value.trim().length > 0)
    .map((value) => checklistCategoryLabel(normalizeChecklistCategory(value)))
  const defaults = CHECKLIST_CATEGORIES.map((entry) => entry.label)
  return Array.from(new Set([...defaults, ...explicit]))
})

const form = reactive({
  title: '',
  type: 'checkbox' as ChecklistItemType,
  required: true,
  category: 'Risk & Compliance',
  weight: 'hard' as ChecklistRuleWeight,
  help_text: '',
  auto_validate: false,
  auto_metric: 'risk_percent',
  dropdown_options: '',
  number_min: '',
  number_max: '',
  number_threshold: '',
  number_comparator: '<=' as ChecklistNumberComparator,
  number_unit: '%',
})

const titleCount = computed(() => form.title.trim().length)
const whyCount = computed(() => form.help_text.trim().length)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    form.title = ''
    form.type = 'checkbox'
    form.required = true
    form.category = categoryOptions.value[0] ?? 'Risk & Compliance'
    form.weight = 'hard'
    form.help_text = ''
    form.auto_validate = false
    form.auto_metric = 'risk_percent'
    form.dropdown_options = ''
    form.number_min = ''
    form.number_max = ''
    form.number_threshold = ''
    form.number_comparator = '<='
    form.number_unit = '%'
  }
)

function applyExample(example: string) {
  form.title = example
}

function configPayload(): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    weight: form.weight,
  }

  if (form.auto_validate) {
    payload.auto = 'risk_engine'
    payload.auto_metric = form.auto_metric
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
    payload.risk_linked = form.auto_validate
    return payload
  }

  if (form.type === 'text') {
    return { ...payload, maxLength: 240 }
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

  return payload
}

function submit() {
  if (props.saving) return
  if (!form.title.trim()) return
  emit('create', {
    title: form.title.trim(),
    type: form.type,
    required: form.required,
    category: form.category.trim() || 'Risk & Compliance',
    help_text: form.help_text.trim() ? form.help_text.trim() : null,
    config: configPayload(),
    is_active: true,
  })
}
</script>

<template>
  <Transition name="fade">
    <div v-if="open" class="rule-modal-overlay" @click.self="emit('close')">
      <section class="rule-modal-panel">
        <header class="rule-modal-head">
          <div>
            <h3 class="section-title">Add Trading Rule</h3>
            <p class="section-note">Write a rule you can enforce quickly during execution.</p>
          </div>
          <button type="button" class="btn btn-ghost p-2" @click="emit('close')">
            <X class="h-4 w-4" />
          </button>
        </header>

        <form class="rule-modal-form" @submit.prevent="submit">
          <div class="rule-modal-field">
            <label class="rule-modal-label" for="new-rule-title">Rule (actionable)</label>
            <p class="rule-modal-help">Keep it direct and binary.</p>
            <textarea
              id="new-rule-title"
              v-model="form.title"
              class="rule-modal-textarea"
              maxlength="120"
              rows="3"
              placeholder="e.g. I will not enter a trade without a defined stop loss"
              required
            />
            <p class="rule-modal-counter">{{ titleCount }}/120</p>
          </div>

          <div class="rule-modal-field">
            <p class="rule-modal-chip-label">Quick examples</p>
            <div class="rule-modal-chips">
              <button
                v-for="example in quickExamples"
                :key="example"
                type="button"
                class="rule-chip"
                @click="applyExample(example)"
              >
                {{ example }}
              </button>
            </div>
          </div>

          <div class="rule-modal-field">
            <label class="rule-modal-label" for="new-rule-why">Why this rule matters (optional)</label>
            <textarea
              id="new-rule-why"
              v-model="form.help_text"
              class="rule-modal-textarea"
              maxlength="220"
              rows="3"
              placeholder="What behavior does this protect you from?"
            />
            <p class="rule-modal-counter">{{ whyCount }}/220</p>
          </div>

          <details class="rule-modal-advanced">
            <summary>Advanced settings</summary>

            <div class="rule-modal-grid">
              <label>
                <span>Type</span>
                <select v-model="form.type" class="checklist-mini-select">
                  <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
              </label>

              <label>
                <span>Category</span>
                <input v-model="form.category" class="checklist-mini-input" list="builder-categories" placeholder="Risk & Compliance">
                <datalist id="builder-categories">
                  <option v-for="entry in categoryOptions" :key="`builder-category-${entry}`" :value="entry" />
                </datalist>
              </label>

              <label class="checklist-pill-toggle on">
                <input v-model="form.required" type="checkbox">
                Required
              </label>

              <label>
                <span>Weight</span>
                <select v-model="form.weight" class="checklist-mini-select">
                  <option value="hard">Hard</option>
                  <option value="soft">Soft</option>
                </select>
              </label>

              <label v-if="form.type === 'dropdown'" class="full">
                <span>Options</span>
                <input v-model="form.dropdown_options" class="checklist-mini-input" type="text" placeholder="A+, A, B">
              </label>

              <template v-if="form.type === 'number'">
                <label>
                  <span>Comparator</span>
                  <select v-model="form.number_comparator" class="checklist-mini-select">
                    <option v-for="option in COMPARATOR_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                  </select>
                </label>

                <label>
                  <span>Threshold</span>
                  <input v-model="form.number_threshold" class="checklist-mini-input" type="number" placeholder="1">
                </label>

                <label>
                  <span>Min</span>
                  <input v-model="form.number_min" class="checklist-mini-input" type="number" placeholder="0">
                </label>

                <label>
                  <span>Max</span>
                  <input v-model="form.number_max" class="checklist-mini-input" type="number" placeholder="1">
                </label>

                <label>
                  <span>Unit</span>
                  <input v-model="form.number_unit" class="checklist-mini-input" type="text" placeholder="%">
                </label>

                <label>
                  <span>Auto metric</span>
                  <select v-model="form.auto_metric" class="checklist-mini-select" :disabled="!form.auto_validate">
                    <option value="risk_percent">Risk %</option>
                    <option value="risk_amount">Risk $</option>
                    <option value="rr">R:R</option>
                  </select>
                </label>

                <label class="checklist-pill-toggle on full">
                  <input v-model="form.auto_validate" type="checkbox">
                  Enable auto-validation
                </label>
              </template>
            </div>
          </details>

          <div class="rule-modal-actions">
            <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="emit('close')">Cancel</button>
            <button
              type="submit"
              class="btn btn-primary px-4 py-2 text-sm"
              :disabled="props.saving || !form.title.trim()"
            >
              {{ props.saving ? 'Saving...' : 'Save Rule' }}
            </button>
          </div>
        </form>
      </section>
    </div>
  </Transition>
</template>

<style scoped>
.rule-modal-overlay {
  position: fixed;
  inset: 0;
  background: color-mix(in srgb, #000 62%, transparent 38%);
  z-index: var(--z-modal);
  display: grid;
  place-items: center;
  padding: 1rem;
}

.rule-modal-panel {
  width: min(760px, 100%);
  max-height: min(92vh, 860px);
  overflow: auto;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--border) 16%, transparent 84%);
  background: color-mix(in srgb, #03070d 72%, var(--panel) 28%);
  padding: 1rem;
}

.rule-modal-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.rule-modal-form {
  margin-top: 0.95rem;
  display: grid;
  gap: 0.9rem;
}

.rule-modal-field {
  display: grid;
  gap: 0.35rem;
}

.rule-modal-label {
  font-size: 0.96rem;
  font-weight: 700;
}

.rule-modal-help {
  margin: 0;
  color: var(--muted);
  font-size: 0.74rem;
}

.rule-modal-textarea {
  width: 100%;
  resize: vertical;
  min-height: 4.4rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, #b89530 46%, var(--border) 54%);
  background: color-mix(in srgb, #02060c 70%, var(--panel) 30%);
  color: var(--text);
  padding: 0.65rem 0.7rem;
  font-family: var(--font-body);
  font-size: 0.92rem;
}

.rule-modal-counter {
  margin: 0;
  text-align: right;
  color: var(--muted);
  font-size: 0.72rem;
}

.rule-modal-chip-label {
  margin: 0;
  color: var(--muted);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.rule-modal-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.36rem;
}

.rule-chip {
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--border) 18%, transparent 82%);
  background: color-mix(in srgb, #01050a 66%, var(--panel) 34%);
  color: var(--text);
  padding: 0.35rem 0.58rem;
  font-size: 0.74rem;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.rule-modal-advanced {
  border-top: 1px solid color-mix(in srgb, var(--border) 16%, transparent 84%);
  padding-top: 0.7rem;
}

.rule-modal-advanced summary {
  cursor: pointer;
  font-weight: 700;
  font-size: 0.78rem;
  color: var(--muted);
}

.rule-modal-grid {
  margin-top: 0.7rem;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.6rem;
}

.rule-modal-grid label {
  display: grid;
  gap: 0.2rem;
}

.rule-modal-grid label > span {
  font-size: 0.67rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.rule-modal-grid .full {
  grid-column: 1 / -1;
}

.rule-modal-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.2rem;
}

@media (max-width: 740px) {
  .rule-modal-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
