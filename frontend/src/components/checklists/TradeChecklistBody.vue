<script setup lang="ts">
import { computed, ref } from 'vue'
import { AlertTriangle, Check, HelpCircle, ShieldCheck } from 'lucide-vue-next'
import type { Checklist, TradeChecklistItemWithResponse, TradeChecklistReadiness, TradeChecklistResponseRecord } from '@/types/checklist'
import ChecklistProgressHeader from '@/components/checklists/ChecklistProgressHeader.vue'
import OptionalSection from '@/components/checklists/OptionalSection.vue'

const props = withDefaults(
  defineProps<{
    checklist: Checklist | null
    requiredItems: TradeChecklistItemWithResponse[]
    optionalItems: TradeChecklistItemWithResponse[]
    archivedResponses: TradeChecklistResponseRecord[]
    readiness: TradeChecklistReadiness
    loading?: boolean
    saving?: boolean
    submitAttempted?: boolean
    strictMode?: boolean
    showHeader?: boolean
  }>(),
  {
    loading: false,
    saving: false,
    submitAttempted: false,
    strictMode: false,
    showHeader: true,
  }
)

const emit = defineEmits<{
  (event: 'update-response', itemId: number, value: unknown): void
}>()

const optionalOpen = ref(false)
const helpOpenId = ref<number | null>(null)

const missingRequiredSet = computed(() =>
  new Set(props.readiness.missing_required.map((item) => item.checklist_item_id))
)

function isMissing(itemId: number) {
  return props.submitAttempted && missingRequiredSet.value.has(itemId)
}

function dropdownOptions(item: TradeChecklistItemWithResponse): string[] {
  const config = item.config as { options?: unknown }
  const options = config?.options
  if (!Array.isArray(options)) return []
  return options
    .map((value) => String(value).trim())
    .filter((value) => value.length > 0)
}

function numberMin(item: TradeChecklistItemWithResponse): number | undefined {
  const config = item.config as { min?: unknown }
  return typeof config?.min === 'number' ? config.min : undefined
}

function numberMax(item: TradeChecklistItemWithResponse): number | undefined {
  const config = item.config as { max?: unknown }
  return typeof config?.max === 'number' ? config.max : undefined
}

function textMaxLength(item: TradeChecklistItemWithResponse): number | undefined {
  const config = item.config as { maxLength?: unknown }
  return typeof config?.maxLength === 'number' ? config.maxLength : undefined
}

function scaleValues(item: TradeChecklistItemWithResponse): number[] {
  const config = item.config as { min?: unknown; max?: unknown }
  const min = typeof config?.min === 'number' ? Math.floor(config.min) : 1
  const max = typeof config?.max === 'number' ? Math.floor(config.max) : 5
  if (max < min) return [1, 2, 3, 4, 5]
  const values: number[] = []
  for (let value = min; value <= max; value += 1) {
    values.push(value)
  }
  return values
}

function scaleLabel(item: TradeChecklistItemWithResponse, score: number): string {
  const config = item.config as { labels?: Record<string, string> }
  const labels = config?.labels ?? {}
  return labels[String(score)] ?? String(score)
}

function valueForInput(value: unknown): string {
  if (value === null || value === undefined) return ''
  return String(value)
}

function toggleHelp(itemId: number) {
  helpOpenId.value = helpOpenId.value === itemId ? null : itemId
}

function itemTypeLabel(type: TradeChecklistItemWithResponse['type']) {
  if (type === 'checkbox') return 'Toggle'
  if (type === 'dropdown') return 'Select'
  if (type === 'number') return 'Number'
  if (type === 'text') return 'Note'
  return 'Scale'
}
</script>

<template>
  <section class="panel trade-checklist-panel-shell">
    <ChecklistProgressHeader v-if="showHeader" :readiness="readiness" />

    <div v-if="loading" class="trade-checklist-loading">
      <div class="skeleton-shimmer h-12 rounded-xl" />
      <div class="skeleton-shimmer h-12 rounded-xl" />
      <div class="skeleton-shimmer h-12 rounded-xl" />
    </div>

    <template v-else-if="checklist">
      <p class="trade-checklist-name">
        <ShieldCheck class="h-4 w-4" />
        {{ checklist.name }}
      </p>

      <div
        v-if="strictMode && !readiness.ready"
        class="trade-checklist-warning is-strict"
      >
        <AlertTriangle class="h-4 w-4" />
        <p>
          <strong>Checklist blocked.</strong>
          Complete required checklist items to proceed.
        </p>
      </div>

      <div
        v-else-if="!strictMode && !readiness.ready"
        class="trade-checklist-warning"
      >
        <AlertTriangle class="h-4 w-4" />
        <p>
          <strong>Checklist incomplete.</strong>
          Trade logged with missing validations.
        </p>
      </div>

      <section class="trade-checklist-section">
        <div class="trade-checklist-section-head">
          <p class="trade-checklist-section-title">Required</p>
          <span class="trade-checklist-section-count">{{ requiredItems.length }}</span>
        </div>

        <article
          v-for="item in requiredItems"
          :key="`required-${item.id}`"
          class="trade-checklist-item"
          :class="{
            'is-missing': isMissing(item.id),
            'is-complete': item.response.is_completed,
            [`is-type-${item.type}`]: true,
          }"
        >
          <div class="trade-checklist-row-main">
            <div class="trade-checklist-row-input">
              <button
                v-if="item.type === 'checkbox'"
                type="button"
                class="trade-checklist-checkbox-modern"
                :class="{ checked: Boolean(item.response.value) }"
                :aria-pressed="Boolean(item.response.value)"
                @click="emit('update-response', item.id, !Boolean(item.response.value))"
              >
                <Check class="h-4 w-4 check-icon" />
              </button>

              <select
                v-else-if="item.type === 'dropdown'"
                class="trade-checklist-input"
                :value="valueForInput(item.response.value)"
                @change="emit('update-response', item.id, ($event.target as HTMLSelectElement).value)"
              >
                <option value="">Select</option>
                <option v-for="option in dropdownOptions(item)" :key="`${item.id}-${option}`" :value="option">
                  {{ option }}
                </option>
              </select>

              <input
                v-else-if="item.type === 'number'"
                type="number"
                class="trade-checklist-input"
                :value="valueForInput(item.response.value)"
                :min="numberMin(item)"
                :max="numberMax(item)"
                @input="emit('update-response', item.id, ($event.target as HTMLInputElement).value)"
              >

              <input
                v-else-if="item.type === 'text'"
                type="text"
                class="trade-checklist-input"
                :value="valueForInput(item.response.value)"
                :maxlength="textMaxLength(item)"
                @input="emit('update-response', item.id, ($event.target as HTMLInputElement).value)"
              >

              <div v-else class="trade-checklist-scale-compact">
                <button
                  v-for="score in scaleValues(item)"
                  :key="`${item.id}-${score}`"
                  type="button"
                  class="trade-checklist-scale-btn"
                  :class="{ active: Number(item.response.value) === score }"
                  @click="emit('update-response', item.id, score)"
                >
                  {{ score }}
                </button>
              </div>
            </div>

            <div class="trade-checklist-row-content">
              <div
                class="trade-checklist-item-head"
                :class="{ clickable: item.type === 'checkbox' }"
                @click="item.type === 'checkbox' && emit('update-response', item.id, !Boolean(item.response.value))"
              >
                <p>{{ item.title }}</p>
                <div class="trade-checklist-item-meta">
                  <span class="trade-checklist-type-pill">{{ itemTypeLabel(item.type) }}</span>
                  <span class="pill">Required</span>
                  <span v-if="item.response.is_completed" class="trade-checklist-complete-pill">
                    <Check class="h-3 w-3" />
                    Done
                  </span>
                  <button
                    v-if="item.help_text"
                    type="button"
                    class="btn btn-ghost p-1"
                    @click.stop="toggleHelp(item.id)"
                  >
                    <HelpCircle class="h-4 w-4" />
                  </button>
                </div>
              </div>

              <p v-if="item.type === 'scale'" class="trade-checklist-scale-label">
                {{ Number(item.response.value) > 0 ? scaleLabel(item, Number(item.response.value)) : 'Select scale' }}
              </p>

              <p v-if="helpOpenId === item.id && item.help_text" class="trade-checklist-help">
                {{ item.help_text }}
              </p>
            </div>
          </div>
        </article>
      </section>

      <OptionalSection
        v-if="optionalItems.length > 0"
        :open="optionalOpen"
        :count="optionalItems.length"
        @toggle="optionalOpen = !optionalOpen"
      >
        <article
          v-for="item in optionalItems"
          :key="`optional-${item.id}`"
          class="trade-checklist-item"
          :class="{ 'is-complete': item.response.is_completed }"
        >
          <div class="trade-checklist-row-main">
            <div class="trade-checklist-row-input">
              <button
                v-if="item.type === 'checkbox'"
                type="button"
                class="trade-checklist-checkbox-modern"
                :class="{ checked: Boolean(item.response.value) }"
                :aria-pressed="Boolean(item.response.value)"
                @click="emit('update-response', item.id, !Boolean(item.response.value))"
              >
                <Check class="h-4 w-4 check-icon" />
              </button>

              <select
                v-else-if="item.type === 'dropdown'"
                class="trade-checklist-input"
                :value="valueForInput(item.response.value)"
                @change="emit('update-response', item.id, ($event.target as HTMLSelectElement).value)"
              >
                <option value="">Select</option>
                <option v-for="option in dropdownOptions(item)" :key="`${item.id}-${option}`" :value="option">
                  {{ option }}
                </option>
              </select>

              <input
                v-else-if="item.type === 'number'"
                type="number"
                class="trade-checklist-input"
                :value="valueForInput(item.response.value)"
                :min="numberMin(item)"
                :max="numberMax(item)"
                @input="emit('update-response', item.id, ($event.target as HTMLInputElement).value)"
              >

              <input
                v-else-if="item.type === 'text'"
                type="text"
                class="trade-checklist-input"
                :value="valueForInput(item.response.value)"
                :maxlength="textMaxLength(item)"
                @input="emit('update-response', item.id, ($event.target as HTMLInputElement).value)"
              >

              <div v-else class="trade-checklist-scale-compact">
                <button
                  v-for="score in scaleValues(item)"
                  :key="`${item.id}-${score}`"
                  type="button"
                  class="trade-checklist-scale-btn"
                  :class="{ active: Number(item.response.value) === score }"
                  @click="emit('update-response', item.id, score)"
                >
                  {{ score }}
                </button>
              </div>
            </div>

            <div class="trade-checklist-row-content">
              <div
                class="trade-checklist-item-head"
                :class="{ clickable: item.type === 'checkbox' }"
                @click="item.type === 'checkbox' && emit('update-response', item.id, !Boolean(item.response.value))"
              >
                <p>{{ item.title }}</p>
                <div class="trade-checklist-item-meta">
                  <span class="trade-checklist-type-pill">{{ itemTypeLabel(item.type) }}</span>
                  <span class="pill">Optional</span>
                  <span v-if="item.response.is_completed" class="trade-checklist-complete-pill">
                    <Check class="h-3 w-3" />
                    Done
                  </span>
                  <button
                    v-if="item.help_text"
                    type="button"
                    class="btn btn-ghost p-1"
                    @click.stop="toggleHelp(item.id)"
                  >
                    <HelpCircle class="h-4 w-4" />
                  </button>
                </div>
              </div>

              <p v-if="helpOpenId === item.id && item.help_text" class="trade-checklist-help">
                {{ item.help_text }}
              </p>
            </div>
          </div>
        </article>
      </OptionalSection>

      <section v-if="archivedResponses.length > 0" class="trade-checklist-archived">
        <p class="kicker-label">Archived items</p>
        <p
          v-for="entry in archivedResponses"
          :key="`archived-${entry.checklist_item_id}`"
          class="trade-checklist-archived-row"
        >
          {{ entry.title || 'Item archived' }}
        </p>
      </section>

      <p v-if="saving" class="kicker-label">Saving checklist responses...</p>
    </template>

    <p v-else class="section-note">No active pre-trade checklist configured.</p>
  </section>
</template>

<style scoped>
.trade-checklist-panel-shell {
  display: grid;
  gap: 0.72rem;
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%) !important;
  border-radius: 16px;
  background:
    radial-gradient(circle at 8% -10%, color-mix(in srgb, var(--primary-soft) 26%, transparent 74%), transparent 40%),
    color-mix(in srgb, var(--panel) 95%, transparent 5%) !important;
  padding: 0.82rem;
}

.trade-checklist-name {
  margin: 0;
  display: inline-flex;
  align-items: center;
  gap: 0.42rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--muted);
}

.trade-checklist-warning {
  border: 1px solid color-mix(in srgb, #d8ac4f 38%, var(--border) 62%);
  border-radius: 12px;
  background: color-mix(in srgb, #d8ac4f 12%, var(--panel) 88%);
  padding: 0.5rem 0.62rem;
  display: flex;
  align-items: flex-start;
  gap: 0.46rem;
  font-size: 0.78rem;
}

.trade-checklist-warning p {
  margin: 0;
}

.trade-checklist-warning strong {
  font-weight: 800;
}

.trade-checklist-warning.is-strict {
  border-color: color-mix(in srgb, var(--danger) 44%, var(--border) 56%);
  background: color-mix(in srgb, var(--danger) 10%, var(--panel) 90%);
}

.trade-checklist-section {
  display: grid;
  gap: 0.5rem;
}

.trade-checklist-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.trade-checklist-section-title {
  margin: 0;
  font-size: 0.76rem;
  font-weight: 800;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--muted);
}

.trade-checklist-section-count {
  border: 1px solid color-mix(in srgb, var(--border) 34%, transparent 66%);
  border-radius: 999px;
  min-width: 1.7rem;
  height: 1.32rem;
  display: inline-grid;
  place-items: center;
  padding: 0 0.36rem;
  font-size: 0.72rem;
  font-weight: 800;
  color: var(--muted);
}

.trade-checklist-item {
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  border-radius: 14px;
  background: color-mix(in srgb, var(--panel-soft) 48%, transparent 52%);
  padding: 0.66rem;
  display: grid;
  gap: 0.42rem;
  transition: border-color var(--transition-fast), background-color var(--transition-fast), transform var(--transition-fast), box-shadow var(--transition-fast);
}

.trade-checklist-item.is-missing {
  border-color: color-mix(in srgb, var(--danger) 48%, var(--border) 52%);
}

.trade-checklist-item.is-complete {
  border-color: color-mix(in srgb, var(--success) 42%, var(--border) 58%);
  background: color-mix(in srgb, var(--success) 9%, var(--panel-soft) 91%);
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--success) 16%, transparent 84%) inset;
}

.trade-checklist-row-main {
  display: grid;
  grid-template-columns: minmax(104px, 132px) minmax(0, 1fr);
  gap: 0.55rem;
  align-items: start;
}

.trade-checklist-row-input {
  min-height: 2.1rem;
  display: inline-flex;
  align-items: center;
}

.trade-checklist-row-content {
  min-width: 0;
}

.trade-checklist-item-head {
  display: grid;
  gap: 0.34rem;
}

.trade-checklist-item-head p {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.2;
  font-weight: 700;
}

.trade-checklist-item-head.clickable {
  cursor: pointer;
}

.trade-checklist-item-head.clickable:hover p {
  color: color-mix(in srgb, var(--primary) 76%, var(--text) 24%);
}

.trade-checklist-item-meta {
  display: inline-flex;
  align-items: center;
  gap: 0.32rem;
  flex-wrap: wrap;
  justify-content: flex-start;
}

.trade-checklist-type-pill {
  border: 1px solid color-mix(in srgb, var(--border) 34%, transparent 66%);
  border-radius: 999px;
  padding: 0.12rem 0.46rem;
  font-size: 0.64rem;
  font-weight: 800;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--muted);
}

.trade-checklist-item-meta :deep(.pill) {
  min-height: auto;
  padding: 0.12rem 0.46rem;
  font-size: 0.64rem;
  font-weight: 800;
}

.trade-checklist-complete-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  border: 1px solid color-mix(in srgb, var(--success) 40%, var(--border) 60%);
  border-radius: 999px;
  padding: 0.14rem 0.42rem;
  font-size: 0.66rem;
  font-weight: 800;
  color: color-mix(in srgb, var(--success) 82%, var(--text) 18%);
}

.trade-checklist-input {
  width: 100%;
  min-height: 2.04rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 32%, transparent 68%);
  background: color-mix(in srgb, var(--panel-soft) 54%, transparent 46%);
  padding: 0.28rem 0.52rem;
  color: var(--text);
  font-size: 0.78rem;
}

.trade-checklist-input:focus-visible {
  outline: none;
  border-color: color-mix(in srgb, var(--primary) 54%, var(--border) 46%);
}

.trade-checklist-checkbox-modern {
  width: 2.16rem;
  height: 2.16rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--border) 36%, transparent 64%);
  background: color-mix(in srgb, var(--panel-soft) 52%, transparent 48%);
  display: inline-grid;
  place-items: center;
  color: transparent;
  transition: border-color var(--transition-fast), background-color var(--transition-fast), color var(--transition-fast);
}

.trade-checklist-checkbox-modern.checked {
  color: color-mix(in srgb, var(--success) 86%, var(--text) 14%);
  border-color: color-mix(in srgb, var(--success) 44%, var(--border) 56%);
  background: color-mix(in srgb, var(--success) 16%, var(--panel) 84%);
}

.trade-checklist-checkbox-modern .check-icon {
  line-height: 1;
}

.trade-checklist-item.is-type-checkbox .trade-checklist-row-main {
  grid-template-columns: 2.5rem minmax(0, 1fr);
  gap: 0.62rem;
  align-items: start;
}

.trade-checklist-item.is-type-checkbox .trade-checklist-row-input {
  min-height: 0;
  align-items: flex-start;
  padding-top: 0.05rem;
}

.trade-checklist-scale-compact {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.26rem;
}

.trade-checklist-scale-btn {
  border: 1px solid color-mix(in srgb, var(--border) 28%, transparent 72%);
  border-radius: 999px;
  background: color-mix(in srgb, var(--panel-soft) 56%, transparent 44%);
  min-width: 1.8rem;
  min-height: 1.8rem;
  padding: 0 0.42rem;
  font-size: 0.74rem;
  font-weight: 700;
}

.trade-checklist-scale-btn.active {
  border-color: color-mix(in srgb, var(--primary) 52%, var(--border) 48%);
  color: color-mix(in srgb, var(--primary) 82%, var(--text) 18%);
  background: color-mix(in srgb, var(--primary-soft) 62%, var(--panel) 38%);
}

.trade-checklist-scale-label {
  margin: 0.22rem 0 0;
  font-size: 0.72rem;
  color: var(--muted);
}

.trade-checklist-help {
  margin: 0.2rem 0 0;
  font-size: 0.74rem;
  color: var(--muted);
}

.trade-checklist-archived {
  border-top: 1px dashed color-mix(in srgb, var(--border) 34%, transparent 66%);
  padding-top: 0.5rem;
  display: grid;
  gap: 0.24rem;
}

.trade-checklist-archived-row {
  margin: 0;
  font-size: 0.74rem;
  color: var(--muted);
}

@media (max-width: 1199px) {
  .trade-checklist-panel-shell {
    padding: 0.72rem;
  }

  .trade-checklist-row-main {
    grid-template-columns: minmax(84px, 104px) minmax(0, 1fr);
    gap: 0.48rem;
  }

  .trade-checklist-item.is-type-checkbox .trade-checklist-row-main {
    grid-template-columns: 2.36rem minmax(0, 1fr);
    gap: 0.54rem;
  }
}

@media (max-width: 680px) {
  .trade-checklist-row-main {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.4rem;
  }

  .trade-checklist-item.is-type-checkbox .trade-checklist-row-main {
    grid-template-columns: 2.2rem minmax(0, 1fr);
    gap: 0.5rem;
  }

  .trade-checklist-row-input {
    min-height: 0;
  }

  .trade-checklist-item-head p {
    font-size: 0.86rem;
  }

  .trade-checklist-item-meta {
    justify-content: flex-start;
  }
}
</style>
