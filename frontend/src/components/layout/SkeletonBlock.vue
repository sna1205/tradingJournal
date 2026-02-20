<script setup lang="ts">
withDefaults(
  defineProps<{
    heightClass?: string
    roundedClass?: string
  }>(),
  {
    heightClass: 'h-5',
    roundedClass: 'rounded-lg',
  }
)
</script>

<template>
  <div class="skeleton-shimmer w-full" :class="[heightClass, roundedClass]" aria-hidden="true" />
</template>
