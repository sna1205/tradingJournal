<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { CalendarDays, CalendarX2, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Eye, ImageOff, Images, Pencil, Plus, Trash2, X } from 'lucide-vue-next'
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
const detailsOpen = ref(false)
const detailsLoading = ref(false)
const detailsEntry = ref<MissedTrade | null>(null)
const detailsImages = ref<MissedTradeImage[]>([])
const detailImageIndex = ref(0)
const activeDetailImage = computed(() => detailsImages.value[detailImageIndex.value] ?? null)
const filtersExpanded = ref(false)

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

const activeFilterPills = computed(() => {
  const pills: string[] = []
  const pair = filters.value.pair.trim()
  const model = filters.value.model.trim()
  const reason = filters.value.reason.trim()
  const from = filters.value.date_from
  const to = filters.value.date_to

  if (pair) pills.push(`Pair: ${pair.toUpperCase()}`)
  if (model) pills.push(`Model: ${model}`)
  if (reason) pills.push(`Tag: ${reason}`)
  if (from && to) pills.push(`${from} to ${to}`)
  else if (from) pills.push(`From: ${from}`)
  else if (to) pills.push(`To: ${to}`)

  return pills
})

function parseTags(reason: string): string[] {
  return reason
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)
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

function openQuickAddPage() {
  const query: Record<string, string> = { quick: '1' }
  const pair = filters.value.pair.trim()
  const model = filters.value.model.trim()
  const reason = filters.value.reason.trim()
  if (pair) query.pair = pair.toUpperCase()
  if (model) query.model = model
  if (reason) query.reason = reason
  void router.push({ path: '/missed-trades/new', query })
}

function openEditPage(item: MissedTrade) {
  void router.push(`/missed-trades/${item.id}/edit`)
}

async function openDetails(item: MissedTrade) {
  detailsOpen.value = true
  detailsLoading.value = true
  detailsEntry.value = null
  detailsImages.value = []
  detailImageIndex.value = 0

  try {
    const entry = await missedTradeStore.fetchMissedTrade(item.id)
    detailsEntry.value = entry
    detailsImages.value = (entry.images ?? [])
      .slice()
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
  } catch {
    detailsOpen.value = false
    uiStore.toast({
      type: 'error',
      title: 'Failed to load setup details',
      message: 'Please try again.',
    })
  } finally {
    detailsLoading.value = false
  }
}

function closeDetails() {
  detailsOpen.value = false
}

function nextDetailImage() {
  if (detailsImages.value.length <= 1) return
  detailImageIndex.value = (detailImageIndex.value + 1) % detailsImages.value.length
}

function previousDetailImage() {
  if (detailsImages.value.length <= 1) return
  detailImageIndex.value = (detailImageIndex.value - 1 + detailsImages.value.length) % detailsImages.value.length
}

async function remove(id: number) {
  const confirmed = await uiStore.askConfirmation({
    title: 'Delete missed setup entry?',
    message: 'This action cannot be undone.',
    confirmText: 'Delete',
    danger: true,
  })
  if (!confirmed) return

  try {
    await missedTradeStore.deleteMissedTrade(id)
    uiStore.toast({
      type: 'success',
      title: 'Missed setup deleted',
    })
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Delete failed',
      message: 'Could not remove this missed setup entry.',
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
  filtersExpanded.value = false
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
  filtersExpanded.value = false
}

async function changePage(delta: number) {
  const next = pagination.value.current_page + delta
  if (next < 1 || next > pagination.value.last_page) return
  await missedTradeStore.fetchMissedTrades(next)
}

onMounted(async () => {
  try {
    await missedTradeStore.fetchMissedTrades()
    filtersExpanded.value = hasFilters.value
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load missed setups',
      message: 'Please refresh and try again.',
    })
  }
})
</script>

<template>
  <div class="space-y-5 missed-list-minimal">
    <section class="grid grid-premium md:grid-cols-3 missed-summary-strip">
      <GlassPanel class="metric-card metric-card-minimal">
        <p class="kicker-label">Total Missed</p>
        <p class="metric-value value-display">
          <AnimatedNumber :value="pagination.total" />
        </p>
      </GlassPanel>
      <GlassPanel class="metric-card metric-card-minimal">
        <p class="kicker-label">Most Missed Strategy</p>
        <p class="metric-value positive">{{ mostMissedModel }}</p>
      </GlassPanel>
      <GlassPanel class="metric-card metric-card-minimal">
        <p class="kicker-label">Most Missed Session</p>
        <p class="metric-value">{{ mostMissedSession }}</p>
      </GlassPanel>
    </section>

    <GlassPanel class="missed-db-shell command-filter-shell">
      <div class="command-filter-bar">
        <div class="command-filter-left">
          <h2 class="section-title">Missed Setups</h2>
          <div class="filter-summary-strip">
            <span v-if="activeFilterPills.length === 0" class="section-note">No filters applied</span>
            <span v-for="pill in activeFilterPills" :key="`missed-pill-${pill}`" class="filter-chip-mini">{{ pill }}</span>
          </div>
        </div>
        <div class="command-filter-right">
          <button class="btn btn-secondary inline-flex items-center gap-2 px-3 py-2 text-sm" @click="openQuickAddPage">
            <Plus class="h-4 w-4" />
            Quick Add
          </button>
          <button class="btn btn-ghost px-4 py-2 text-sm" @click="applyFilters">Apply</button>
          <button type="button" class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="filtersExpanded = !filtersExpanded">
            <ChevronUp v-if="filtersExpanded" class="h-4 w-4" />
            <ChevronDown v-else class="h-4 w-4" />
            Filters
          </button>
        </div>
      </div>

      <Transition name="drawer">
        <div v-if="filtersExpanded" class="filter-drawer">
          <div class="form-block mb-4 grid grid-premium md:grid-cols-2 xl:grid-cols-3">
            <BaseInput v-model="filters.pair" label="Pair" type="text" placeholder="EURUSD" size="sm" />
            <BaseInput v-model="filters.model" label="Model" type="text" placeholder="Breakout" size="sm" />
            <BaseInput v-model="filters.reason" label="Reason Tag" type="text" placeholder="session:london" size="sm" />
          </div>

          <div class="filter-drawer-row">
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

          <div class="filter-drawer-footer">
            <button class="btn btn-primary px-4 py-2 text-sm" @click="applyFilters">Apply</button>
            <button class="btn btn-ghost px-4 py-2 text-sm" :disabled="!hasFilters" @click="clearFilters">Reset</button>
          </div>
        </div>
      </Transition>
    </GlassPanel>

    <section class="missed-db-shell panel p-4 md:p-5">
      <div class="section-head">
        <h2 class="section-title">Missed Setup Log</h2>
        <p class="section-note">
          <span v-if="loading">Loading...</span>
          <span v-else><AnimatedNumber :value="pagination.total" /> entries</span>
        </p>
      </div>

      <div v-if="loading" class="missed-db-grid">
        <SkeletonBlock v-for="row in 6" :key="`missed-skeleton-${row}`" height-class="h-48" rounded-class="rounded-2xl" />
      </div>

      <EmptyState
        v-else-if="missedTrades.length === 0"
        title="No missed setups yet"
        description="Capture missed setups with tags to improve execution."
        :icon="CalendarX2"
        cta-text="Quick Add"
        @cta="openQuickAddPage"
      />

      <div v-else class="missed-db-grid">
        <article v-for="item in missedTrades" :key="item.id" class="trade-db-card missed-log-row">
          <button type="button" class="trade-db-media" @click="openDetails(item)">
            <img
              v-if="primaryImage(item)"
              :src="primaryImage(item)?.thumbnail_url || primaryImage(item)?.image_url"
              :alt="`${item.pair} missed setup screenshot`"
              loading="lazy"
              class="trade-db-image"
              @error="setImageFallback($event, primaryImage(item)?.image_url)"
            />
            <div v-else class="trade-db-empty-chart">
              <ImageOff class="h-7 w-7" />
              <span>No screenshot</span>
            </div>
            <span class="pill trade-log-image-count">
              <Images class="h-3.5 w-3.5" />
              {{ item.images_count ?? item.images?.length ?? 0 }}
            </span>
          </button>

          <div class="missed-log-row-content">
            <div class="missed-log-row-head">
              <div class="missed-log-row-id">
                <h3>{{ item.pair }}</h3>
                <span class="pill missed-status-pill">Missed</span>
              </div>
              <span class="missed-log-row-date">
                <CalendarDays class="h-3.5 w-3.5" />
                {{ asDate(item.date) }}
              </span>
            </div>

            <div class="missed-db-meta">
              <span>{{ item.model }}</span>
            </div>

            <div class="missed-log-row-metrics">
              <article class="trade-log-metric">
                <p class="kicker-label">Tags</p>
                <strong class="value-display">{{ parseTags(item.reason).length }}</strong>
              </article>
              <article class="trade-log-metric">
                <p class="kicker-label">Images</p>
                <strong class="value-display">{{ item.images_count ?? item.images?.length ?? 0 }}</strong>
              </article>
            </div>

            <div class="trade-db-actions">
              <button class="btn btn-ghost px-3 py-1.5 text-xs" @click="openDetails(item)">
                <Eye class="h-3.5 w-3.5" />
                View
              </button>
              <button class="btn btn-ghost px-3 py-1.5 text-xs" @click="openEditPage(item)">
                <Pencil class="h-3.5 w-3.5" />
                Edit
              </button>
              <button class="btn btn-secondary px-3 py-1.5 text-xs" @click="remove(item.id)">
                <Trash2 class="h-3.5 w-3.5" />
                Delete
              </button>
            </div>
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
    </section>

    <Transition name="fade">
      <div v-if="detailsOpen" class="trade-details-modal-backdrop" @click.self="closeDetails">
        <div class="trade-details-modal panel p-5">
          <div class="section-head">
            <h3 class="section-title">Missed Setup Details</h3>
            <button type="button" class="btn btn-ghost p-2" @click="closeDetails">
              <X class="h-4 w-4" />
            </button>
          </div>

          <div v-if="detailsLoading" class="space-y-3">
            <SkeletonBlock v-for="row in 3" :key="`missed-detail-skeleton-${row}`" height-class="h-12" rounded-class="rounded-xl" />
          </div>

          <div v-else-if="detailsEntry" class="trade-simple-detail">
            <div class="trade-simple-image-shell">
              <img
                v-if="activeDetailImage"
                :src="activeDetailImage.image_url"
                alt="Missed setup screenshot"
                class="trade-simple-image"
                @error="setImageFallback($event, activeDetailImage.image_url)"
              />
              <div v-else class="trade-simple-image-empty">
                <ImageOff class="h-8 w-8" />
                <span>No screenshot uploaded</span>
              </div>

              <button
                v-if="detailsImages.length > 1"
                type="button"
                class="trade-simple-nav left"
                @click="previousDetailImage"
              >
                <ChevronLeft class="h-4 w-4" />
              </button>
              <button
                v-if="detailsImages.length > 1"
                type="button"
                class="trade-simple-nav right"
                @click="nextDetailImage"
              >
                <ChevronRight class="h-4 w-4" />
              </button>
            </div>

            <div class="trade-simple-metrics">
              <article>
                <span>Pair</span>
                <strong>{{ detailsEntry.pair }}</strong>
              </article>
              <article>
                <span>Model</span>
                <strong>{{ detailsEntry.model }}</strong>
              </article>
              <article>
                <span>Date</span>
                <strong>{{ asDate(detailsEntry.date) }}</strong>
              </article>
              <article>
                <span>Tags</span>
                <strong>{{ parseTags(detailsEntry.reason).join(', ') || '-' }}</strong>
              </article>
              <article class="trade-simple-note">
                <span>Notes</span>
                <strong>{{ detailsEntry.notes || '-' }}</strong>
              </article>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
