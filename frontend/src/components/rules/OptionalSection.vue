<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next'

defineProps<{
  open: boolean
  count: number
}>()

const emit = defineEmits<{
  (event: 'toggle'): void
}>()
</script>

<template>
  <section class="checklist-optional">
    <button
      type="button"
      class="checklist-optional-trigger"
      :aria-expanded="open"
      @click="emit('toggle')"
    >
      <span>Optional ({{ count }})</span>
      <ChevronDown class="h-4 w-4" :class="{ 'rotate-180': open }" />
    </button>
    <div v-show="open" class="checklist-optional-content">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.checklist-optional {
  display: grid;
  gap: 0.42rem;
}

.checklist-optional-trigger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  width: 100%;
  border: 1px solid color-mix(in srgb, var(--border) 30%, transparent 70%);
  border-radius: 12px;
  background: color-mix(in srgb, var(--panel-soft) 48%, transparent 52%);
  padding: 0.48rem 0.62rem;
  font-size: 0.82rem;
  font-weight: 800;
  color: var(--text);
}

.checklist-optional-content {
  display: grid;
  gap: 0.5rem;
}

@media (max-width: 680px) {
  .checklist-optional-trigger {
    font-size: 0.78rem;
    padding: 0.44rem 0.58rem;
  }
}
</style>
