<script setup lang="ts">
import RuleProgressHeader from '@/components/rules/RuleProgressHeader.vue'
import type { TradeChecklistReadiness } from '@/types/checklist'

defineProps<{
  readiness: TradeChecklistReadiness
}>()
</script>

<template>
  <RuleProgressHeader :readiness="readiness" />
</template>
