<script setup lang="ts">
import LotSizeCalculator from '@/components/tools/LotSizeCalculator.vue'
</script>

<template>
  <div class="space-y-4">
    <LotSizeCalculator mode="lots" title="Lots Calculate" subtitle="Compute instrument-aware lot size from account risk and stop distance." />
  </div>
</template>
