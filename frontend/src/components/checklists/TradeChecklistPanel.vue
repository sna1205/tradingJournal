<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronDown } from 'lucide-vue-next'
import type {
  Checklist,
  TradeChecklistExecutionSnapshot,
  TradeChecklistItemWithResponse,
  TradeChecklistReadiness,
  TradeChecklistResponseRecord,
} from '@/types/checklist'
import type { TradePrecheckResult } from '@/stores/tradeStore'
import TradeChecklistBody from '@/components/checklists/TradeChecklistBody.vue'

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
    mode?: 'auto' | 'desktop' | 'mobile'
    riskPrecheck?: TradePrecheckResult | null
  }>(),
  {
    loading: false,
    saving: false,
    submitAttempted: false,
    strictMode: false,
    mode: 'auto',
    riskPrecheck: null,
    executionSnapshot: null,
  }
)

const emit = defineEmits<{
  (event: 'update-response', itemId: number, value: unknown): void
  (event: 'evaluation-change', payload: { failedRequiredIds: number[]; firstFailingId: number | null }): void
}>()

const mobileOpen = ref(props.mode === 'mobile')
const summaryCount = computed(() => `${props.readiness.completed_required}/${props.readiness.total_required}`)

const isDesktopOnly = computed(() => props.mode === 'desktop')
const isMobileOnly = computed(() => props.mode === 'mobile')
</script>

<template>
  <aside v-if="!isMobileOnly" class="trade-checklist-panel-desktop" :class="{ 'force-desktop': isDesktopOnly }">
    <TradeChecklistBody
      :checklist="checklist"
      :required-items="requiredItems"
      :optional-items="optionalItems"
      :archived-responses="archivedResponses"
      :readiness="readiness"
      :execution-snapshot="executionSnapshot"
      :loading="loading"
      :saving="saving"
      :submit-attempted="submitAttempted"
      :strict-mode="strictMode"
      :risk-precheck="riskPrecheck"
      :show-header="true"
      @update-response="(itemId, value) => emit('update-response', itemId, value)"
      @evaluation-change="(payload) => emit('evaluation-change', payload)"
    />
  </aside>

  <section v-if="!isDesktopOnly" class="trade-checklist-mobile-accordion" :class="{ 'force-mobile': isMobileOnly }">
    <button
      type="button"
      class="trade-checklist-mobile-trigger"
      :aria-expanded="mobileOpen"
      @click="mobileOpen = !mobileOpen"
    >
      <span class="trade-checklist-mobile-trigger-title">Rules Checklist</span>
      <span class="trade-checklist-mobile-trigger-right">
        <strong>{{ summaryCount }}</strong>
        <em>checked</em>
        <ChevronDown class="h-4 w-4" :class="{ 'rotate-180': mobileOpen }" />
      </span>
    </button>

    <div v-show="mobileOpen" class="trade-checklist-mobile-content">
      <TradeChecklistBody
        :checklist="checklist"
        :required-items="requiredItems"
        :optional-items="optionalItems"
        :archived-responses="archivedResponses"
        :readiness="readiness"
        :execution-snapshot="executionSnapshot"
        :loading="loading"
        :saving="saving"
        :submit-attempted="submitAttempted"
        :strict-mode="strictMode"
        :risk-precheck="riskPrecheck"
        :show-header="false"
        @update-response="(itemId, value) => emit('update-response', itemId, value)"
        @evaluation-change="(payload) => emit('evaluation-change', payload)"
      />
    </div>
  </section>
</template>

<style scoped>
.trade-checklist-panel-desktop {
  min-width: 0;
  position: sticky;
  top: 5.8rem;
  align-self: flex-start;
}

.trade-checklist-mobile-accordion {
  display: none;
}

.trade-checklist-mobile-trigger {
  width: 100%;
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--border) 30%, transparent 70%);
  background: color-mix(in srgb, #01050a 88%, var(--panel) 12%);
  padding: 0.58rem 0.66rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
}

.trade-checklist-mobile-trigger-title {
  font-size: 0.84rem;
  font-weight: 700;
}

.trade-checklist-mobile-trigger-right {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.trade-checklist-mobile-trigger-right strong {
  font-size: 0.74rem;
  font-weight: 800;
  color: color-mix(in srgb, #35d89e 84%, var(--text) 16%);
}

.trade-checklist-mobile-trigger-right em {
  font-size: 0.7rem;
  font-style: normal;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.trade-checklist-mobile-content {
  margin-top: 0.5rem;
}

@media (max-width: 1199px) {
  .trade-checklist-mobile-accordion,
  .trade-checklist-mobile-accordion.force-mobile {
    display: block;
  }

  .trade-checklist-panel-desktop {
    display: none;
  }
}

@media (min-width: 1200px) {
  .trade-checklist-mobile-accordion {
    display: none;
  }

  .trade-checklist-panel-desktop,
  .trade-checklist-panel-desktop.force-desktop {
    display: block;
  }
}
</style>
