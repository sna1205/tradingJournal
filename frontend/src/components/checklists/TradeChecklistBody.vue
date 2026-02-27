<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Check, ChevronDown, Info } from 'lucide-vue-next'
import type {
  Checklist,
  TradeChecklistExecutionSnapshot,
  TradeChecklistItemWithResponse,
  TradeChecklistReadiness,
  TradeChecklistResponseRecord,
} from '@/types/checklist'
import type { TradePrecheckResult } from '@/stores/tradeStore'
import { normalizeChecklistCategory } from '@/utils/checklistSchema'

type RuleLaneKey = 'before' | 'during' | 'after'

interface RuleLane {
  key: RuleLaneKey
  label: string
}

const RULE_LANES: RuleLane[] = [
  { key: 'before', label: 'Before Trading' },
  { key: 'during', label: 'During Trading' },
  { key: 'after', label: 'After Trading' },
]

const props = withDefaults(
  defineProps<{
    checklist: Checklist | null
    requiredItems: TradeChecklistItemWithResponse[]
    optionalItems: TradeChecklistItemWithResponse[]
    archivedResponses: TradeChecklistResponseRecord[]
    readiness: TradeChecklistReadiness
    executionSnapshot?: TradeChecklistExecutionSnapshot | null
    loading?: boolean
    saving?: boolean
    submitAttempted?: boolean
    strictMode?: boolean
    showHeader?: boolean
    riskPrecheck?: TradePrecheckResult | null
  }>(),
  {
    loading: false,
    saving: false,
    submitAttempted: false,
    strictMode: false,
    showHeader: true,
    riskPrecheck: null,
    executionSnapshot: null,
  }
)

const emit = defineEmits<{
  (event: 'update-response', itemId: number, value: unknown): void
  (event: 'evaluation-change', payload: { failedRequiredIds: number[]; firstFailingId: number | null }): void
}>()

const cardOpen = ref(true)

const allItems = computed(() =>
  [...props.requiredItems, ...props.optionalItems]
    .slice()
    .sort((left, right) => left.order_index - right.order_index || left.id - right.id)
)

const checkedCount = computed(() => allItems.value.filter((item) => item.response.is_completed).length)
const totalCount = computed(() => allItems.value.length)
const snapshotFailedRows = computed(() => {
  const snapshot = props.executionSnapshot
  if (!snapshot || snapshot.failed_rule_ids.length === 0) return []

  return snapshot.failed_rule_ids.map((ruleId, index) => ({
    id: ruleId,
    title: snapshot.failed_rule_titles[index] || `Rule #${ruleId}`,
  }))
})

function laneForItem(item: TradeChecklistItemWithResponse): RuleLaneKey {
  const category = normalizeChecklistCategory(item.category)
  const text = `${item.title} ${item.category ?? ''}`.toLowerCase()

  if (/journal|review|post|after|debrief/.test(text)) return 'after'
  if (item.type === 'text' && !item.required) return 'after'

  if (category === 'market_context' || category === 'setup_validation') return 'before'
  return 'during'
}

const laneItems = computed<Record<RuleLaneKey, TradeChecklistItemWithResponse[]>>(() => {
  const result: Record<RuleLaneKey, TradeChecklistItemWithResponse[]> = {
    before: [],
    during: [],
    after: [],
  }

  for (const item of allItems.value) {
    result[laneForItem(item)].push(item)
  }

  return result
})

watch(
  () => allItems.value,
  () => {
    emit('evaluation-change', {
      failedRequiredIds: [],
      firstFailingId: null,
    })
  },
  { immediate: true, deep: true }
)

function dropdownOptions(item: TradeChecklistItemWithResponse): string[] {
  const config = item.config as { options?: unknown }
  if (!Array.isArray(config.options)) return []
  return config.options.map((entry) => String(entry)).filter((entry) => entry.trim().length > 0)
}

function toggleItem(item: TradeChecklistItemWithResponse) {
  if (item.type === 'checkbox') {
    emit('update-response', item.id, !Boolean(item.response.value))
    return
  }

  if (item.type === 'number' || item.type === 'scale') {
    const config = item.config as { min?: unknown }
    const min = typeof config.min === 'number' ? config.min : 1
    emit('update-response', item.id, item.response.is_completed ? null : min)
    return
  }

  if (item.type === 'dropdown') {
    const firstOption = dropdownOptions(item)[0] ?? 'done'
    emit('update-response', item.id, item.response.is_completed ? '' : firstOption)
    return
  }

  emit('update-response', item.id, item.response.is_completed ? '' : 'Done')
}
</script>

<template>
  <section class="rules-checklist-shell">
    <header v-if="showHeader" class="rules-checklist-title-row">
      <p class="rules-checklist-title">Rules Checklist</p>
      <Info class="h-3.5 w-3.5 rules-checklist-info" />
    </header>

    <section class="rules-card">
      <button type="button" class="rules-card-head" @click="cardOpen = !cardOpen">
        <span class="rules-card-head-left">
          <strong>Rules</strong>
          <em>{{ checkedCount }}/{{ totalCount }} checked</em>
        </span>
        <ChevronDown class="h-4 w-4" :class="{ 'rotate-180': cardOpen }" />
      </button>

      <div v-if="loading" class="rules-loading">
        <div class="skeleton-shimmer h-10 rounded-xl" />
        <div class="skeleton-shimmer h-10 rounded-xl" />
      </div>

      <div v-show="cardOpen && !loading && checklist" class="rules-card-body">
        <section
          v-for="lane in RULE_LANES"
          :key="lane.key"
          class="rules-lane"
          v-show="laneItems[lane.key].length > 0"
        >
          <h4 class="rules-lane-label">{{ lane.label }}</h4>

          <button
            v-for="item in laneItems[lane.key]"
            :key="item.id"
            type="button"
            class="rules-item"
            :class="{ checked: item.response.is_completed }"
            @click="toggleItem(item)"
          >
            <span class="rules-item-box" :class="{ checked: item.response.is_completed }">
              <Check class="h-3.5 w-3.5" />
            </span>
            <span class="rules-item-text">{{ item.title }}</span>
          </button>
        </section>
      </div>

      <p v-if="saving" class="rules-saving">Saving...</p>
      <div
        v-if="!loading && snapshotFailedRows.length > 0"
        class="rules-snapshot-failures"
      >
        <p class="rules-snapshot-title">Failed At Execution</p>
        <p
          v-for="row in snapshotFailedRows"
          :key="`snapshot-fail-${row.id}`"
          class="rules-snapshot-row"
        >
          {{ row.title }}
        </p>
      </div>
      <p v-if="!loading && !checklist" class="rules-empty">No active checklist configured.</p>
    </section>
  </section>
</template>

<style scoped>
.rules-checklist-shell {
  display: grid;
  gap: 0.45rem;
}

.rules-checklist-title-row {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.rules-checklist-title {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
}

.rules-checklist-info {
  color: var(--muted);
}

.rules-card {
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  background: color-mix(in srgb, #01050a 84%, var(--panel) 16%);
  overflow: hidden;
}

.rules-card-head {
  width: 100%;
  border: none;
  border-bottom: 1px solid color-mix(in srgb, var(--border) 18%, transparent 82%);
  background: transparent;
  color: var(--text);
  padding: 0.72rem 0.8rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  text-align: left;
}

.rules-card-head-left {
  display: inline-flex;
  align-items: baseline;
  gap: 0.55rem;
}

.rules-card-head-left strong {
  font-size: 0.96rem;
  font-weight: 700;
}

.rules-card-head-left em {
  font-size: 0.9rem;
  font-style: normal;
  font-weight: 700;
  color: color-mix(in srgb, #35d89e 84%, var(--text) 16%);
}

.rules-card-body {
  max-height: 360px;
  overflow: auto;
  padding: 0.72rem 0.78rem 0.8rem;
  display: grid;
  gap: 1rem;
}

.rules-loading {
  padding: 0.72rem 0.78rem;
  display: grid;
  gap: 0.5rem;
}

.rules-lane {
  display: grid;
  gap: 0.38rem;
}

.rules-lane-label {
  margin: 0;
  font-size: 0.73rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--muted);
}

.rules-item {
  width: 100%;
  border: none;
  background: transparent;
  color: var(--text);
  padding: 0.12rem 0;
  display: flex;
  align-items: center;
  gap: 0.55rem;
  text-align: left;
}

.rules-item-box {
  width: 1.08rem;
  height: 1.08rem;
  border-radius: 4px;
  border: 1px solid color-mix(in srgb, #d7a84f 74%, var(--border) 26%);
  background: transparent;
  color: transparent;
  display: inline-grid;
  place-items: center;
  flex: 0 0 auto;
}

.rules-item-box.checked {
  background: color-mix(in srgb, #d7a84f 92%, #111 8%);
  color: #111;
}

.rules-item-text {
  font-size: 1.03rem;
  line-height: 1.25;
}

.rules-item.checked .rules-item-text {
  color: color-mix(in srgb, var(--text) 90%, #35d89e 10%);
}

.rules-saving,
.rules-empty {
  margin: 0;
  padding: 0.62rem 0.78rem;
  font-size: 0.74rem;
  color: var(--muted);
}

.rules-snapshot-failures {
  margin: 0.25rem 0.78rem 0.7rem;
  padding: 0.45rem 0.55rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--danger) 30%, transparent 70%);
  background: color-mix(in srgb, var(--danger) 8%, transparent 92%);
  display: grid;
  gap: 0.2rem;
}

.rules-snapshot-title {
  margin: 0;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: color-mix(in srgb, var(--danger) 84%, var(--text) 16%);
}

.rules-snapshot-row {
  margin: 0;
  font-size: 0.74rem;
  color: var(--text);
}
</style>
