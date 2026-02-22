<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
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
import { useMissedTradeStore } from '@/stores/missedTradeStore'
import { useUiStore } from '@/stores/uiStore'
import type { MissedTrade, MissedTradeImage } from '@/types/trade'
import { asDate } from '@/utils/format'

const router = useRouter()
const route = useRoute()
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
type MissedQuickFocus = 'all' | 'action_required' | 'no_notes' | 'no_screenshot' | 'low_tags'
const quickFocus = ref<MissedQuickFocus>('all')
const focusOptions: MissedQuickFocus[] = ['all', 'action_required', 'no_notes', 'no_screenshot', 'low_tags']

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

const actionRequiredCount = computed(() => missedTrades.value.filter((item) => needsRecoveryAction(item)).length)
const noNotesCount = computed(() => missedTrades.value.filter((item) => hasThinRecoveryNotes(item)).length)
const noScreenshotCount = computed(() => missedTrades.value.filter((item) => missedImageCount(item) === 0).length)
const lowTagCount = computed(() => missedTrades.value.filter((item) => tagCount(item) < 2).length)
const averageRecoveryScore = computed(() => {
  if (missedTrades.value.length === 0) return 0
  const total = missedTrades.value.reduce((sum, item) => sum + recoveryScore(item), 0)
  return total / missedTrades.value.length
})

const visibleMissedTrades = computed(() => {
  if (quickFocus.value === 'action_required') {
    return missedTrades.value.filter((item) => needsRecoveryAction(item))
  }
  if (quickFocus.value === 'no_notes') {
    return missedTrades.value.filter((item) => hasThinRecoveryNotes(item))
  }
  if (quickFocus.value === 'no_screenshot') {
    return missedTrades.value.filter((item) => missedImageCount(item) === 0)
  }
  if (quickFocus.value === 'low_tags') {
    return missedTrades.value.filter((item) => tagCount(item) < 2)
  }
  return missedTrades.value
})

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

function normalizeQuickFocus(value: unknown): MissedQuickFocus {
  const normalized = `${value ?? ''}`.trim().toLowerCase()
  return focusOptions.includes(normalized as MissedQuickFocus) ? (normalized as MissedQuickFocus) : 'all'
}

function applyQuickFocusFromRoute() {
  quickFocus.value = normalizeQuickFocus(route.query.focus)
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
    applyQuickFocusFromRoute()
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load missed setups',
      message: 'Please refresh and try again.',
    })
  }
})

watch(
  () => route.query.focus,
  () => {
    applyQuickFocusFromRoute()
  }
)

watch(quickFocus, (value) => {
  const current = normalizeQuickFocus(route.query.focus)
  if (value === current) return

  const query = { ...route.query }
  if (value === 'all') {
    delete query.focus
  } else {
    query.focus = value
  }

  void router.replace({ query })
})
</script>

<template>
  <div class="space-y-5 missed-list-minimal">
    <section class="trade-review-strip grid grid-premium md:grid-cols-5 missed-summary-strip">
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">Action Required</p>
        <p class="trade-review-kpi-value value-display negative">
          <AnimatedNumber :value="actionRequiredCount" />
        </p>
      </GlassPanel>
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">No Notes</p>
        <p class="trade-review-kpi-value value-display negative">
          <AnimatedNumber :value="noNotesCount" />
        </p>
      </GlassPanel>
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">No Screenshot</p>
        <p class="trade-review-kpi-value value-display">
          <AnimatedNumber :value="noScreenshotCount" />
        </p>
      </GlassPanel>
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">Low Tag Quality</p>
        <p class="trade-review-kpi-value value-display">
          <AnimatedNumber :value="lowTagCount" />
        </p>
      </GlassPanel>
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">Avg Recovery Score</p>
        <p class="trade-review-kpi-value value-display">
          <AnimatedNumber :value="averageRecoveryScore" :decimals="0" />
        </p>
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

    <GlassPanel class="trade-focus-shell">
      <div class="trade-focus-row">
        <span class="section-note">Recovery focus</span>
        <div class="trade-focus-actions">
          <button type="button" class="chip-btn" :class="{ 'is-active': quickFocus === 'all' }" @click="quickFocus = 'all'">All</button>
          <button
            type="button"
            class="chip-btn"
            :class="{ 'is-active': quickFocus === 'action_required' }"
            @click="quickFocus = 'action_required'"
          >
            Action Required
          </button>
          <button type="button" class="chip-btn" :class="{ 'is-active': quickFocus === 'no_notes' }" @click="quickFocus = 'no_notes'">No Notes</button>
          <button
            type="button"
            class="chip-btn"
            :class="{ 'is-active': quickFocus === 'no_screenshot' }"
            @click="quickFocus = 'no_screenshot'"
          >
            No Screenshot
          </button>
          <button type="button" class="chip-btn" :class="{ 'is-active': quickFocus === 'low_tags' }" @click="quickFocus = 'low_tags'">Low Tags</button>
        </div>
      </div>
    </GlassPanel>

    <section class="missed-db-shell panel p-4 md:p-5">
      <div class="section-head">
        <h2 class="section-title">Missed Setup Log</h2>
        <p class="section-note">
          <span v-if="loading">Loading...</span>
          <span v-else>
            <AnimatedNumber :value="visibleMissedTrades.length" /> shown
            <template v-if="quickFocus !== 'all'"> of <AnimatedNumber :value="pagination.total" /></template>
          </span>
        </p>
      </div>

      <div v-if="loading" class="missed-db-grid">
        <SkeletonBlock v-for="row in 6" :key="`missed-skeleton-${row}`" height-class="h-48" rounded-class="rounded-2xl" />
      </div>

      <EmptyState
        v-else-if="visibleMissedTrades.length === 0"
        :title="quickFocus === 'all' ? 'No missed setups yet' : 'No setups in this focus'"
        :description="quickFocus === 'all'
          ? 'Capture missed setups with tags to improve execution.'
          : 'Switch focus or widen filters to continue recovery review.'"
        :icon="CalendarX2"
        cta-text="Quick Add"
        @cta="openQuickAddPage"
      />

      <div v-else class="missed-db-grid">
        <article v-for="item in visibleMissedTrades" :key="item.id" class="trade-db-card trade-card-reference missed-card-reference">
          <button type="button" class="trade-db-media trade-card-reference-media" @click="openDetails(item)">
            <img
              v-if="primaryImage(item)"
              :src="primaryImage(item)?.thumbnail_url || primaryImage(item)?.image_url"
              :alt="`${item.pair} missed setup screenshot`"
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
            <h3 class="section-title">Missed Setup Details</h3>
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
                  alt="Missed setup screenshot"
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
