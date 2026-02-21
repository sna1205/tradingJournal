<script setup lang="ts">
defineProps<{
  label: string
  error?: string
  hint?: string
  required?: boolean
  forId?: string
}>()
</script>

<template>
  <label class="form-field-shell" :for="forId">
    <span class="form-field-head">
      <span class="form-field-label">
        {{ label }}
        <span v-if="required" class="form-field-required">*</span>
      </span>
      <slot name="label-extra" />
    </span>

    <slot />

    <p v-if="error" class="field-error-text">{{ error }}</p>
    <p v-else-if="hint" class="field-hint-text">{{ hint }}</p>
  </label>
</template>
