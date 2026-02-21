<script setup lang="ts">
import { computed, useId } from 'vue'
import FieldWrapper from './FieldWrapper.vue'

const props = withDefaults(
  defineProps<{
    modelValue: string | number | null | undefined
    label: string
    type?: string
    required?: boolean
    placeholder?: string
    error?: string
    hint?: string
    min?: string | number
    max?: string | number
    step?: string | number
    disabled?: boolean
    rows?: number
    multiline?: boolean
    size?: 'sm' | 'md'
    autocomplete?: string
  }>(),
  {
    type: 'text',
    required: false,
    disabled: false,
    multiline: false,
    size: 'md',
    rows: 3,
    autocomplete: 'off',
  }
)

const emit = defineEmits<{
  (event: 'update:modelValue', value: string | number): void
}>()

const fallbackId = useId()
const controlId = computed(() => `field-${fallbackId}`)
const valueText = computed(() => `${props.modelValue ?? ''}`)
const controlClass = computed(() => ['field', props.size === 'sm' ? 'field-sm' : '', props.error ? 'field-invalid' : ''])

function onInput(event: Event) {
  const target = event.target as HTMLInputElement | HTMLTextAreaElement
  if (props.type === 'number' && !props.multiline) {
    emit('update:modelValue', target.value === '' ? '' : Number(target.value))
    return
  }

  emit('update:modelValue', target.value)
}
</script>

<template>
  <FieldWrapper :label="label" :required="required" :error="error" :hint="hint" :for-id="controlId">
    <textarea
      v-if="multiline"
      :id="controlId"
      :value="valueText"
      :required="required"
      :placeholder="placeholder"
      :rows="rows"
      :disabled="disabled"
      :class="controlClass"
      @input="onInput"
    />
    <input
      v-else
      :id="controlId"
      :value="valueText"
      :required="required"
      :type="type"
      :placeholder="placeholder"
      :min="min"
      :max="max"
      :step="step"
      :disabled="disabled"
      :autocomplete="autocomplete"
      :class="controlClass"
      @input="onInput"
    />
  </FieldWrapper>
</template>
