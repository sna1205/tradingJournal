<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <div class="glass-card rounded-2xl p-6">
    <h3 class="text-base font-bold">{{ title }}</h3>
    <p v-if="subtitle" class="mt-1 text-sm muted">{{ subtitle }}</p>
    <div class="mt-4">
      <slot />
    </div>
  </div>
</template>
