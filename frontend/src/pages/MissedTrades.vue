<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { CalendarX2, Pencil, Plus, Trash2, X } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import { useMissedTradeStore } from '@/stores/missedTradeStore'
import { useUiStore } from '@/stores/uiStore'
import type { MissedTrade } from '@/types/trade'
import { asDate } from '@/utils/format'

const missedTradeStore = useMissedTradeStore()
const uiStore = useUiStore()
const { missedTrades, pagination, filters, loading, saving, hasFilters } = storeToRefs(missedTradeStore)

const isEditing = ref(false)
const editingId = ref<number | null>(null)
const customTag = ref('')

const reasonTagOptions = [
  'late-entry',
  'fear',
  'hesitation',
  'no-plan',
  'overtrading',
  'news-volatility',
  'session:london',
  'session:new-york',
  'session:asia',
]

const form = reactive({
  pair: '',
  model: '',
  date: '',
  notes: '',
  tags: [] as string[],
})

const mostMissedModel = computed(() => {
  if (missedTrades.value.length === 0) return '-'

  const counter = new Map<string, number>()
  for (const item of missedTrades.value) {
    counter.set(item.model, (counter.get(item.model) ?? 0) + 1)
  }

  let topModel = '-'
  let maxCount = -1
  for (const [model, count] of counter.entries()) {
    if (count > maxCount) {
      maxCount = count
      topModel = model
    }
  }

  return topModel
})

const mostMissedSession = computed(() => {
  const counter = new Map<string, number>()

  for (const item of missedTrades.value) {
    const tags = parseTags(item.reason)
    const sessionTag = tags.find((tag) => tag.startsWith('session:'))
    if (!sessionTag) continue

    const session = sessionTag.replace('session:', '')
    counter.set(session, (counter.get(session) ?? 0) + 1)
  }

  if (counter.size === 0) return '-'

  let topSession = '-'
  let maxCount = -1
  for (const [session, count] of counter.entries()) {
    if (count > maxCount) {
      maxCount = count
      topSession = session
    }
  }

  return topSession
})

function parseTags(reason: string): string[] {
  return reason
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)
}

function resetForm() {
  isEditing.value = false
  editingId.value = null
  form.pair = ''
  form.model = ''
  form.date = ''
  form.notes = ''
  form.tags = []
  customTag.value = ''
}

function startAdd() {
  resetForm()
  form.date = toLocalDateTime(new Date().toISOString())
}

function startEdit(item: MissedTrade) {
  isEditing.value = true
  editingId.value = item.id
  form.pair = item.pair
  form.model = item.model
  form.date = toLocalDateTime(item.date)
  form.notes = item.notes ?? ''
  form.tags = parseTags(item.reason)
}

function toLocalDateTime(value: string) {
  const date = new Date(value)
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

function toggleTag(tag: string) {
  if (form.tags.includes(tag)) {
    form.tags = form.tags.filter((item) => item !== tag)
    return
  }

  form.tags = [...form.tags, tag]
}

function addCustomTag() {
  const value = customTag.value.trim().toLowerCase()
  if (!value || form.tags.includes(value)) return

  form.tags = [...form.tags, value]
  customTag.value = ''
}

function removeTag(tag: string) {
  form.tags = form.tags.filter((item) => item !== tag)
}

async function submit() {
  if (!form.pair || !form.model || !form.date || form.tags.length === 0) return

  try {
    const payload = {
      pair: form.pair.toUpperCase(),
      model: form.model,
      date: new Date(form.date).toISOString(),
      reason: form.tags.join(', '),
      notes: form.notes.trim() ? form.notes.trim() : null,
    }

    if (isEditing.value && editingId.value) {
      await missedTradeStore.updateMissedTrade(editingId.value, payload)
      uiStore.toast({
        type: 'success',
        title: 'Missed trade updated',
      })
    } else {
      await missedTradeStore.createMissedTrade(payload)
      uiStore.toast({
        type: 'success',
        title: 'Missed trade logged',
      })
    }

    resetForm()
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to save missed trade',
      message: 'Please verify form values and try again.',
    })
  }
}

async function remove(id: number) {
  const confirmed = await uiStore.askConfirmation({
    title: 'Delete missed trade entry?',
    message: 'This action cannot be undone.',
    confirmText: 'Delete',
    danger: true,
  })
  if (!confirmed) return
  try {
    await missedTradeStore.deleteMissedTrade(id)
    uiStore.toast({
      type: 'success',
      title: 'Missed trade deleted',
    })
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Delete failed',
      message: 'Could not remove this missed trade entry.',
    })
  }
}

async function applyFilters() {
  await missedTradeStore.fetchMissedTrades(1)
}

async function clearFilters() {
  missedTradeStore.resetFilters()
  await missedTradeStore.fetchMissedTrades(1)
}

async function changePage(delta: number) {
  const next = pagination.value.current_page + delta
  if (next < 1 || next > pagination.value.last_page) return
  await missedTradeStore.fetchMissedTrades(next)
}

onMounted(async () => {
  startAdd()
  try {
    await missedTradeStore.fetchMissedTrades()
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load missed trades',
      message: 'Please refresh and try again.',
    })
  }
})
</script>

<template>
  <div class="space-y-6">
    <section class="grid grid-premium md:grid-cols-3">
      <GlassPanel class="metric-card">
        <p class="kicker-label">Total Missed</p>
        <p class="metric-value value-display">
          <AnimatedNumber :value="pagination.total" />
        </p>
      </GlassPanel>
      <GlassPanel class="metric-card">
        <p class="kicker-label">Most Missed Model</p>
        <p class="metric-value positive">{{ mostMissedModel }}</p>
      </GlassPanel>
      <GlassPanel class="metric-card">
        <p class="kicker-label">Most Missed Session</p>
        <p class="metric-value">{{ mostMissedSession }}</p>
      </GlassPanel>
    </section>

    <GlassPanel>
      <div class="section-head">
        <h2 class="section-title">{{ isEditing ? 'Edit Missed Trade' : 'Add Missed Trade' }}</h2>
        <button class="btn btn-primary inline-flex items-center gap-2 px-3 py-2 text-sm" @click="startAdd">
          <Plus class="h-4 w-4" />
          New Entry
        </button>
      </div>

      <form class="form-block space-y-4" @submit.prevent="submit">
        <div class="grid grid-premium md:grid-cols-2 xl:grid-cols-4">
          <label>
            Pair
            <input v-model="form.pair" required type="text" placeholder="EURUSD" class="field" />
          </label>
          <label>
            Model
            <input v-model="form.model" required type="text" placeholder="Liquidity Sweep" class="field" />
          </label>
          <label>
            Date
            <input v-model="form.date" required type="datetime-local" class="field" />
          </label>
          <label>
            Custom Tag
            <div class="mt-2 flex gap-2">
              <input
                v-model="customTag"
                type="text"
                placeholder="discipline"
                class="field mt-0 w-full"
                @keydown.enter.prevent="addCustomTag"
              />
              <button type="button" class="btn btn-ghost px-3 text-xs" @click="addCustomTag">Add</button>
            </div>
          </label>
        </div>

        <div>
          <p class="kicker-label mb-2">Reason Tags</p>
          <div class="chip-row">
            <button
              v-for="tag in reasonTagOptions"
              :key="tag"
              type="button"
              class="chip-btn"
              :class="{ active: form.tags.includes(tag) }"
              @click="toggleTag(tag)"
            >
              {{ tag }}
            </button>
          </div>
          <div v-if="form.tags.length > 0" class="chip-row mt-3">
            <span v-for="tag in form.tags" :key="`selected-${tag}`" class="pill">
              {{ tag }}
              <button type="button" class="inline-flex items-center text-[var(--muted)]" @click="removeTag(tag)">
                <X class="h-3 w-3" />
              </button>
            </span>
          </div>
        </div>

        <label>
          Notes
          <textarea v-model="form.notes" rows="3" class="field" />
        </label>

        <div class="flex justify-end gap-2">
          <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="resetForm">Clear</button>
          <button type="submit" class="btn btn-primary px-4 py-2 text-sm" :disabled="saving">
            {{ saving ? 'Saving...' : isEditing ? 'Update Entry' : 'Save Entry' }}
          </button>
        </div>
      </form>
    </GlassPanel>

    <GlassPanel>
      <div class="section-head">
        <h2 class="section-title">Missed Trades</h2>
        <div class="flex flex-wrap items-center gap-2">
          <button class="btn btn-ghost px-4 py-2 text-sm" @click="applyFilters">Apply</button>
          <button v-if="hasFilters" class="btn btn-ghost px-4 py-2 text-sm" @click="clearFilters">Reset</button>
        </div>
      </div>

      <div class="form-block mb-4 grid grid-premium md:grid-cols-2 xl:grid-cols-5">
        <label>
          Pair
          <input v-model="filters.pair" type="text" class="field field-sm" placeholder="EURUSD" />
        </label>
        <label>
          Model
          <input v-model="filters.model" type="text" class="field field-sm" placeholder="Breakout" />
        </label>
        <label>
          Reason Tag
          <input v-model="filters.reason" type="text" class="field field-sm" placeholder="session:london" />
        </label>
        <label>
          Date From
          <input v-model="filters.date_from" type="date" class="field field-sm" />
        </label>
        <label>
          Date To
          <input v-model="filters.date_to" type="date" class="field field-sm" />
        </label>
      </div>

      <div v-if="loading" class="space-y-3">
        <SkeletonBlock v-for="row in 6" :key="`missed-skeleton-${row}`" height-class="h-12" rounded-class="rounded-xl" />
      </div>

      <EmptyState
        v-else-if="missedTrades.length === 0"
        title="No missed trades yet"
        description="Capture missed setups with tags to improve execution."
        :icon="CalendarX2"
      />

      <div v-else class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Pair</th>
              <th>Model</th>
              <th>Date</th>
              <th>Reason Tags</th>
              <th>Notes</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in missedTrades" :key="item.id">
              <td class="font-semibold">{{ item.pair }}</td>
              <td class="muted">{{ item.model }}</td>
              <td class="muted">{{ asDate(item.date) }}</td>
              <td>
                <div class="chip-row">
                  <span v-for="tag in parseTags(item.reason)" :key="`${item.id}-${tag}`" class="pill">{{ tag }}</span>
                </div>
              </td>
              <td class="muted">{{ item.notes || '-' }}</td>
              <td>
                <div class="flex items-center justify-end gap-2">
                  <button class="btn btn-ghost p-2" @click="startEdit(item)">
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button class="btn btn-danger p-2" @click="remove(item.id)">
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-4 flex items-center justify-between text-sm">
        <button class="btn btn-ghost px-3 py-1.5" :disabled="pagination.current_page === 1" @click="changePage(-1)">
          Previous
        </button>
        <span class="muted">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
        <button
          class="btn btn-ghost px-3 py-1.5"
          :disabled="pagination.current_page === pagination.last_page"
          @click="changePage(1)"
        >
          Next
        </button>
      </div>
    </GlassPanel>
  </div>
</template>
