<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronDown } from 'lucide-vue-next'
import type { Checklist, TradeChecklistItemWithResponse, TradeChecklistReadiness, TradeChecklistResponseRecord } from '@/types/checklist'
import TradeChecklistBody from '@/components/checklists/TradeChecklistBody.vue'

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
    mode?: 'auto' | 'desktop' | 'mobile'
  }>(),
  {
    loading: false,
    saving: false,
    submitAttempted: false,
    strictMode: false,
    mode: 'auto',
  }
)

const emit = defineEmits<{
  (event: 'update-response', itemId: number, value: unknown): void
}>()

const mobileOpen = ref(props.mode === 'mobile')

const summaryToneClass = computed(() => {
  if (props.readiness.status === 'ready') return 'is-ready'
  if (props.readiness.status === 'almost') return 'is-almost'
  return 'is-not-ready'
})

const summaryLabel = computed(() => {
  if (props.readiness.status === 'ready') return 'Ready'
  if (props.readiness.status === 'almost') return 'Almost'
  return 'Not Ready'
})

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
      :loading="loading"
      :saving="saving"
      :submit-attempted="submitAttempted"
      :strict-mode="strictMode"
      :show-header="true"
      @update-response="(itemId, value) => emit('update-response', itemId, value)"
    />
  </aside>

  <section v-if="!isDesktopOnly" class="trade-checklist-mobile-accordion" :class="{ 'force-mobile': isMobileOnly }">
    <button
      type="button"
      class="trade-checklist-mobile-trigger"
      :class="summaryToneClass"
      :aria-expanded="mobileOpen"
      @click="mobileOpen = !mobileOpen"
    >
      <span class="trade-checklist-mobile-trigger-title">Pre-Trade Validation</span>
      <span class="trade-checklist-mobile-trigger-right">
        <strong>{{ readiness.completed_required }}/{{ readiness.total_required }}</strong>
        <em>{{ summaryLabel }}</em>
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
        :loading="loading"
        :saving="saving"
        :submit-attempted="submitAttempted"
        :strict-mode="strictMode"
        :show-header="false"
        @update-response="(itemId, value) => emit('update-response', itemId, value)"
      />
    </div>
  </section>
</template>

<style scoped>
.trade-checklist-panel-desktop {
  min-width: 0;
}

.trade-checklist-mobile-accordion {
  display: none;
}

.trade-checklist-mobile-trigger {
  width: 100%;
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--border) 30%, transparent 70%);
  background:
    linear-gradient(135deg, color-mix(in srgb, var(--primary-soft) 20%, var(--panel) 80%), var(--panel)),
    var(--panel);
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

.trade-checklist-mobile-trigger.is-ready {
  border-color: color-mix(in srgb, var(--success) 44%, var(--border) 56%);
}

.trade-checklist-mobile-trigger.is-almost {
  border-color: color-mix(in srgb, #d8ac4f 44%, var(--border) 56%);
}

.trade-checklist-mobile-trigger.is-not-ready {
  border-color: color-mix(in srgb, var(--danger) 42%, var(--border) 58%);
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
