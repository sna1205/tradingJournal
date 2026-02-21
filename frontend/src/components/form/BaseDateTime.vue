<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { Clock3 } from 'lucide-vue-next'
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
    size?: 'sm' | 'md'
    disabled?: boolean
    showQuickActions?: boolean
  }>(),
  {
    required: false,
    size: 'md',
    disabled: false,
    showQuickActions: true,
  }
)

const emit = defineEmits<{
  (event: 'update:modelValue', value: string): void
}>()

const fallbackId = useId()
const controlId = computed(() => `datetime-${fallbackId}`)
const valueText = computed(() => props.modelValue ?? '')
const dateValue = computed(() => valueText.value.slice(0, 10))
const timeValue = computed(() => valueText.value.slice(11, 16))
const dateControlId = computed(() => `${controlId.value}-date`)
const timeControlId = computed(() => `${controlId.value}-time`)
const controlClass = computed(() => [
  'field',
  'control-modern',
  props.size === 'sm' ? 'field-sm' : '',
  props.error ? 'field-invalid' : '',
])
const minDate = computed(() => (props.min ? props.min.slice(0, 10) : ''))
const maxDate = computed(() => (props.max ? props.max.slice(0, 10) : ''))
const timeText = ref('')
const isEditingTime = ref(false)

watch(
  timeValue,
  (value) => {
    if (isEditingTime.value) return
    timeText.value = value || toLocalDateTime(new Date()).slice(11, 16)
  },
  { immediate: true }
)

function toLocalDateTime(value: Date) {
  const offset = value.getTimezoneOffset() * 60000
  return new Date(value.getTime() - offset).toISOString().slice(0, 16)
}

function parseDateTime(value: string | null | undefined) {
  if (!value) return null
  const timestamp = new Date(value).getTime()
  if (Number.isNaN(timestamp)) return null
  return timestamp
}

function parseTimeText(value: string): string | null {
  const normalized = value.trim().toUpperCase()
  if (!normalized) return null

  const withMeridiem = normalized.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/)
  if (withMeridiem) {
    let hour = Number(withMeridiem[1])
    const minute = Number(withMeridiem[2])
    const meridiem = withMeridiem[3]

    if (!Number.isInteger(hour) || !Number.isInteger(minute) || hour < 1 || hour > 12 || minute < 0 || minute > 59) {
      return null
    }

    if (meridiem === 'AM') {
      hour = hour === 12 ? 0 : hour
    } else {
      hour = hour === 12 ? 12 : hour + 12
    }

    return `${`${hour}`.padStart(2, '0')}:${`${minute}`.padStart(2, '0')}`
  }

  const twentyFourHour = normalized.match(/^(\d{1,2}):(\d{2})$/)
  if (!twentyFourHour) return null

  const hour = Number(twentyFourHour[1])
  const minute = Number(twentyFourHour[2])

  if (!Number.isInteger(hour) || !Number.isInteger(minute) || hour < 0 || hour > 23 || minute < 0 || minute > 59) {
    return null
  }

  return `${`${hour}`.padStart(2, '0')}:${`${minute}`.padStart(2, '0')}`
}

function applyNextValue(value: string) {
  if (!value) {
    emit('update:modelValue', '')
    return
  }

  const timestamp = parseDateTime(value)
  if (timestamp === null) {
    emit('update:modelValue', value)
    return
  }

  const min = parseDateTime(props.min)
  const max = parseDateTime(props.max)
  let next = timestamp

  if (min !== null && next < min) {
    next = min
  }
  if (max !== null && next > max) {
    next = max
  }

  emit('update:modelValue', toLocalDateTime(new Date(next)))
}

function onDateSelect(date: string) {
  if (!date) {
    emit('update:modelValue', '')
    return
  }

  const fallbackTime = timeValue.value || toLocalDateTime(new Date()).slice(11, 16)
  applyNextValue(`${date}T${fallbackTime}`)
}

function onTimeInput(event: Event) {
  const target = event.target as HTMLInputElement
  timeText.value = target.value
}

function onTimeFocus() {
  isEditingTime.value = true
}

function onTimeBlur() {
  isEditingTime.value = false
  const parsed = parseTimeText(timeText.value)
  if (!parsed) {
    timeText.value = timeValue.value || toLocalDateTime(new Date()).slice(11, 16)
    return
  }

  const fallbackDate = dateValue.value || toLocalDateTime(new Date()).slice(0, 10)
  applyNextValue(`${fallbackDate}T${parsed}`)
}

function setNow() {
  emit('update:modelValue', toLocalDateTime(new Date()))
}

function nudge(minutes: number) {
  const current = parseDateTime(props.modelValue) ?? Date.now()
  const min = parseDateTime(props.min)
  const max = parseDateTime(props.max)

  let next = current + minutes * 60_000
  if (min !== null) {
    next = Math.max(next, min)
  }
  if (max !== null) {
    next = Math.min(next, max)
  }

  applyNextValue(toLocalDateTime(new Date(next)))
}
</script>

<template>
  <FieldWrapper :label="label" :required="required" :error="error" :hint="hint" :for-id="dateControlId">
    <div class="date-time-grid">
      <DatePopoverField
        :id="dateControlId"
        :model-value="dateValue"
        :min="minDate"
        :max="maxDate"
        :disabled="disabled"
        :size="size"
        :error="error"
        @update:model-value="onDateSelect"
      />

      <div class="field-shell">
        <span class="field-shell-icon field-shell-icon-left" aria-hidden="true">
          <Clock3 class="h-4 w-4" />
        </span>
        <input
          :id="timeControlId"
          :value="timeText"
          :required="required"
          type="text"
          inputmode="numeric"
          placeholder="13:37 or 1:37 PM"
          :disabled="disabled"
          :class="[controlClass, 'field-with-leading-icon']"
          @input="onTimeInput"
          @focus="onTimeFocus"
          @blur="onTimeBlur"
        />
      </div>
    </div>

    <div v-if="showQuickActions && !disabled" class="trade-filter-presets">
      <button type="button" class="chip-btn" @click="nudge(-60)">-1h</button>
      <button type="button" class="chip-btn" @click="nudge(-15)">-15m</button>
      <button type="button" class="chip-btn active" @click="setNow">Now</button>
    </div>
  </FieldWrapper>
</template>
