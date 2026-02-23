<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import {
  AlertCircle,
  CalendarDays,
  CalendarX2,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronUp,
  Eye,
  ImageOff,
  Images,
  MessageSquare,
  Pencil,
  Plus,
  Trash2,
  X,
} from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseDateInput from '@/components/form/BaseDateInput.vue'
import InstrumentPairSelect from '@/components/form/InstrumentPairSelect.vue'
import { useMissedTradeStore } from '@/stores/missedTradeStore'
import { useTradeStore } from '@/stores/tradeStore'
import { useUiStore } from '@/stores/uiStore'
import type { MissedTrade, MissedTradeImage } from '@/types/trade'
import { asDate } from '@/utils/format'

const router = useRouter()
const missedTradeStore = useMissedTradeStore()
const tradeStore = useTradeStore()
const uiStore = useUiStore()
const { missedTrades, pagination, filters, loading, hasFilters } = storeToRefs(missedTradeStore)
const { instruments } = storeToRefs(tradeStore)
const detailsOpen = ref(false)
const detailsLoading = ref(false)
const detailsEntry = ref<MissedTrade | null>(null)
const detailsImages = ref<MissedTradeImage[]>([])
const detailImageIndex = ref(0)
const activeDetailImage = computed(() => detailsImages.value[detailImageIndex.value] ?? null)
const filtersExpanded = ref(false)

const selectedFilterInstrumentId = computed({
  get() {
    const normalizedPair = filters.value.pair.trim().toUpperCase()
    if (!normalizedPair) return ''
    const match = instruments.value.find((instrument) => instrument.symbol === normalizedPair)
    return match ? String(match.id) : ''
  },
  set(value: string) {
    const id = Number(value)
    if (!Number.isInteger(id) || id <= 0) {
      filters.value.pair = ''
      return
    }

    const match = instruments.value.find((instrument) => instrument.id === id)
    filters.value.pair = match?.symbol ?? ''
  },
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

const visibleMissedTrades = computed(() => missedTrades.value)

const detailRecoveryScore = computed(() => {
  if (!detailsEntry.value) return 0
  const score = recoveryScore(detailsEntry.value)
  return detailsImages.value.length > 0 ? Math.min(100, score + 10) : score
})

const detailRecoveryChecklist = computed(() => {
  if (!detailsEntry.value) return []
  const notesLength = (detailsEntry.value.notes ?? '').trim().length
  return [
    { label: 'Setup tags captured', done: parseTags(detailsEntry.value.reason).length >= 2 },
    { label: 'Screenshot evidence attached', done: detailsImages.value.length > 0 },
    { label: 'Recovery notes are specific', done: notesLength >= 20 },
    { label: 'Model is recorded', done: modelLabel(detailsEntry.value.model) !== 'No model' },
  ]
})

const detailRecoveryAction = computed(() => {
  if (!detailsEntry.value) return ''
  if (parseTags(detailsEntry.value.reason).length < 2) return 'Add at least two reason tags (context + trigger).'
  if (detailsImages.value.length === 0) return 'Attach the missed chart to preserve market context.'
  if ((detailsEntry.value.notes ?? '').trim().length < 20) return 'Write a concrete execution plan for next occurrence.'
  return 'Setup is documented and ready for playbook review.'
})

function parseTags(reason: string): string[] {
  return reason
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)
}

function tagCount(item: MissedTrade) {
  return parseTags(item.reason).length
}

function missedImageCount(item: MissedTrade) {
  return Number(item.images_count ?? item.images?.length ?? 0)
}

function hasThinRecoveryNotes(item: MissedTrade) {
  return (item.notes ?? '').trim().length < 20
}

function needsRecoveryAction(item: MissedTrade) {
  return (
    missedImageCount(item) === 0
    || hasThinRecoveryNotes(item)
    || tagCount(item) < 2
  )
}

function recoveryPriority(item: MissedTrade): 'high' | 'medium' | 'low' {
  if (missedImageCount(item) === 0 && hasThinRecoveryNotes(item)) return 'high'
  if (needsRecoveryAction(item)) return 'medium'
  return 'low'
}

function recoveryPriorityLabel(item: MissedTrade) {
  const priority = recoveryPriority(item)
  if (priority === 'high') return 'Action now'
  if (priority === 'medium') return 'Action soon'
  return 'Action set'
}

function recoveryScore(item: MissedTrade) {
  let score = 100
  if (tagCount(item) < 2) score -= 30
  if (missedImageCount(item) === 0) score -= 30
  if (hasThinRecoveryNotes(item)) score -= 25
  if (modelLabel(item.model) === 'No model') score -= 10
  return Math.max(0, Math.min(100, score))
}

function scoreTier(score: number) {
  if (score >= 85) return 'A'
  if (score >= 70) return 'B'
  if (score >= 55) return 'C'
  return 'D'
}

function cardDate(value: string) {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return '-'
  return parsed.toLocaleDateString('en-US', {
    month: 'short',
    day: '2-digit',
    year: 'numeric',
  })
}

function modelLabel(value: string | null | undefined) {
  const text = `${value ?? ''}`.trim()
  return text || 'No model'
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

function todayLocalDate() {
  return toLocalDateString(new Date())
}

const dateFromMax = computed(() => {
  const today = todayLocalDate()
  if (!filters.value.date_to) return today
  return filters.value.date_to < today ? filters.value.date_to : today
})

const dateToMin = computed(() => filters.value.date_from || undefined)
const dateToMax = computed(() => todayLocalDate())

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
  const today = todayLocalDate()
  if (
    (filters.value.date_from && filters.value.date_from > today)
    || (filters.value.date_to && filters.value.date_to > today)
  ) {
    uiStore.toast({
      type: 'error',
      title: 'Invalid date range',
      message: 'Dates cannot be in the future.',
    })
    return
  }

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

function setFilterPreset(preset: 'today' | '7d' | '30d' | 'uptodate' | 'clear') {
  if (preset === 'clear') {
    filters.value.date_from = ''
    filters.value.date_to = ''
    return
  }

  const now = new Date()
  const today = todayLocalDate()

  if (preset === 'uptodate') {
    if (filters.value.date_from && filters.value.date_from > today) {
      filters.value.date_from = today
    }
    filters.value.date_to = today
    return
  }

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
    await Promise.all([
      tradeStore.fetchInstruments(),
      missedTradeStore.fetchMissedTrades(),
    ])
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
    <GlassPanel class="missed-db-shell command-filter-shell">
      <div class="command-filter-bar">
        <div class="command-filter-left">
          <h2 class="section-title">Missed Trade Log</h2>
          <div class="filter-summary-strip">
            <span v-if="activeFilterPills.length === 0" class="section-note">No filters applied</span>
            <span v-for="pill in activeFilterPills" :key="`missed-pill-${pill}`" class="filter-chip-mini">{{ pill }}</span>
          </div>
        </div>
        <div class="command-filter-right">
          <button class="btn btn-secondary inline-flex items-center gap-2 px-3 py-2 text-sm" @click="openQuickAddPage">
            <Plus class="h-4 w-4" />
            New Missed
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
            <InstrumentPairSelect
              v-model="selectedFilterInstrumentId"
              label="Instrument / Pair"
              :instruments="instruments"
              size="sm"
              clearable
              all-label="All symbols"
              placeholder="All symbols"
              :show-label-help="false"
            />
            <BaseInput v-model="filters.model" label="Model" type="text" placeholder="Breakout" size="sm" />
            <BaseInput v-model="filters.reason" label="Reason Tag" type="text" placeholder="session:london" size="sm" />
          </div>

          <div class="filter-drawer-row">
            <div class="trade-filter-date-grid form-block">
              <BaseDateInput v-model="filters.date_from" label="Date From" size="sm" :max="dateFromMax" />
              <BaseDateInput v-model="filters.date_to" label="Date To" size="sm" :min="dateToMin" :max="dateToMax" />
            </div>
            <div class="trade-filter-presets">
              <button type="button" class="chip-btn" @click="setFilterPreset('today')">Today</button>
              <button type="button" class="chip-btn" @click="setFilterPreset('7d')">Last 7D</button>
              <button type="button" class="chip-btn" @click="setFilterPreset('30d')">Last 30D</button>
              <button type="button" class="chip-btn" @click="setFilterPreset('uptodate')">Up to Date</button>
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
        <h2 class="section-title">Missed Trade Log</h2>
        <p class="section-note">
          <span v-if="loading">Loading...</span>
          <span v-else>
            <AnimatedNumber :value="visibleMissedTrades.length" /> shown
          </span>
        </p>
      </div>

      <div v-if="loading" class="missed-db-grid">
        <SkeletonBlock v-for="row in 6" :key="`missed-skeleton-${row}`" height-class="h-48" rounded-class="rounded-2xl" />
      </div>

      <EmptyState
        v-else-if="visibleMissedTrades.length === 0"
        title="No missed trades yet"
        description="Capture missed trades with tags to improve execution."
        :icon="CalendarX2"
        cta-text="New Missed"
        @cta="openQuickAddPage"
      />

      <div v-else class="missed-db-grid">
        <article v-for="item in visibleMissedTrades" :key="item.id" class="trade-db-card trade-card-reference missed-card-reference">
          <button type="button" class="trade-db-media trade-card-reference-media" @click="openDetails(item)">
            <img
              v-if="primaryImage(item)"
              :src="primaryImage(item)?.thumbnail_url || primaryImage(item)?.image_url"
              :alt="`${item.pair} missed trade screenshot`"
              loading="lazy"
              class="trade-db-image"
              @error="setImageFallback($event, primaryImage(item)?.image_url)"
            />
            <div v-else class="trade-db-empty-chart trade-card-reference-empty">
              <ImageOff class="h-6 w-6" />
              <span>No screenshot</span>
            </div>
          </button>

          <div class="trade-card-reference-body">
            <div class="trade-card-reference-head">
              <div class="trade-card-reference-id">
                <h3>{{ item.pair }}</h3>
                <span class="pill missed-status-pill">Missed</span>
                <span class="trade-card-priority" :class="`is-${recoveryPriority(item)}`">{{ recoveryPriorityLabel(item) }}</span>
              </div>
              <p class="trade-card-reference-side value-display">{{ tagCount(item) }} tags</p>
            </div>

            <div class="trade-card-reference-meta">
              <span>{{ cardDate(item.date) }}</span>
              <span class="trade-card-reference-dot">&middot;</span>
              <span class="trade-card-reference-model">{{ modelLabel(item.model) }}</span>
              <span class="trade-card-reference-dot">&middot;</span>
              <span class="trade-card-reference-images">
                <Images class="h-3.5 w-3.5" />
                {{ item.images_count ?? item.images?.length ?? 0 }}
              </span>
            </div>

            <div class="trade-card-score-row">
              <span class="section-note">Recovery Score</span>
              <span class="trade-card-score-pill value-display" :class="`tier-${scoreTier(recoveryScore(item)).toLowerCase()}`">
                {{ scoreTier(recoveryScore(item)) }} - {{ recoveryScore(item) }}
              </span>
            </div>

            <div class="trade-card-reference-footer">
              <button type="button" class="trade-card-reference-review" @click="openDetails(item)">
                <MessageSquare class="h-4 w-4" />
                Review
              </button>

              <div class="trade-card-reference-actions">
                <button type="button" class="trade-card-reference-icon-btn" @click="openDetails(item)" aria-label="View missed trade">
                  <Eye class="h-4 w-4" />
                </button>
                <button type="button" class="trade-card-reference-icon-btn" @click="openEditPage(item)" aria-label="Edit missed trade">
                  <Pencil class="h-4 w-4" />
                </button>
                <button
                  type="button"
                  class="trade-card-reference-icon-btn is-danger"
                  @click="remove(item.id)"
                  aria-label="Delete missed trade"
                >
                  <Trash2 class="h-4 w-4" />
                </button>
              </div>
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
        <div class="trade-details-modal panel p-4">
          <div class="section-head">
            <h3 class="section-title">Missed Trade Details</h3>
            <button type="button" class="btn btn-ghost p-2" @click="closeDetails">
              <X class="h-4 w-4" />
            </button>
          </div>

          <div v-if="detailsLoading" class="space-y-3">
            <SkeletonBlock v-for="row in 3" :key="`missed-detail-skeleton-${row}`" height-class="h-12" rounded-class="rounded-xl" />
          </div>

          <div v-else-if="detailsEntry" class="trade-simple-detail">
            <section class="trade-modal-summary">
              <div class="trade-modal-summary-main">
                <h4>{{ detailsEntry.pair }}</h4>
                <span class="pill missed-status-pill">Missed</span>
              </div>

              <div class="trade-modal-summary-meta">
                <span class="trade-modal-summary-date">
                  <CalendarDays class="h-3.5 w-3.5" />
                  {{ asDate(detailsEntry.date) }}
                </span>
                <span class="trade-modal-summary-stat value-display">{{ parseTags(detailsEntry.reason).length }} tags</span>
              </div>
            </section>

            <div class="trade-simple-layout">
              <div class="trade-simple-image-shell">
                <img
                  v-if="activeDetailImage"
                  :src="activeDetailImage.image_url"
                  alt="Missed trade screenshot"
                  class="trade-simple-image"
                  @error="setImageFallback($event, activeDetailImage.image_url)"
                />
                <div v-else class="trade-simple-image-empty">
                  <ImageOff class="h-6 w-6" />
                  <span>No screenshot</span>
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
                  <span>Model</span>
                  <strong>{{ detailsEntry.model }}</strong>
                </article>
                <article>
                  <span>Images</span>
                  <strong class="value-display">{{ detailsImages.length }}</strong>
                </article>
                <article class="trade-simple-note">
                  <span>Tags</span>
                  <strong>{{ parseTags(detailsEntry.reason).join(', ') || '-' }}</strong>
                </article>
                <article class="trade-simple-note">
                  <span>Notes</span>
                  <strong>{{ detailsEntry.notes || '-' }}</strong>
                </article>
              </div>
            </div>

            <section class="trade-review-checklist panel">
              <div class="trade-review-checklist-head">
                <p class="kicker-label">Recovery Checklist</p>
                <span class="trade-card-score-pill value-display" :class="`tier-${scoreTier(detailRecoveryScore).toLowerCase()}`">
                  {{ scoreTier(detailRecoveryScore) }} - {{ detailRecoveryScore }}
                </span>
              </div>

              <div class="trade-review-checklist-list">
                <p v-for="item in detailRecoveryChecklist" :key="item.label" :class="item.done ? 'is-done' : 'is-pending'">
                  <CheckCircle2 v-if="item.done" class="h-4 w-4" />
                  <AlertCircle v-else class="h-4 w-4" />
                  <span>{{ item.label }}</span>
                </p>
              </div>

              <p class="section-note">{{ detailRecoveryAction }}</p>
            </section>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
