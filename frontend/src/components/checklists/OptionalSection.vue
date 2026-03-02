<script setup lang="ts">
import RulesOptionalSection from '@/components/rules/OptionalSection.vue'

withDefaults(
  defineProps<{
    open?: boolean
    count?: number
    title?: string
  }>(),
  {
    open: false,
    count: 0,
    title: 'Optional',
  }
)

const emit = defineEmits<{
  (event: 'toggle'): void
}>()
</script>

<template>
  <RulesOptionalSection :open="open" :count="count" :title="title" @toggle="emit('toggle')">
    <slot />
  </RulesOptionalSection>
</template>
