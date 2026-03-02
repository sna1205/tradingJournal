<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { X } from 'lucide-vue-next'
import type { ChecklistItemType } from '@/types/checklist'

const props = withDefaults(
  defineProps<{
    open: boolean
    categories: string[]
  }>(),
  {
    open: false,
  }
)

const emit = defineEmits<{
  (event: 'close'): void
  (event: 'create', payload: {
    title: string
    type: ChecklistItemType
    required: boolean
    category: string
    help_text: string | null
    config: Record<string, unknown>
    is_active: boolean
  }): void
}>()

const typeOptions: Array<{ label: string; value: ChecklistItemType }> = [
  { label: 'Checkbox', value: 'checkbox' },
  { label: 'Dropdown', value: 'dropdown' },
  { label: 'Number', value: 'number' },
  { label: 'Text', value: 'text' },
]

const categoryOptions = computed(() => {
  const values = props.categories.filter((value) => value.trim().length > 0)
  if (values.length === 0) {
    return ['Context']
  }
  return values
})

const form = reactive({
  title: '',
  type: 'checkbox' as ChecklistItemType,
  required: true,
  category: 'Context',
  help_text: '',
  dropdown_options: '',
  number_min: '',
  number_max: '',
})

watch(
  () => props.open,
  (open) => {
    if (!open) return
    form.title = ''
    form.type = 'checkbox'
    form.required = true
    form.category = categoryOptions.value[0] ?? 'Context'
    form.help_text = ''
    form.dropdown_options = ''
    form.number_min = ''
    form.number_max = ''
  }
)

function configPayload(): Record<string, unknown> {
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

  if (form.type === 'text') {
    return { maxLength: 240 }
  }

  return {}
}

function submit() {
  if (!form.title.trim()) return
  emit('create', {
    title: form.title.trim(),
    type: form.type,
    required: form.required,
    category: form.category.trim() || 'Context',
    help_text: form.help_text.trim() ? form.help_text.trim() : null,
    config: configPayload(),
    is_active: true,
  })
}
</script>

<template>
  <Transition name="fade">
    <div v-if="open" class="app-modal-overlay checklist-item-modal-overlay" @click.self="emit('close')">
      <section class="panel checklist-item-modal">
        <header class="checklist-modal-head">
          <div>
            <h3 class="section-title">Add Rule</h3>
            <p class="section-note">Create a new pre-trade validation rule.</p>
          </div>
          <button type="button" class="btn btn-ghost p-2" @click="emit('close')">
            <X class="h-4 w-4" />
          </button>
        </header>

        <form class="checklist-modal-form" @submit.prevent="submit">
          <div class="checklist-modal-grid">
            <label>
              <span>Title</span>
              <input v-model="form.title" class="checklist-mini-input" type="text" required placeholder="Rule title">
            </label>

            <label>
              <span>Type</span>
              <select v-model="form.type" class="checklist-mini-select">
                <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </label>

            <label>
              <span>Category</span>
              <input v-model="form.category" class="checklist-mini-input" list="builder-categories" placeholder="Risk">
              <datalist id="builder-categories">
                <option v-for="entry in categoryOptions" :key="`builder-category-${entry}`" :value="entry" />
              </datalist>
            </label>

            <label class="checklist-pill-toggle on">
              <input v-model="form.required" type="checkbox">
              Required
            </label>

            <label class="full">
              <span>Help text (optional)</span>
              <input v-model="form.help_text" class="checklist-mini-input" type="text" placeholder="Shown in tooltip on trade form">
            </label>

            <label v-if="form.type === 'dropdown'" class="full">
              <span>Options</span>
              <input v-model="form.dropdown_options" class="checklist-mini-input" type="text" placeholder="A+, A, B">
            </label>

            <template v-if="form.type === 'number'">
              <label>
                <span>Min</span>
                <input v-model="form.number_min" class="checklist-mini-input" type="number" placeholder="0">
              </label>
              <label>
                <span>Max</span>
                <input v-model="form.number_max" class="checklist-mini-input" type="number" placeholder="1">
              </label>
            </template>
          </div>

          <div class="checklist-modal-actions">
            <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="emit('close')">Cancel</button>
            <button type="submit" class="btn btn-primary px-4 py-2 text-sm">Add Rule</button>
          </div>
        </form>
      </section>
    </div>
  </Transition>
</template>
