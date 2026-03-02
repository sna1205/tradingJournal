<script setup lang="ts">
import TradeRulesPanel from '@/components/rules/TradeRulesPanel.vue'
import type {
  Checklist,
  TradeChecklistItemWithResponse,
  TradeChecklistReadiness,
  TradeChecklistResponseRecord,
} from '@/types/checklist'

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
</script>

<template>
  <TradeRulesPanel
    :checklist="props.checklist"
    :required-items="props.requiredItems"
    :optional-items="props.optionalItems"
    :archived-responses="props.archivedResponses"
    :readiness="props.readiness"
    :loading="props.loading"
    :saving="props.saving"
    :submit-attempted="props.submitAttempted"
    :strict-mode="props.strictMode"
    :mode="props.mode"
    @update-response="(itemId, value) => emit('update-response', itemId, value)"
  />
</template>
