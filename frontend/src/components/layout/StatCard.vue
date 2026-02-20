<script setup lang="ts">
defineProps<{
  label: string
  value: string
  tone?: 'neutral' | 'positive' | 'negative'
}>()
</script>

<template>
  <div
    class="glass-card glass-card-hover rounded-2xl border p-6"
    :class="{
      'border-slate-700/80': !tone || tone === 'neutral',
      'border-emerald-500/35': tone === 'positive',
      'border-rose-300/30': tone === 'negative',
    }"
  >
    <p class="text-xs uppercase tracking-[0.2em] text-slate-400">{{ label }}</p>
    <p class="number-display mt-3 text-xl font-semibold md:text-2xl">{{ value }}</p>
  </div>
</template>
