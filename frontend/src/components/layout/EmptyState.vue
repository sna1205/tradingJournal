<script setup lang="ts">
import type { Component } from 'vue'

withDefaults(
  defineProps<{
    title: string
    description: string
    icon?: Component
    ctaText?: string
  }>(),
  {
    icon: undefined,
    ctaText: '',
  }
)

const emit = defineEmits<{
  (event: 'cta'): void
}>()
</script>

<template>
  <div class="panel border-dashed p-8 text-center">
    <component :is="icon" v-if="icon" class="mx-auto mb-2 h-5 w-5 text-[var(--muted)]" />
    <h4 class="text-base font-semibold">{{ title }}</h4>
    <p class="mt-1 text-sm muted">{{ description }}</p>
    <button v-if="ctaText" class="btn btn-primary mt-4 px-4 py-2 text-sm" @click="emit('cta')">
      {{ ctaText }}
    </button>
  </div>
</template>
