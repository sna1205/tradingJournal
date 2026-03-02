<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronDown } from 'lucide-vue-next'
import type {
  Checklist,
  TradeChecklistExecutionSnapshot,
  TradeChecklistItemWithResponse,
  TradeChecklistReadiness,
  TradeChecklistResponsePayload,
  TradeChecklistResponseRecord,
} from '@/types/rules'
import type { TradePrecheckResult } from '@/stores/tradeStore'
import TradeRulesBody from '@/components/rules/TradeRulesBody.vue'

const props = withDefaults(
  defineProps<{
    checklist: Checklist | null
    requiredItems: TradeChecklistItemWithResponse[]
    optionalItems: TradeChecklistItemWithResponse[]
    archivedResponses: TradeChecklistResponseRecord[]
    readiness: TradeChecklistReadiness
    serverReadiness?: TradeChecklistReadiness
    serverReadinessMismatch?: boolean
    serverReadinessReasons?: TradeChecklistResponsePayload['failing_rules']
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
    serverReadiness: undefined,
    serverReadinessMismatch: false,
    serverReadinessReasons: () => [],
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
    <TradeRulesBody
      :checklist="checklist"
      :required-items="requiredItems"
      :optional-items="optionalItems"
      :archived-responses="archivedResponses"
      :readiness="readiness"
      :server-readiness="serverReadiness ?? readiness"
      :server-readiness-mismatch="serverReadinessMismatch"
      :server-readiness-reasons="serverReadinessReasons ?? []"
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
      <span class="trade-checklist-mobile-trigger-title">Rules</span>
      <span class="trade-checklist-mobile-trigger-right">
        <strong>{{ summaryCount }}</strong>
        <em>checked</em>
        <ChevronDown class="h-4 w-4" :class="{ 'rotate-180': mobileOpen }" />
      </span>
    </button>

    <div v-show="mobileOpen" class="trade-checklist-mobile-content">
      <TradeRulesBody
        :checklist="checklist"
        :required-items="requiredItems"
        :optional-items="optionalItems"
        :archived-responses="archivedResponses"
        :readiness="readiness"
        :server-readiness="serverReadiness ?? readiness"
        :server-readiness-mismatch="serverReadinessMismatch"
        :server-readiness-reasons="serverReadinessReasons ?? []"
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
  border: 1px solid color-mix(in srgb, var(--border) 70%, transparent 30%);
  background:
    linear-gradient(145deg, color-mix(in srgb, var(--panel-soft) 64%, var(--panel) 36%), var(--panel)),
    var(--panel);
  color: var(--text);
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
  color: color-mix(in srgb, var(--primary) 80%, var(--text) 20%);
}

.trade-checklist-mobile-trigger-right em {
  font-size: 0.7rem;
  font-style: normal;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: var(--muted);
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
