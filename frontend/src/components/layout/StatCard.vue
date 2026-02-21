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
    :style="{
      borderColor:
        tone === 'positive'
          ? 'color-mix(in srgb, var(--primary) 44%, var(--border) 56%)'
          : tone === 'negative'
            ? 'color-mix(in srgb, var(--danger) 44%, var(--border) 56%)'
            : 'var(--border)',
    }"
  >
    <p class="text-xs uppercase tracking-[0.2em] muted">{{ label }}</p>
    <p class="number-display mt-3 text-xl font-semibold md:text-2xl">{{ value }}</p>
  </div>
</template>
