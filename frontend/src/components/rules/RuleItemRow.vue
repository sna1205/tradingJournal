<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { PenSquare, Trash2, X } from 'lucide-vue-next'
import type { ChecklistItem } from '@/types/rules'

const props = defineProps<{
  item: ChecklistItem
  categories: string[]
  expanded: boolean
}>()

const emit = defineEmits<{
  (event: 'toggle-expand', itemId: number): void
  (event: 'update', itemId: number, payload: Record<string, unknown>): void
  (event: 'remove', itemId: number): void
}>()

const form = reactive({
  title: '',
  help_text: '',
})

const titleCount = computed(() => form.title.trim().length)
const whyCount = computed(() => form.help_text.trim().length)

watch(
  () => props.item,
  (value) => {
    form.title = value.title
    form.help_text = value.help_text ?? ''
  },
  { immediate: true, deep: true }
)

function toggleExpand() {
  emit('toggle-expand', props.item.id)
}

function closeModal() {
  emit('toggle-expand', props.item.id)
}

function submit() {
  emit('update', props.item.id, {
    title: form.title.trim() || props.item.title,
    help_text: form.help_text.trim() ? form.help_text.trim() : null,
  })
  closeModal()
}
</script>

<template>
  <article class="checklist-builder-item-row">
    <div>
      <p class="checklist-builder-rule-title">{{ form.title || 'Untitled rule' }}</p>
      <p v-if="form.help_text" class="checklist-builder-rule-note">{{ form.help_text }}</p>
    </div>

    <div class="checklist-builder-rule-meta">
      <button
        type="button"
        class="checklist-builder-rule-action"
        aria-label="Edit rule"
        @click.stop="toggleExpand"
      >
        <PenSquare class="h-4 w-4" />
      </button>
      <button
        type="button"
        class="checklist-builder-rule-action danger"
        aria-label="Delete rule"
        @click.stop="emit('remove', item.id)"
      >
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </article>

  <Teleport to="body">
    <Transition name="fade">
      <div v-if="expanded" class="rule-modal-overlay" @click.self="closeModal">
        <section class="rule-modal-panel">
          <header class="rule-modal-head">
            <div>
              <h3 class="section-title">Edit Trading Rule</h3>
              <p class="section-note">Write rules you can mark as Followed or Broken during your trading.</p>
            </div>
            <button type="button" class="rule-close" @click="closeModal" aria-label="Close">
              <X class="h-5 w-5" />
            </button>
          </header>

          <form class="rule-form" @submit.prevent="submit" @keydown.meta.enter.prevent="submit" @keydown.ctrl.enter.prevent="submit">
            <section class="rule-block">
              <label class="rule-label" for="edit-rule-title">Rule <span>(actionable)</span></label>
              <p class="rule-help">Write it so you can mark it as Followed or Broken.</p>
              <textarea
                id="edit-rule-title"
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
              <label class="rule-label" for="edit-rule-why">Why this rule matters <span>(optional)</span></label>
              <textarea
                id="edit-rule-why"
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
                <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="closeModal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 py-2 text-sm" :disabled="!form.title.trim()">
                  Save Rule
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
.checklist-builder-item-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: flex-start;
  gap: 0.55rem;
  padding: 0.72rem 0.7rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--border) 12%, transparent 88%);
  border-left: 2px solid color-mix(in srgb, var(--primary) 46%, transparent 54%);
  background: color-mix(in srgb, var(--panel-soft) 68%, var(--panel) 32%);
}

.checklist-builder-rule-title {
  margin: 0;
  min-width: 0;
  font-size: 0.9rem;
  font-weight: 700;
  line-height: 1.25;
}

.checklist-builder-rule-note {
  margin: 0.26rem 0 0;
  color: var(--muted);
  font-size: 0.72rem;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
  overflow: hidden;
  text-overflow: ellipsis;
}

.checklist-builder-rule-meta {
  display: inline-flex;
  align-items: center;
  gap: 0.42rem;
}

.checklist-builder-rule-action {
  width: 1.75rem;
  height: 1.75rem;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--muted);
  display: inline-grid;
  place-items: center;
}

.checklist-builder-rule-action:hover {
  background: color-mix(in srgb, var(--panel-soft) 30%, transparent 70%);
  color: var(--text);
}

.checklist-builder-rule-action.danger {
  color: color-mix(in srgb, var(--danger) 78%, var(--text) 22%);
}

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
