<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { X } from 'lucide-vue-next'
import type {
  ChecklistItemType,
  ChecklistRuleDefinition,
  ChecklistRuleOperator,
  ChecklistRuleType,
} from '@/types/rules'

const RULE_TYPE_OPTIONS: Array<{ label: string; value: ChecklistRuleType }> = [
  { label: 'Boolean', value: 'boolean' },
  { label: 'Numeric', value: 'numeric' },
  { label: 'Select', value: 'select' },
  { label: 'Auto Metric', value: 'auto_metric' },
]

const METRIC_OPTIONS: Array<{ label: string; value: string }> = [
  { label: 'Risk %', value: 'risk_percent' },
  { label: 'Risk Amount', value: 'risk_amount' },
  { label: 'R Multiple', value: 'r_multiple' },
  { label: 'PnL Amount', value: 'pnl_amount' },
]

const OPERATOR_OPTIONS: Record<ChecklistRuleType, Array<{ label: string; value: ChecklistRuleOperator }>> = {
  boolean: [
    { label: 'Equals (==)', value: '==' },
    { label: 'Not Equals (!=)', value: '!=' },
  ],
  numeric: [
    { label: '>', value: '>' },
    { label: '>=', value: '>=' },
    { label: '<', value: '<' },
    { label: '<=', value: '<=' },
    { label: '==', value: '==' },
    { label: '!=', value: '!=' },
  ],
  auto_metric: [
    { label: '>', value: '>' },
    { label: '>=', value: '>=' },
    { label: '<', value: '<' },
    { label: '<=', value: '<=' },
    { label: '==', value: '==' },
    { label: '!=', value: '!=' },
  ],
  select: [
    { label: 'In', value: 'in' },
    { label: 'Not In', value: 'not_in' },
    { label: '==', value: '==' },
    { label: '!=', value: '!=' },
  ],
}

const DEFAULT_OPERATOR: Record<ChecklistRuleType, ChecklistRuleOperator> = {
  boolean: '==',
  numeric: '<=',
  auto_metric: '<=',
  select: 'in',
}

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
    rule: ChecklistRuleDefinition
    required: boolean
    category: string
    help_text: string | null
    config: Record<string, unknown>
    is_active: boolean
  }): void
}>()

const form = reactive({
  title: '',
  help_text: '',
  rule_type: 'boolean' as ChecklistRuleType,
  metric_key: 'risk_percent',
  operator: '==' as ChecklistRuleOperator,
  threshold_scalar: 'true',
  threshold_list: '',
  required: true,
})

const titleCount = computed(() => form.title.trim().length)
const whyCount = computed(() => form.help_text.trim().length)
const defaultCategory = computed(() =>
  props.categories.find((value) => value.trim().length > 0)?.trim() || 'Risk & Compliance'
)
const availableOperators = computed(() => OPERATOR_OPTIONS[form.rule_type])
const needsMetric = computed(() => form.rule_type === 'auto_metric' || form.rule_type === 'numeric')
const thresholdUsesList = computed(() =>
  form.rule_type === 'select' && (form.operator === 'in' || form.operator === 'not_in')
)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    resetForm()
  }
)

watch(
  () => form.rule_type,
  (nextType) => {
    form.operator = DEFAULT_OPERATOR[nextType]
    if (nextType === 'boolean') {
      form.threshold_scalar = 'true'
      form.threshold_list = ''
      return
    }

    if (nextType === 'select') {
      form.threshold_scalar = 'pass'
      form.threshold_list = 'pass'
      return
    }

    form.threshold_scalar = '1'
    form.threshold_list = ''
  }
)

watch(
  () => form.operator,
  () => {
    if (!thresholdUsesList.value) return
    if (!form.threshold_list.trim()) {
      form.threshold_list = form.threshold_scalar.trim() || 'pass'
    }
  }
)

const thresholdValue = computed<number | string | boolean | Array<number | string | boolean> | null>(() => {
  if (form.rule_type === 'boolean') {
    return form.threshold_scalar === 'true'
  }

  if (thresholdUsesList.value) {
    const values = form.threshold_list
      .split(',')
      .map((entry) => entry.trim())
      .filter((entry) => entry.length > 0)
    return values
  }

  if (form.rule_type === 'numeric' || form.rule_type === 'auto_metric') {
    const parsed = Number(form.threshold_scalar)
    if (!Number.isFinite(parsed)) return null
    return parsed
  }

  const normalized = form.threshold_scalar.trim()
  return normalized.length > 0 ? normalized : null
})

const thresholdValid = computed(() => {
  const value = thresholdValue.value
  if (value === null) return false
  if (Array.isArray(value)) return value.length > 0
  if (typeof value === 'string') return value.trim().length > 0
  return true
})

const metricValid = computed(() => !needsMetric.value || form.metric_key.trim().length > 0)
const canSubmit = computed(() =>
  form.title.trim().length > 0 && thresholdValid.value && metricValid.value && !props.saving
)

const previewText = computed(() => {
  const target = (() => {
    if (form.rule_type === 'auto_metric') return `metric ${form.metric_key || '(metric_key)'}`
    if (form.rule_type === 'numeric') {
      return form.metric_key.trim() ? `metric ${form.metric_key.trim()}` : 'input value'
    }
    if (form.rule_type === 'select') return 'selected option'
    return 'checkbox value'
  })()

  const thresholdPreview = (() => {
    if (thresholdValue.value === null) return '(threshold)'
    if (Array.isArray(thresholdValue.value)) return `[${thresholdValue.value.join(', ')}]`
    return String(thresholdValue.value)
  })()

  const requirement = form.required ? 'required' : 'optional'
  return `${target} ${form.operator} ${thresholdPreview} (${requirement})`
})

function resetForm() {
  form.title = ''
  form.help_text = ''
  form.rule_type = 'boolean'
  form.metric_key = 'risk_percent'
  form.operator = '=='
  form.threshold_scalar = 'true'
  form.threshold_list = ''
  form.required = true
}

function mapRuleTypeToItemType(ruleType: ChecklistRuleType): ChecklistItemType {
  if (ruleType === 'boolean') return 'checkbox'
  if (ruleType === 'select') return 'dropdown'
  return 'number'
}

function submit() {
  if (!canSubmit.value) return
  const threshold = thresholdValue.value
  if (threshold === null) return

  const rule: ChecklistRuleDefinition = {
    type: form.rule_type,
    operator: form.operator,
    threshold,
    required: form.required,
    metric_key: needsMetric.value ? form.metric_key.trim() : null,
  }

  const config: Record<string, unknown> = {
    weight: form.required ? 'hard' : 'soft',
    rule,
  }

  if (rule.type === 'select') {
    const options = Array.isArray(rule.threshold)
      ? rule.threshold.map((entry) => String(entry))
      : [String(rule.threshold)]
    config.options = options.filter((entry) => entry.trim().length > 0)
  }

  if (rule.type === 'numeric' || rule.type === 'auto_metric') {
    const thresholdNumber = typeof rule.threshold === 'number' ? rule.threshold : Number(rule.threshold)
    if (Number.isFinite(thresholdNumber)) {
      config.threshold = thresholdNumber
    }
    config.comparator = rule.operator === '==' ? '=' : rule.operator
  }

  if (rule.type === 'auto_metric' && rule.metric_key) {
    config.auto_metric = rule.metric_key
  }

  emit('create', {
    title: form.title.trim(),
    type: mapRuleTypeToItemType(form.rule_type),
    rule,
    required: form.required,
    category: defaultCategory.value,
    help_text: form.help_text.trim() ? form.help_text.trim() : null,
    config,
    is_active: true,
  })
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="open" class="rule-modal-overlay" @click.self="emit('close')">
        <section class="rule-modal-panel">
          <header class="rule-modal-head">
            <div>
              <h3 class="section-title">Add Trading Rule</h3>
              <p class="section-note">Define the exact schema used by UI, API, and evaluation engine.</p>
            </div>
            <button type="button" class="rule-close" @click="emit('close')" aria-label="Close">
              <X class="h-5 w-5" />
            </button>
          </header>

          <form class="rule-form" @submit.prevent="submit" @keydown.meta.enter.prevent="submit" @keydown.ctrl.enter.prevent="submit">
            <section class="rule-block">
              <label class="rule-label" for="new-rule-title">Rule title <span>(human readable)</span></label>
              <textarea
                id="new-rule-title"
                v-model="form.title"
                class="rule-textarea primary"
                maxlength="120"
                rows="3"
                placeholder="e.g. Risk percent must stay under 1.0%"
                required
              />
              <p class="rule-counter">{{ titleCount }}/120</p>
            </section>

            <section class="rule-block">
              <p class="rule-kicker">Rule schema</p>
              <div class="rule-grid">
                <label class="field-block">
                  <span>Type</span>
                  <select v-model="form.rule_type" class="rule-input" required>
                    <option v-for="entry in RULE_TYPE_OPTIONS" :key="entry.value" :value="entry.value">
                      {{ entry.label }}
                    </option>
                  </select>
                </label>

                <label v-if="needsMetric" class="field-block">
                  <span>Metric</span>
                  <select v-model="form.metric_key" class="rule-input" :required="needsMetric">
                    <option v-for="metric in METRIC_OPTIONS" :key="metric.value" :value="metric.value">
                      {{ metric.label }}
                    </option>
                  </select>
                </label>

                <label class="field-block">
                  <span>Operator</span>
                  <select v-model="form.operator" class="rule-input" required>
                    <option v-for="entry in availableOperators" :key="entry.value" :value="entry.value">
                      {{ entry.label }}
                    </option>
                  </select>
                </label>

                <label v-if="form.rule_type === 'boolean'" class="field-block">
                  <span>Threshold</span>
                  <select v-model="form.threshold_scalar" class="rule-input">
                    <option value="true">true</option>
                    <option value="false">false</option>
                  </select>
                </label>

                <label v-else-if="thresholdUsesList" class="field-block field-block-wide">
                  <span>Threshold list (comma separated)</span>
                  <input
                    v-model="form.threshold_list"
                    class="rule-input"
                    type="text"
                    placeholder="allowed, approved, pass"
                  >
                </label>

                <label v-else class="field-block">
                  <span>Threshold</span>
                  <input
                    v-model="form.threshold_scalar"
                    class="rule-input"
                    :type="form.rule_type === 'numeric' || form.rule_type === 'auto_metric' ? 'number' : 'text'"
                    :step="form.rule_type === 'numeric' || form.rule_type === 'auto_metric' ? 'any' : undefined"
                    placeholder="Enter threshold"
                  >
                </label>

                <label class="field-check">
                  <input v-model="form.required" type="checkbox">
                  <span>Required</span>
                </label>
              </div>
              <p class="rule-help">Preview: {{ previewText }}</p>
            </section>

            <section class="rule-block">
              <label class="rule-label" for="new-rule-why">Why this rule matters <span>(optional)</span></label>
              <textarea
                id="new-rule-why"
                v-model="form.help_text"
                class="rule-textarea"
                maxlength="220"
                rows="3"
                placeholder="What failure pattern does this rule prevent?"
              />
              <p class="rule-counter">{{ whyCount }}/220</p>
            </section>

            <footer class="rule-actions">
              <p class="rule-hint">⌘ + ↵ to save</p>
              <div class="rule-actions-right">
                <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="emit('close')">Cancel</button>
                <button
                  type="submit"
                  class="btn btn-primary px-4 py-2 text-sm"
                  :disabled="!canSubmit"
                >
                  {{ props.saving ? 'Saving...' : 'Save Rule' }}
                </button>
              </div>
            </footer>
          </form>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.rule-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: grid;
  justify-items: center;
  align-items: start;
  overflow-y: auto;
  padding: 4.5rem 1rem 1rem;
  background: color-mix(in srgb, var(--text) 52%, transparent 48%);
  backdrop-filter: blur(4px);
}

.rule-modal-panel {
  width: min(680px, 100%);
  max-height: calc(100vh - 5.5rem);
  overflow: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--border) 20%, transparent 80%);
  background: color-mix(in srgb, var(--panel-strong) 88%, var(--panel-soft) 12%);
  padding: 1rem;
  box-shadow: 0 24px 80px color-mix(in srgb, var(--text) 28%, transparent 72%);
}

.rule-modal-panel::-webkit-scrollbar {
  width: 0;
  height: 0;
}

.rule-modal-head {
  display: flex;
  justify-content: space-between;
  gap: 0.8rem;
}

.rule-close {
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 20%, transparent 80%);
  background: transparent;
  color: var(--muted);
  display: inline-grid;
  place-items: center;
}

.rule-close:hover {
  color: var(--text);
  border-color: color-mix(in srgb, var(--primary) 35%, var(--border) 65%);
}

.rule-form {
  margin-top: 0.8rem;
  display: grid;
  gap: 0.7rem;
}

.rule-block {
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 14%, transparent 86%);
  background: color-mix(in srgb, var(--panel) 78%, var(--panel-soft) 22%);
  padding: 0.75rem;
}

.rule-label {
  display: block;
  margin: 0;
  font-size: 1.02rem;
  font-weight: 700;
}

.rule-label span {
  color: var(--muted);
  font-weight: 500;
}

.rule-help {
  margin: 0.5rem 0 0;
  color: var(--muted);
  font-size: 0.78rem;
}

.rule-kicker {
  margin: 0 0 0.55rem;
  color: var(--muted);
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.rule-textarea {
  width: 100%;
  min-height: 5rem;
  resize: vertical;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  background: color-mix(in srgb, var(--panel) 84%, var(--panel-soft) 16%);
  color: var(--text);
  padding: 0.75rem;
  font-family: var(--font-body);
  font-size: 0.98rem;
}

.rule-textarea.primary {
  border-color: color-mix(in srgb, var(--primary) 60%, var(--border) 40%);
}

.rule-counter {
  margin: 0.5rem 0 0;
  text-align: right;
  color: var(--muted);
  font-size: 0.8rem;
}

.rule-grid {
  display: grid;
  gap: 0.55rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field-block {
  display: grid;
  gap: 0.28rem;
}

.field-block span {
  font-size: 0.75rem;
  color: var(--muted);
}

.field-block-wide {
  grid-column: 1 / -1;
}

.rule-input {
  width: 100%;
  min-height: 2.25rem;
  border-radius: 9px;
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  background: color-mix(in srgb, var(--panel) 84%, var(--panel-soft) 16%);
  color: var(--text);
  padding: 0.5rem 0.62rem;
  font-size: 0.88rem;
}

.field-check {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding-top: 1.2rem;
  font-size: 0.82rem;
  font-weight: 600;
}

.rule-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.2rem 0.1rem 0;
}

.rule-actions-right {
  display: inline-flex;
  gap: 0.5rem;
}

.rule-hint {
  margin: 0;
  color: var(--muted);
  font-size: 0.78rem;
}

@media (max-width: 640px) {
  .rule-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .field-block-wide {
    grid-column: auto;
  }

  .field-check {
    padding-top: 0.2rem;
  }

  .rule-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .rule-actions-right {
    justify-content: flex-end;
  }
}
</style>
