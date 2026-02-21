<script setup lang="ts">
import { computed, useId } from 'vue'
import FieldWrapper from './FieldWrapper.vue'
import DatePopoverField from './DatePopoverField.vue'

const props = withDefaults(
  defineProps<{
    modelValue: string | null | undefined
    label: string
    required?: boolean
    error?: string
    hint?: string
    min?: string
    max?: string
    disabled?: boolean
    size?: 'sm' | 'md'
    showTodayShortcut?: boolean
  }>(),
  {
    required: false,
    disabled: false,
    size: 'md',
    showTodayShortcut: false,
  }
)

const emit = defineEmits<{
  (event: 'update:modelValue', value: string): void
}>()

const fallbackId = useId()
const controlId = computed(() => `date-${fallbackId}`)

function onUpdate(value: string) {
  emit('update:modelValue', value)
}

function setToday() {
  const now = new Date()
  const offset = now.getTimezoneOffset() * 60000
  const value = new Date(now.getTime() - offset).toISOString().slice(0, 10)
  emit('update:modelValue', value)
}
</script>

<template>
  <FieldWrapper :label="label" :required="required" :error="error" :hint="hint" :for-id="controlId">
    <DatePopoverField
      :id="controlId"
      :model-value="modelValue"
      :min="min"
      :max="max"
      :disabled="disabled"
      :size="size"
      :error="error"
      @update:model-value="onUpdate"
    />

    <div v-if="showTodayShortcut && !disabled" class="trade-filter-presets">
      <button type="button" class="chip-btn" @click="setToday">Today</button>
    </div>
  </FieldWrapper>
</template>
