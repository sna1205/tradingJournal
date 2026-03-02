<script setup lang="ts">
import { reactive, watch } from 'vue'
import { GripVertical, MoreHorizontal, Trash2 } from 'lucide-vue-next'
import type { ChecklistItem, ChecklistItemType } from '@/types/checklist'

const props = defineProps<{
  item: ChecklistItem
  categories: string[]
  expanded: boolean
}>()

const emit = defineEmits<{
  (event: 'toggle-expand', itemId: number): void
  (event: 'update', itemId: number, payload: Record<string, unknown>): void
  (event: 'remove', itemId: number): void
  (event: 'drag-start', itemId: number): void
  (event: 'drag-over', itemId: number): void
  (event: 'drop', itemId: number): void
}>()

const form = reactive({
  title: '',
  type: 'checkbox' as ChecklistItemType,
  category: '',
  required: false,
  help_text: '',
  dropdown_options: '',
  number_min: '',
  number_max: '',
})

let hydrating = false

function hydrateFromProps() {
  hydrating = true
  form.title = props.item.title
  form.type = props.item.type
  form.category = props.item.category || 'Context'
  form.required = props.item.required
  form.help_text = props.item.help_text ?? ''

  const config = props.item.config as Record<string, unknown>
  form.dropdown_options = Array.isArray(config.options)
    ? config.options.map((entry) => String(entry)).join(', ')
    : ''
  form.number_min = config.min !== undefined ? String(config.min) : ''
  form.number_max = config.max !== undefined ? String(config.max) : ''

  queueMicrotask(() => {
    hydrating = false
  })
}

watch(() => props.item, hydrateFromProps, { immediate: true, deep: true })

function toConfig(): Record<string, unknown> {
  if (form.type === 'dropdown') {
    return {
      options: form.dropdown_options
        .split(',')
        .map((value) => value.trim())
        .filter((value) => value.length > 0),
    }
  }

  if (form.type === 'number') {
    const payload: Record<string, unknown> = {}
    if (form.number_min.trim()) payload.min = Number(form.number_min)
    if (form.number_max.trim()) payload.max = Number(form.number_max)
    return payload
  }

  return {}
}

function saveNow() {
  if (hydrating) return

  emit('update', props.item.id, {
    title: form.title.trim() || props.item.title,
    type: form.type,
    category: form.category.trim() || 'Context',
    required: form.required,
    help_text: form.help_text.trim() ? form.help_text.trim() : null,
    config: toConfig(),
  })
}

function onDragStart(event: DragEvent) {
  event.dataTransfer?.setData('text/plain', String(props.item.id))
  emit('drag-start', props.item.id)
}

function onDragOver(event: DragEvent) {
  event.preventDefault()
  emit('drag-over', props.item.id)
}

function onDrop(event: DragEvent) {
  event.preventDefault()
  emit('drop', props.item.id)
}

function toggleExpand() {
  emit('toggle-expand', props.item.id)
}
</script>

<template>
  <article
    class="checklist-item-card compact"
    :class="{ expanded: expanded }"
    draggable="true"
    @dragstart="onDragStart"
    @dragover="onDragOver"
    @drop="onDrop"
  >
    <div class="checklist-item-card-top" @click="toggleExpand">
      <button type="button" class="checklist-item-drag" aria-label="Drag rule" @click.stop>
        <GripVertical class="h-4 w-4" />
      </button>

      <p class="checklist-rule-title">{{ form.title || 'Untitled rule' }}</p>

      <button type="button" class="checklist-rule-menu" aria-label="Rule options" @click.stop="toggleExpand">
        <MoreHorizontal class="h-4 w-4" />
      </button>
    </div>

    <div class="checklist-item-row-required">
      <label class="checklist-pill-toggle subtle" :class="{ on: form.required }">
        <input v-model="form.required" type="checkbox" @change="saveNow">
        Required
      </label>
    </div>

    <div v-if="expanded" class="checklist-item-card-expanded">
      <div class="checklist-item-expanded-grid">
        <label>
          <span>Rule title</span>
          <input
            v-model="form.title"
            type="text"
            class="checklist-mini-input"
            placeholder="Rule title"
            @blur="saveNow"
            @keydown.enter.prevent="saveNow"
          >
        </label>

        <label>
          <span>Type</span>
          <select v-model="form.type" class="checklist-mini-select" aria-label="Rule type" @change="saveNow">
            <option value="checkbox">Checkbox</option>
            <option value="dropdown">Dropdown</option>
            <option value="number">Number</option>
            <option value="text">Text</option>
            <option value="scale">Scale</option>
          </select>
        </label>

        <label>
          <span>Category</span>
          <select v-model="form.category" class="checklist-mini-select" @change="saveNow">
            <option v-for="category in categories" :key="`category-${item.id}-${category}`" :value="category">
              {{ category }}
            </option>
          </select>
        </label>
      </div>

      <details class="checklist-item-card-advanced">
        <summary>Advanced</summary>
        <div class="checklist-item-card-advanced-grid">
          <input
            v-model="form.help_text"
            type="text"
            class="checklist-mini-input"
            placeholder="Help text"
            @blur="saveNow"
            @keydown.enter.prevent="saveNow"
          >

          <template v-if="form.type === 'dropdown'">
            <input
              v-model="form.dropdown_options"
              type="text"
              class="checklist-mini-input"
              placeholder="Options: A+, A, B"
              @blur="saveNow"
              @keydown.enter.prevent="saveNow"
            >
          </template>

          <template v-if="form.type === 'number'">
            <input
              v-model="form.number_min"
              type="number"
              class="checklist-mini-input"
              placeholder="Min"
              @blur="saveNow"
              @keydown.enter.prevent="saveNow"
            >
            <input
              v-model="form.number_max"
              type="number"
              class="checklist-mini-input"
              placeholder="Max"
              @blur="saveNow"
              @keydown.enter.prevent="saveNow"
            >
          </template>
        </div>
      </details>

      <div class="checklist-item-expanded-actions">
        <button
          type="button"
          class="btn btn-ghost is-danger px-3 py-2 text-sm"
          aria-label="Delete rule"
          @click="emit('remove', item.id)"
        >
          <Trash2 class="h-4 w-4" />
          Delete rule
        </button>
      </div>
    </div>
  </article>
</template>
