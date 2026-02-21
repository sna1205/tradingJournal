<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { CalendarDays, CalendarX2, CircleAlert, ImageOff, Images, Pencil, Plus, Tags, Trash2 } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseDateInput from '@/components/form/BaseDateInput.vue'
import { useMissedTradeStore } from '@/stores/missedTradeStore'
import { useUiStore } from '@/stores/uiStore'
import type { MissedTrade, MissedTradeImage } from '@/types/trade'
import { asDate } from '@/utils/format'

const router = useRouter()
const missedTradeStore = useMissedTradeStore()
const uiStore = useUiStore()
const { missedTrades, pagination, filters, loading, hasFilters } = storeToRefs(missedTradeStore)

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

function notePreview(value: string | null) {
  const normalized = (value ?? '').trim()
  if (!normalized) return '-'
  if (normalized.length <= 82) return normalized
  return `${normalized.slice(0, 82)}...`
}

function tagClass(tag: string) {
  if (tag.startsWith('session:')) return 'pill missed-tag-session'
  if (tag.includes('fear') || tag.includes('hesitation')) return 'pill missed-tag-discipline'
  return 'pill missed-tag-generic'
}

function primaryImage(item: MissedTrade): MissedTradeImage | null {
  const images = item.images ?? []
  return images.length > 0 ? images[0]! : null
}

function setImageFallback(event: Event, fallbackUrl?: string | null) {
  if (!fallbackUrl) return

  const target = event.target
  if (!(target instanceof HTMLImageElement)) return
  if (target.dataset.fallbackApplied === 'true') return

  target.dataset.fallbackApplied = 'true'
  target.src = fallbackUrl
}

function toLocalDateString(value: Date) {
  const offset = value.getTimezoneOffset() * 60000
  return new Date(value.getTime() - offset).toISOString().slice(0, 10)
}

function addDays(value: Date, days: number) {
  const next = new Date(value)
  next.setDate(next.getDate() + days)
  return next
}

function openAddPage() {
  void router.push('/missed-trades/new')
}

function openEditPage(item: MissedTrade) {
  void router.push(`/missed-trades/${item.id}/edit`)
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
  if (filters.value.date_from && filters.value.date_to && filters.value.date_from > filters.value.date_to) {
    uiStore.toast({
      type: 'error',
      title: 'Invalid date range',
      message: 'Date From cannot be later than Date To.',
    })
    return
  }

  await missedTradeStore.fetchMissedTrades(1)
}

function setFilterPreset(preset: 'today' | '7d' | '30d' | 'clear') {
  if (preset === 'clear') {
    filters.value.date_from = ''
    filters.value.date_to = ''
    return
  }

  const now = new Date()
  const today = toLocalDateString(now)

  if (preset === 'today') {
    filters.value.date_from = today
    filters.value.date_to = today
    return
  }

  if (preset === '7d') {
    filters.value.date_from = toLocalDateString(addDays(now, -6))
    filters.value.date_to = today
    return
  }

  filters.value.date_from = toLocalDateString(addDays(now, -29))
  filters.value.date_to = today
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

    <GlassPanel class="missed-db-shell">
      <div class="section-head">
        <h2 class="section-title text-white">Missed Trades</h2>
        <div class="flex flex-wrap items-center gap-2">
          <button class="btn btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm" @click="openAddPage">
            <Plus class="h-4 w-4" />
            Add Missed Trade
          </button>
          <button class="btn btn-ghost px-4 py-2 text-sm" @click="applyFilters">Apply</button>
          <button v-if="hasFilters" class="btn btn-ghost px-4 py-2 text-sm" @click="clearFilters">Reset</button>
        </div>
      </div>

      <div class="form-block mb-4 grid grid-premium md:grid-cols-2 xl:grid-cols-3">
        <BaseInput v-model="filters.pair" label="Pair" type="text" placeholder="EURUSD" size="sm" />
        <BaseInput v-model="filters.model" label="Model" type="text" placeholder="Breakout" size="sm" />
        <BaseInput v-model="filters.reason" label="Reason Tag" type="text" placeholder="session:london" size="sm" />
      </div>

      <div class="trade-filter-date-panel mb-4">
        <div class="trade-filter-date-grid form-block">
          <BaseDateInput v-model="filters.date_from" label="Date From" size="sm" :max="filters.date_to || undefined" />
          <BaseDateInput v-model="filters.date_to" label="Date To" size="sm" :min="filters.date_from || undefined" />
        </div>
        <div class="trade-filter-presets">
          <button type="button" class="chip-btn" @click="setFilterPreset('today')">Today</button>
          <button type="button" class="chip-btn" @click="setFilterPreset('7d')">Last 7D</button>
          <button type="button" class="chip-btn" @click="setFilterPreset('30d')">Last 30D</button>
          <button type="button" class="chip-btn" @click="setFilterPreset('clear')">Clear</button>
        </div>
      </div>

      <div v-if="loading" class="missed-db-grid">
        <SkeletonBlock v-for="row in 6" :key="`missed-skeleton-${row}`" height-class="h-48" rounded-class="rounded-2xl" />
      </div>

      <EmptyState
        v-else-if="missedTrades.length === 0"
        title="No missed trades yet"
        description="Capture missed setups with tags to improve execution."
        :icon="CalendarX2"
      />

      <div v-else class="missed-db-grid">
        <article v-for="item in missedTrades" :key="item.id" class="missed-db-card">
          <div class="missed-db-media">
            <img
              v-if="primaryImage(item)"
              :src="primaryImage(item)?.thumbnail_url || primaryImage(item)?.image_url"
              :alt="`${item.pair} missed setup screenshot`"
              loading="lazy"
              class="missed-db-image"
              @error="setImageFallback($event, primaryImage(item)?.image_url)"
            />
            <div v-else class="missed-db-image-empty">
              <ImageOff class="h-7 w-7" />
              <span>No screenshot</span>
            </div>
            <span class="pill missed-db-image-count">
              <Images class="h-3.5 w-3.5" />
              {{ item.images_count ?? item.images?.length ?? 0 }}
            </span>
          </div>

          <div class="missed-db-head">
            <div>
              <h3>{{ item.pair }}</h3>
              <p>{{ item.model }}</p>
            </div>
            <span class="pill missed-db-pill-alert">
              <CircleAlert class="h-3.5 w-3.5" />
              Missed
            </span>
          </div>

          <div class="missed-db-meta">
            <span class="inline-flex items-center gap-1">
              <CalendarDays class="h-3.5 w-3.5" />
              {{ asDate(item.date) }}
            </span>
          </div>

          <div class="mt-3">
            <p class="kicker-label inline-flex items-center gap-1 text-[color-mix(in_srgb,#93c5fd_72%,#cbd5e1_28%)]">
              <Tags class="h-3.5 w-3.5" />
              Reason Tags
            </p>
            <div class="chip-row mt-2">
              <span v-for="tag in parseTags(item.reason)" :key="`${item.id}-${tag}`" :class="tagClass(tag)">
                {{ tag }}
              </span>
            </div>
          </div>

          <p class="missed-db-note">{{ notePreview(item.notes) }}</p>

          <div class="missed-db-actions">
            <button class="btn btn-ghost px-3 py-1.5 text-xs" @click="openEditPage(item)">
              <Pencil class="h-3.5 w-3.5" />
              Edit
            </button>
            <button class="btn btn-danger px-3 py-1.5 text-xs" @click="remove(item.id)">
              <Trash2 class="h-3.5 w-3.5" />
              Delete
            </button>
          </div>
        </article>
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
