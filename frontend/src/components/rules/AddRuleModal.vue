<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { X } from 'lucide-vue-next'
import type { ChecklistItemType } from '@/types/rules'

const props = withDefaults(
  defineProps<{
    open: boolean
    categories: string[]
    saving?: boolean
  }>(),
  {
    open: false,
    saving: false,
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

const quickExamples = [
  'I have a defined entry, stop, and target before entry.',
  'I am trading in line with HTF bias.',
  'I am emotionally neutral before entering.',
  'I will not increase risk after a losing trade.',
  'I will stop after 2 losing trades.',
]

const form = reactive({
  title: '',
  help_text: '',
})

const titleCount = computed(() => form.title.trim().length)
const whyCount = computed(() => form.help_text.trim().length)
const defaultCategory = computed(() =>
  props.categories.find((value) => value.trim().length > 0)?.trim() || 'Risk & Compliance'
)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    form.title = ''
    form.help_text = ''
  }
)

function applyExample(example: string) {
  form.title = example
}

function submit() {
  if (props.saving) return
  if (!form.title.trim()) return

  emit('create', {
    title: form.title.trim(),
    type: 'checkbox',
    required: true,
    category: defaultCategory.value,
    help_text: form.help_text.trim() ? form.help_text.trim() : null,
    config: {
      weight: 'hard',
    },
    is_active: true,
  })
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="open" class="rule-modal-overlay" @click.self="emit('close')">
        <section class="rule-modal-panel">
          <header class="rule-modal-head">
            <div>
              <h3 class="section-title">Add Trading Rule</h3>
              <p class="section-note">Write rules you can mark as Followed or Broken during your trading.</p>
            </div>
            <button type="button" class="rule-close" @click="emit('close')" aria-label="Close">
              <X class="h-5 w-5" />
            </button>
          </header>

          <form class="rule-form" @submit.prevent="submit" @keydown.meta.enter.prevent="submit" @keydown.ctrl.enter.prevent="submit">
            <section class="rule-block">
              <label class="rule-label" for="new-rule-title">Rule <span>(actionable)</span></label>
              <p class="rule-help">Write it so you can mark it as Followed or Broken.</p>
              <textarea
                id="new-rule-title"
                v-model="form.title"
                class="rule-textarea primary"
                maxlength="120"
                rows="3"
                placeholder="e.g. I will not enter a trade without a defined stop loss"
                required
              />
              <p class="rule-counter">{{ titleCount }}/120</p>
            </section>

            <section class="rule-block">
              <p class="rule-kicker">Quick examples</p>
              <div class="rule-chips">
                <button
                  v-for="example in quickExamples"
                  :key="example"
                  type="button"
                  class="rule-chip"
                  @click="applyExample(example)"
                >
                  {{ example }}
                </button>
              </div>
            </section>

            <section class="rule-block">
              <label class="rule-label" for="new-rule-why">Why this rule matters <span>(optional)</span></label>
              <textarea
                id="new-rule-why"
                v-model="form.help_text"
                class="rule-textarea"
                maxlength="220"
                rows="3"
                placeholder="What problem does this rule protect you from? (e.g., revenge trading, FOMO, overtrading)"
              />
              <p class="rule-counter">{{ whyCount }}/220</p>
            </section>

            <footer class="rule-actions">
              <p class="rule-hint">⌘ + ↵ to save</p>
              <div class="rule-actions-right">
                <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="emit('close')">Cancel</button>
                <button
                  type="submit"
                  class="btn btn-primary px-4 py-2 text-sm"
                  :disabled="props.saving || !form.title.trim()"
                >
                  {{ props.saving ? 'Saving...' : 'Save Rule' }}
                </button>
              </div>
            </footer>
          </form>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.rule-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: grid;
  justify-items: center;
  align-items: start;
  overflow-y: auto;
  padding: 4.5rem 1rem 1rem;
  background: color-mix(in srgb, var(--text) 52%, transparent 48%);
  backdrop-filter: blur(4px);
}

.rule-modal-panel {
  width: min(680px, 100%);
  max-height: calc(100vh - 5.5rem);
  overflow: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--border) 20%, transparent 80%);
  background: color-mix(in srgb, var(--panel-strong) 88%, var(--panel-soft) 12%);
  padding: 1rem;
  box-shadow: 0 24px 80px color-mix(in srgb, var(--text) 28%, transparent 72%);
}

.rule-modal-panel::-webkit-scrollbar {
  width: 0;
  height: 0;
}

.rule-modal-head {
  display: flex;
  justify-content: space-between;
  gap: 0.8rem;
}

.rule-close {
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 20%, transparent 80%);
  background: transparent;
  color: var(--muted);
  display: inline-grid;
  place-items: center;
}

.rule-close:hover {
  color: var(--text);
  border-color: color-mix(in srgb, var(--primary) 35%, var(--border) 65%);
}

.rule-form {
  margin-top: 0.8rem;
  display: grid;
  gap: 0.7rem;
}

.rule-block {
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 14%, transparent 86%);
  background: color-mix(in srgb, var(--panel) 78%, var(--panel-soft) 22%);
  padding: 0.75rem;
}

.rule-label {
  display: block;
  margin: 0;
  font-size: 1.02rem;
  font-weight: 700;
}

.rule-label span {
  color: var(--muted);
  font-weight: 500;
}

.rule-help {
  margin: 0.32rem 0 0.55rem;
  color: var(--muted);
  font-size: 0.78rem;
}

.rule-kicker {
  margin: 0 0 0.55rem;
  color: var(--muted);
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.rule-textarea {
  width: 100%;
  min-height: 5rem;
  resize: vertical;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 24%, transparent 76%);
  background: color-mix(in srgb, var(--panel) 84%, var(--panel-soft) 16%);
  color: var(--text);
  padding: 0.75rem;
  font-family: var(--font-body);
  font-size: 0.98rem;
}

.rule-textarea.primary {
  border-color: color-mix(in srgb, var(--primary) 60%, var(--border) 40%);
}

.rule-counter {
  margin: 0.5rem 0 0;
  text-align: right;
  color: var(--muted);
  font-size: 0.8rem;
}

.rule-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.rule-chip {
  max-width: 260px;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--border) 14%, transparent 86%);
  background: color-mix(in srgb, var(--panel) 82%, var(--panel-soft) 18%);
  color: var(--text);
  padding: 0.42rem 0.6rem;
  font-size: 0.78rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.rule-chip:hover {
  border-color: color-mix(in srgb, var(--primary) 32%, var(--border) 68%);
}

.rule-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.2rem 0.1rem 0;
}

.rule-actions-right {
  display: inline-flex;
  gap: 0.5rem;
}

.rule-hint {
  margin: 0;
  color: var(--muted);
  font-size: 0.78rem;
}

@media (max-width: 640px) {
  .rule-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .rule-actions-right {
    justify-content: flex-end;
  }
}
</style>
