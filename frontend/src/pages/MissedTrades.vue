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
      <GlassPanel>
        <p class="text-xs uppercase tracking-[0.14em] text-slate-400">Total Missed</p>
        <p class="mt-2 text-2xl font-bold text-slate-100">
          <AnimatedNumber :value="pagination.total" />
        </p>
      </GlassPanel>
      <GlassPanel>
        <p class="text-xs uppercase tracking-[0.14em] text-slate-400">Most Missed Model</p>
        <p class="mt-2 text-2xl font-bold text-emerald-300">{{ mostMissedModel }}</p>
      </GlassPanel>
      <GlassPanel>
        <p class="text-xs uppercase tracking-[0.14em] text-slate-400">Most Missed Session</p>
        <p class="mt-2 text-2xl font-bold text-amber-300">{{ mostMissedSession }}</p>
      </GlassPanel>
    </section>

    <GlassPanel>
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-bold">{{ isEditing ? 'Edit Missed Trade' : 'Add Missed Trade' }}</h2>
        <button
          class="rounded-2xl border border-slate-600 px-3 py-2 text-sm font-semibold transition-all duration-200 ease-in-out hover:bg-slate-700/40"
          @click="startAdd"
        >
          <span class="inline-flex items-center gap-2">
            <Plus class="h-4 w-4" />
            New Entry
          </span>
        </button>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
            Pair
            <input
              v-model="form.pair"
              required
              type="text"
              placeholder="EURUSD"
              class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            />
          </label>
          <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
            Model
            <input
              v-model="form.model"
              required
              type="text"
              placeholder="Liquidity Sweep"
              class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            />
          </label>
          <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
            Date
            <input
              v-model="form.date"
              required
              type="datetime-local"
              class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            />
          </label>
          <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
            Custom Tag
            <div class="mt-2 flex gap-2">
              <input
                v-model="customTag"
                type="text"
                placeholder="discipline"
                class="w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                @keydown.enter.prevent="addCustomTag"
              />
              <button
                type="button"
                class="rounded-xl border border-emerald-400/60 px-3 text-xs font-semibold text-emerald-200 transition-all duration-200 ease-in-out hover:bg-emerald-500/20"
                @click="addCustomTag"
              >
                Add
              </button>
            </div>
          </label>
        </div>

        <div>
          <p class="mb-2 text-xs uppercase tracking-[0.14em] text-slate-400">Reason Tags</p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="tag in reasonTagOptions"
              :key="tag"
              type="button"
              class="rounded-full border px-3 py-1 text-xs font-semibold transition-all duration-200 ease-in-out"
              :class="form.tags.includes(tag)
                ? 'border-emerald-400/80 bg-emerald-500/20 text-emerald-200'
                : 'border-slate-700 bg-slate-900/50 text-slate-300 hover:bg-slate-800'"
              @click="toggleTag(tag)"
            >
              {{ tag }}
            </button>
          </div>
          <div v-if="form.tags.length > 0" class="mt-3 flex flex-wrap gap-2">
            <span
              v-for="tag in form.tags"
              :key="`selected-${tag}`"
              class="inline-flex items-center gap-1 rounded-full border border-slate-600 bg-slate-800/80 px-3 py-1 text-xs text-slate-200"
            >
              {{ tag }}
              <button type="button" class="text-slate-400 hover:text-slate-200" @click="removeTag(tag)">
                <X class="h-3 w-3" />
              </button>
            </span>
          </div>
        </div>

        <label class="block text-xs uppercase tracking-[0.14em] text-slate-400">
          Notes
          <textarea
            v-model="form.notes"
            rows="3"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
          />
        </label>

        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="rounded-2xl border border-slate-600 px-4 py-2 text-sm font-semibold transition-all duration-200 ease-in-out hover:bg-slate-700/40"
            @click="resetForm"
          >
            Clear
          </button>
          <button
            type="submit"
            class="rounded-2xl border border-emerald-400/70 bg-emerald-500/20 px-4 py-2 text-sm font-semibold text-emerald-100 transition-all duration-200 ease-in-out hover:bg-emerald-500/35"
            :disabled="saving"
          >
            {{ saving ? 'Saving...' : isEditing ? 'Update Entry' : 'Save Entry' }}
          </button>
        </div>
      </form>
    </GlassPanel>

    <GlassPanel>
      <div class="mb-4 flex flex-wrap items-end gap-3">
        <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
          Pair
          <input
            v-model="filters.pair"
            type="text"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            placeholder="EURUSD"
          />
        </label>
        <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
          Model
          <input
            v-model="filters.model"
            type="text"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            placeholder="Breakout"
          />
        </label>
        <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
          Reason Tag
          <input
            v-model="filters.reason"
            type="text"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            placeholder="session:london"
          />
        </label>
        <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
          Date From
          <input
            v-model="filters.date_from"
            type="date"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
          />
        </label>
        <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
          Date To
          <input
            v-model="filters.date_to"
            type="date"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
          />
        </label>
        <button
          class="rounded-2xl border border-slate-600 px-4 py-2 text-sm font-semibold transition-all duration-200 ease-in-out hover:bg-slate-700/40"
          @click="applyFilters"
        >
          Apply
        </button>
        <button
          v-if="hasFilters"
          class="rounded-2xl border border-slate-600 px-4 py-2 text-sm font-semibold transition-all duration-200 ease-in-out hover:bg-slate-700/40"
          @click="clearFilters"
        >
          Reset
        </button>
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

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead>
            <tr class="border-b border-slate-700/80 text-xs uppercase tracking-[0.12em] text-slate-400">
              <th class="px-3 py-3">Pair</th>
              <th class="px-3 py-3">Model</th>
              <th class="px-3 py-3">Date</th>
              <th class="px-3 py-3">Reason Tags</th>
              <th class="px-3 py-3">Notes</th>
              <th class="px-3 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in missedTrades"
              :key="item.id"
              class="border-b border-slate-800/70 transition-all duration-200 ease-in-out hover:bg-slate-900/70"
            >
              <td class="px-3 py-3 font-semibold">{{ item.pair }}</td>
              <td class="px-3 py-3 text-slate-300">{{ item.model }}</td>
              <td class="px-3 py-3 text-slate-300">{{ asDate(item.date) }}</td>
              <td class="px-3 py-3">
                <div class="flex max-w-md flex-wrap gap-1.5">
                  <span
                    v-for="tag in parseTags(item.reason)"
                    :key="`${item.id}-${tag}`"
                    class="rounded-full border border-slate-600 bg-slate-900/70 px-2 py-0.5 text-[11px] text-slate-200"
                  >
                    {{ tag }}
                  </span>
                </div>
              </td>
              <td class="px-3 py-3 text-slate-300">{{ item.notes || '-' }}</td>
              <td class="px-3 py-3">
                <div class="flex items-center justify-end gap-2">
                  <button
                    class="rounded-xl border border-slate-600 p-2 text-slate-200 transition-all duration-200 ease-in-out hover:border-emerald-400/70 hover:bg-emerald-500/20"
                    @click="startEdit(item)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button
                    class="rounded-xl border border-rose-400/40 p-2 text-rose-300 transition-all duration-200 ease-in-out hover:bg-rose-500/20"
                    @click="remove(item.id)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-4 flex items-center justify-between text-sm">
        <button
          class="rounded-xl border border-slate-600 px-3 py-1.5 transition-all duration-200 ease-in-out hover:bg-slate-700/40 disabled:opacity-50"
          :disabled="pagination.current_page === 1"
          @click="changePage(-1)"
        >
          Previous
        </button>
        <span class="text-slate-300">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
        <button
          class="rounded-xl border border-slate-600 px-3 py-1.5 transition-all duration-200 ease-in-out hover:bg-slate-700/40 disabled:opacity-50"
          :disabled="pagination.current_page === pagination.last_page"
          @click="changePage(1)"
        >
          Next
        </button>
      </div>
    </GlassPanel>
  </div>
</template>
