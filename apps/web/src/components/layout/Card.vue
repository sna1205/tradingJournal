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
  <section class="panel panel-hover summary-card group" :style="highlightStyle">
    <div class="mb-4 flex items-center justify-between gap-3">
      <h3>{{ title }}</h3>
      <div
        v-if="icon"
        class="grid h-9 w-9 place-items-center rounded-xl border border-[var(--border)] bg-[var(--panel-soft)] text-[var(--muted)] transition-transform duration-200 ease-out group-hover:scale-105"
        :style="highlightColor ? { color: highlightColor } : {}"
      >
        <component :is="icon" class="h-4 w-4" />
      </div>
    </div>
    <slot />
  </section>
</template>
