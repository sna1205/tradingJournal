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
  <div class="glass-card rounded-2xl border border-dashed border-slate-700/80 p-8 text-center">
    <component :is="icon" v-if="icon" class="mx-auto mb-2 h-5 w-5 text-slate-400" />
    <h4 class="text-base font-semibold text-slate-100">{{ title }}</h4>
    <p class="mt-1 text-sm text-slate-400">{{ description }}</p>
    <button
      v-if="ctaText"
      class="mt-4 rounded-xl border border-emerald-400/70 bg-emerald-500/20 px-4 py-2 text-sm font-semibold text-emerald-100 transition hover:bg-emerald-500/35"
      @click="emit('cta')"
    >
      {{ ctaText }}
    </button>
  </div>
</template>
