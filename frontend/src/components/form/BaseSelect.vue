<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { Check, ChevronDown, Search } from 'lucide-vue-next'
import FieldWrapper from './FieldWrapper.vue'

interface SelectOption {
  label: string
  value: string
  subtitle?: string
  badge?: string
  keywords?: string[]
}

const props = withDefaults(
  defineProps<{
    modelValue: string | null | undefined
    label: string
    options: SelectOption[]
    required?: boolean
    error?: string
    hint?: string
    size?: 'sm' | 'md'
    disabled?: boolean
    searchable?: boolean
    searchPlaceholder?: string
    emptyText?: string
  }>(),
  {
    required: false,
    size: 'md',
    disabled: false,
    searchable: false,
    searchPlaceholder: 'Search...',
    emptyText: 'No options found',
  }
)

const emit = defineEmits<{
  (event: 'update:modelValue', value: string): void
}>()

const fallbackId = useId()
const controlId = computed(() => `select-${fallbackId}`)
const currentValue = computed(() => props.modelValue ?? '')
const controlClass = computed(() => [
  'field',
  'control-modern',
  props.size === 'sm' ? 'field-sm' : '',
  props.error ? 'field-invalid' : '',
])
const rootRef = ref<HTMLElement | null>(null)
const searchInputRef = ref<HTMLInputElement | null>(null)
const isOpen = ref(false)
const searchTerm = ref('')
const filteredOptions = computed(() => {
  if (!props.searchable) return props.options

  const term = searchTerm.value.trim().toLowerCase()
  if (!term) return props.options

  return props.options.filter((option) => {
    if (option.label.toLowerCase().includes(term)) return true
    if ((option.subtitle ?? '').toLowerCase().includes(term)) return true
    return (option.keywords ?? []).some((keyword) => keyword.toLowerCase().includes(term))
  })
})
const selectedOption = computed(() =>
  props.options.find((option) => option.value === currentValue.value)
  ?? filteredOptions.value[0]
  ?? props.options[0]
)

function toggleOpen() {
  if (props.disabled) return
  isOpen.value = !isOpen.value
  if (isOpen.value && props.searchable) {
    void nextTick(() => searchInputRef.value?.focus())
  }
}

function closeMenu() {
  isOpen.value = false
  searchTerm.value = ''
}

function selectOption(value: string) {
  emit('update:modelValue', value)
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

function onKeydown(event: KeyboardEvent) {
  if (props.disabled) return

  if (event.key === 'Escape') {
    closeMenu()
    return
  }

  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    toggleOpen()
    return
  }

  if (!isOpen.value) return

  const index = filteredOptions.value.findIndex((option) => option.value === currentValue.value)

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    const next = filteredOptions.value[Math.min(index + 1, filteredOptions.value.length - 1)] ?? filteredOptions.value[0]
    if (next) emit('update:modelValue', next.value)
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault()
    const next = filteredOptions.value[Math.max(index - 1, 0)] ?? filteredOptions.value[0]
    if (next) emit('update:modelValue', next.value)
  }
}

function onSearchKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeMenu()
  }
}

function badgeClass(badge: string | undefined) {
  const tone = (badge ?? '').trim().toLowerCase()
  if (tone === 'funded' || tone === 'prop' || /^phase\s*\d+$/.test(tone)) return 'pill pill-badge-funded'
  if (tone === 'personal' || tone === 'live') return 'pill pill-badge-personal'
  if (tone === 'demo') return 'pill pill-badge-demo'
  if (tone === 'portfolio') return 'pill pill-badge-portfolio'
  return 'pill'
}
</script>

<template>
  <FieldWrapper :label="label" :required="required" :error="error" :hint="hint" :for-id="controlId">
    <div ref="rootRef" class="select-popover" :class="{ 'is-open': isOpen }">
      <button
        :id="controlId"
        type="button"
        :disabled="disabled"
        :class="[controlClass, 'select-trigger']"
        :aria-expanded="isOpen"
        aria-haspopup="listbox"
        @click="toggleOpen"
        @keydown="onKeydown"
      >
        <span class="select-trigger-value">{{ selectedOption?.label || 'Select' }}</span>
        <ChevronDown class="h-4 w-4" />
      </button>

      <Transition name="fade">
        <div v-if="isOpen" class="select-menu" role="listbox">
          <div v-if="searchable" class="select-search-shell">
            <Search class="h-4 w-4 select-search-icon" />
            <input
              ref="searchInputRef"
              v-model="searchTerm"
              type="text"
              class="field control-modern select-search-input"
              :placeholder="searchPlaceholder"
              @keydown="onSearchKeydown"
            />
          </div>

          <p v-if="filteredOptions.length === 0" class="select-empty-text">{{ emptyText }}</p>

          <button
            v-for="option in filteredOptions"
            :key="`${label}-${option.value}`"
            type="button"
            class="select-option"
            :class="{ 'is-active': option.value === currentValue }"
            :aria-selected="option.value === currentValue"
            @click="selectOption(option.value)"
          >
            <span class="select-option-copy">
              <span>{{ option.label }}</span>
              <small v-if="option.subtitle" class="select-option-subtitle">{{ option.subtitle }}</small>
            </span>
            <span v-if="option.badge" :class="badgeClass(option.badge)">{{ option.badge }}</span>
            <Check v-if="option.value === currentValue" class="h-4 w-4" />
          </button>
        </div>
      </Transition>
    </div>
  </FieldWrapper>
</template>
