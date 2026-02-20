<script setup lang="ts">
import type { Component } from 'vue'

const props = withDefaults(
  defineProps<{
    title: string
    icon?: Component
    highlightColor?: string
  }>(),
  {
    icon: undefined,
    highlightColor: '',
  }
)

const highlightStyle = props.highlightColor
  ? { borderColor: `${props.highlightColor}66` }
  : {}
</script>

<template>
  <section
    class="glass-card glass-card-hover group relative overflow-hidden rounded-2xl p-6"
    :style="highlightStyle"
  >
    <div class="mb-4 flex items-center gap-3">
      <div
        v-if="icon"
        class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-700/80 bg-slate-900/55 text-slate-200 transition-transform duration-200 ease-out group-hover:scale-105"
        :style="highlightColor ? { color: highlightColor } : {}"
      >
        <component :is="icon" class="h-4 w-4" />
      </div>
      <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{{ title }}</h3>
    </div>
    <slot />
  </section>
</template>
