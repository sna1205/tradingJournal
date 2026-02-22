<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    modelValue: string | null | undefined
    min?: string
    max?: string
    disabled?: boolean
    size?: 'sm' | 'md'
    error?: string
    id?: string
    placeholder?: string
  }>(),
  {
    disabled: false,
    size: 'md',
    placeholder: 'Select date',
  }
)

const emit = defineEmits<{
  (event: 'update:modelValue', value: string): void
}>()

type CalendarCell = {
  key: string
  iso?: string
  label?: number
  padding?: boolean
  disabled?: boolean
}

const weekdayLabels = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
const isOpen = ref(false)
const openUpward = ref(false)
const rootRef = ref<HTMLElement | null>(null)
const selectedValue = computed(() => props.modelValue ?? '')
const currentMonth = ref(startOfMonth(parseIsoDate(selectedValue.value) ?? new Date()))
const controlClass = computed(() => [
  'field',
  'control-modern',
  props.size === 'sm' ? 'field-sm' : '',
  props.error ? 'field-invalid' : '',
])
const monthLabel = computed(() =>
  currentMonth.value.toLocaleString('en-US', {
    month: 'long',
    year: 'numeric',
  })
)

watch(
  () => props.modelValue,
  (value) => {
    const parsed = parseIsoDate(value ?? '')
    if (parsed) {
      currentMonth.value = startOfMonth(parsed)
    }
  }
)

const selectedLabel = computed(() => {
  const selected = parseIsoDate(selectedValue.value)
  if (!selected) return props.placeholder
  return selected.toLocaleDateString('en-US', {
    month: '2-digit',
    day: '2-digit',
    year: 'numeric',
  })
})

const cells = computed<CalendarCell[]>(() => {
  const year = currentMonth.value.getFullYear()
  const month = currentMonth.value.getMonth()
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const firstWeekday = new Date(year, month, 1).getDay()
  const list: CalendarCell[] = []

  for (let i = 0; i < firstWeekday; i += 1) {
    list.push({ key: `pad-${year}-${month}-${i}`, padding: true })
  }

  for (let day = 1; day <= daysInMonth; day += 1) {
    const date = new Date(year, month, day)
    const iso = toIsoDate(date)
    list.push({
      key: iso,
      iso,
      label: day,
      disabled: isOutsideRange(iso),
    })
  }

  while (list.length % 7 !== 0) {
    list.push({ key: `tail-${year}-${month}-${list.length}`, padding: true })
  }

  return list
})

function parseIsoDate(value: string) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null
  const [yearRaw, monthRaw, dayRaw] = value.split('-')
  const year = Number(yearRaw)
  const month = Number(monthRaw)
  const day = Number(dayRaw)
  const date = new Date(year, month - 1, day)
  if (Number.isNaN(date.getTime())) return null
  return date
}

function toIsoDate(value: Date) {
  const year = value.getFullYear()
  const month = `${value.getMonth() + 1}`.padStart(2, '0')
  const day = `${value.getDate()}`.padStart(2, '0')
  return `${year}-${month}-${day}`
}

function startOfMonth(value: Date) {
  return new Date(value.getFullYear(), value.getMonth(), 1)
}

function isOutsideRange(iso: string) {
  if (props.min && iso < props.min) return true
  if (props.max && iso > props.max) return true
  return false
}

function toggleOpen() {
  if (props.disabled) return
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    void nextTick(() => {
      const rect = rootRef.value?.getBoundingClientRect()
      if (!rect) return
      const estimatedMenuHeight = 340
      openUpward.value = rect.bottom + estimatedMenuHeight > window.innerHeight - 12 && rect.top > estimatedMenuHeight
    })
  }
}

function closeMenu() {
  isOpen.value = false
}

function selectDate(iso: string | undefined) {
  if (!iso || isOutsideRange(iso)) return
  emit('update:modelValue', iso)
  closeMenu()
}

function prevMonth() {
  const value = currentMonth.value
  currentMonth.value = new Date(value.getFullYear(), value.getMonth() - 1, 1)
}

function nextMonth() {
  const value = currentMonth.value
  currentMonth.value = new Date(value.getFullYear(), value.getMonth() + 1, 1)
}

function setToday() {
  const today = toIsoDate(new Date())
  if (isOutsideRange(today)) return
  emit('update:modelValue', today)
  currentMonth.value = startOfMonth(new Date())
  closeMenu()
}

function clearDate() {
  emit('update:modelValue', '')
  closeMenu()
}

function onDocumentPointerDown(event: PointerEvent) {
  if (!rootRef.value) return
  const target = event.target as Node | null
  if (target && rootRef.value.contains(target)) return
  closeMenu()
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
})
</script>

<template>
  <div ref="rootRef" class="date-popover" :class="{ 'is-open': isOpen }">
    <button
      :id="id"
      type="button"
      :disabled="disabled"
      :class="[controlClass, 'date-trigger', { 'date-trigger-placeholder': !selectedValue }]"
      :aria-expanded="isOpen"
      aria-haspopup="dialog"
      @click="toggleOpen"
    >
      <span class="date-trigger-leading">
        <CalendarDays class="h-4 w-4" />
        <span>{{ selectedLabel }}</span>
      </span>
      <ChevronDown class="h-4 w-4" />
    </button>

    <Transition name="fade">
      <div v-if="isOpen" class="date-menu" :class="{ 'date-menu-up': openUpward }" role="dialog" aria-label="Date picker">
        <header class="date-menu-header">
          <button type="button" class="date-nav-btn" @click="prevMonth">
            <ChevronLeft class="h-4 w-4" />
          </button>
          <p class="date-menu-title">{{ monthLabel }}</p>
          <button type="button" class="date-nav-btn" @click="nextMonth">
            <ChevronRight class="h-4 w-4" />
          </button>
        </header>

        <div class="date-weekdays">
          <span v-for="day in weekdayLabels" :key="`weekday-${day}`">{{ day }}</span>
        </div>

        <div class="date-grid">
          <button
            v-for="cell in cells"
            :key="cell.key"
            type="button"
            class="date-cell"
            :class="{
              'is-padding': cell.padding,
              'is-selected': cell.iso && cell.iso === selectedValue,
              'is-disabled': cell.disabled,
            }"
            :disabled="cell.padding || cell.disabled"
            @click="selectDate(cell.iso)"
          >
            {{ cell.label ?? '' }}
          </button>
        </div>

        <footer class="date-menu-footer">
          <button type="button" class="chip-btn" @click="clearDate">Clear</button>
          <button type="button" class="chip-btn active" @click="setToday">Today</button>
        </footer>
      </div>
    </Transition>
  </div>
</template>
