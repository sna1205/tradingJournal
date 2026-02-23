<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import {
  AlertCircle,
  BarChart3,
  CalendarDays,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  ChevronLeft,
  ChevronRight,
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
import BaseSelect from '@/components/form/BaseSelect.vue'
import BaseDateInput from '@/components/form/BaseDateInput.vue'
import { useReportStore } from '@/stores/reportStore'
import { useTradeStore } from '@/stores/tradeStore'
import { useUiStore } from '@/stores/uiStore'
import { asDate, asSignedCurrency } from '@/utils/format'
import type { Trade, TradeImage, TradePsychology } from '@/types/trade'

const router = useRouter()
const route = useRoute()
const tradeStore = useTradeStore()
const reportStore = useReportStore()
const uiStore = useUiStore()
const { trades, pagination, filters, loading, hasFilters, strategyModels, setups, killzones, tradeTags, sessionOptions } = storeToRefs(tradeStore)
const { reports } = storeToRefs(reportStore)

const detailsOpen = ref(false)
const detailsLoading = ref(false)
const detailsTrade = ref<Trade | null>(null)
const detailsImages = ref<TradeImage[]>([])
const detailsPsychology = ref<TradePsychology | null>(null)
const detailImageIndex = ref(0)
const activeDetailImage = computed(() => detailsImages.value[detailImageIndex.value] ?? null)
const filtersExpanded = ref(false)
const selectedSavedReportId = ref('')
type TradeQuickFocus = 'all' | 'needs_review' | 'rule_breaks' | 'losers' | 'no_screenshot'
const quickFocus = ref<TradeQuickFocus>('all')
const focusOptions: TradeQuickFocus[] = ['all', 'needs_review', 'rule_breaks', 'losers', 'no_screenshot']
const savedViewOptions = computed(() => [
  { label: 'No saved view', value: '' },
  ...reports.value
    .filter((report) => report.scope === 'trades')
    .map((report) => ({ label: report.name, value: String(report.id) })),
])

const filterDirectionOptions = [
  { label: 'All', value: '' },
  { label: 'Buy', value: 'buy' },
  { label: 'Sell', value: 'sell' },
]
const strategyFilterOptions = computed(() => [
  { label: 'All', value: '' },
  ...strategyModels.value.map((item) => ({ label: item.name, value: String(item.id) })),
])
const setupFilterOptions = computed(() => [
  { label: 'All', value: '' },
  ...setups.value.map((item) => ({ label: item.name, value: String(item.id) })),
])
const killzoneFilterOptions = computed(() => [
  { label: 'All', value: '' },
  ...killzones.value.map((item) => ({ label: item.name, value: String(item.id) })),
])
const sessionFilterOptions = computed(() => [
  { label: 'All', value: '' },
  ...sessionOptions.value.map((item) => ({ label: item.label, value: item.value })),
])
const tagFilterOptions = computed(() => tradeTags.value)

const activeFilterPills = computed(() => {
  const pills: string[] = []
  const pair = filters.value.pair.trim()
  const model = filters.value.model.trim()
  const strategyModelId = filters.value.strategy_model_id
  const setupId = filters.value.setup_id
  const killzoneId = filters.value.killzone_id
  const sessionEnum = filters.value.session_enum
  const tagIds = filters.value.tag_ids
  const direction = filters.value.direction
  const from = filters.value.date_from
  const to = filters.value.date_to

  if (pair) pills.push(`Symbol: ${pair.toUpperCase()}`)
  if (model) pills.push(`Model: ${model}`)
  if (strategyModelId) pills.push(`Strategy: ${strategyModels.value.find((item) => String(item.id) === strategyModelId)?.name ?? strategyModelId}`)
  if (setupId) pills.push(`Setup: ${setups.value.find((item) => String(item.id) === setupId)?.name ?? setupId}`)
  if (killzoneId) pills.push(`Killzone: ${killzones.value.find((item) => String(item.id) === killzoneId)?.name ?? killzoneId}`)
  if (sessionEnum) pills.push(`Session: ${sessionOptions.value.find((item) => item.value === sessionEnum)?.label ?? sessionEnum}`)
  if (tagIds) pills.push(`Tags: ${tagIds}`)
  if (direction) pills.push(`Direction: ${direction === 'buy' ? 'Long' : 'Short'}`)
  if (from && to) pills.push(`${from} to ${to}`)
  else if (from) pills.push(`From: ${from}`)
  else if (to) pills.push(`To: ${to}`)

  return pills
})

const needsReviewCount = computed(() => trades.value.filter((trade) => needsReview(trade)).length)
const ruleBreakCount = computed(() => trades.value.filter((trade) => !trade.followed_rules).length)
const losersCount = computed(() => trades.value.filter((trade) => Number(trade.profit_loss) < 0).length)
const noScreenshotCount = computed(() => trades.value.filter((trade) => imageCount(trade) === 0).length)
const averageReviewScore = computed(() => {
  if (trades.value.length === 0) return 0
  const total = trades.value.reduce((sum, trade) => sum + reviewQualityScore(trade), 0)
  return total / trades.value.length
})

const visibleTrades = computed(() => {
  if (quickFocus.value === 'needs_review') {
    return trades.value.filter((trade) => needsReview(trade))
  }
  if (quickFocus.value === 'rule_breaks') {
    return trades.value.filter((trade) => !trade.followed_rules)
  }
  if (quickFocus.value === 'losers') {
    return trades.value.filter((trade) => Number(trade.profit_loss) < 0)
  }
  if (quickFocus.value === 'no_screenshot') {
    return trades.value.filter((trade) => imageCount(trade) === 0)
  }
  return trades.value
})

const detailReviewScore = computed(() => {
  if (!detailsTrade.value) return 0
  const score = reviewQualityScore(detailsTrade.value)
  return detailsImages.value.length > 0 ? Math.min(100, score + 10) : score
})

const detailChecklist = computed(() => {
  if (!detailsTrade.value) return []
  const noteLength = (detailsTrade.value.notes ?? '').trim().length
  return [
    { label: 'Screenshot captured', done: detailsImages.value.length > 0 },
    { label: 'Execution note is specific', done: noteLength >= 25 },
    { label: 'Rule adherence marked', done: detailsTrade.value.followed_rules },
    { label: 'Emotion tagged', done: detailsTrade.value.emotion !== 'neutral' },
  ]
})

const detailNextAction = computed(() => {
  if (!detailsTrade.value) return ''
  if (detailsImages.value.length === 0) return 'Attach a chart screenshot before weekly review.'
  if ((detailsTrade.value.notes ?? '').trim().length < 25) return 'Add why entry and exit decisions were made.'
  if (!detailsTrade.value.followed_rules) return 'Document the rule break and prevention plan.'
  return 'Execution is review-ready for weekly process scoring.'
})

const selectedTagFilterIds = computed(() => {
  const values = `${filters.value.tag_ids ?? ''}`
    .split(',')
    .map((value) => Number(value.trim()))
    .filter((value) => Number.isInteger(value) && value > 0)
  return new Set(values)
})

function toggleTagFilter(tagId: number) {
  const next = new Set(selectedTagFilterIds.value)
  if (next.has(tagId)) {
    next.delete(tagId)
  } else {
    next.add(tagId)
  }

  filters.value.tag_ids = [...next].join(',')
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

function openQuickAddPage() {
  const query: Record<string, string> = { quick: '1' }
  const pair = filters.value.pair.trim()
  if (pair) query.symbol = pair.toUpperCase()
  if (filters.value.direction) query.direction = filters.value.direction
  void router.push({ path: '/trades/new', query })
}

function openEditPage(trade: Trade) {
  void router.push(`/trades/${trade.id}/edit`)
}

async function openDetails(trade: Trade) {
  detailsOpen.value = true
  detailsLoading.value = true
  detailsTrade.value = null
  detailsImages.value = []
  detailsPsychology.value = null
  detailImageIndex.value = 0

  try {
    const details = await tradeStore.fetchTradeDetails(trade.id)
    detailsTrade.value = details.trade
    detailsPsychology.value = details.psychology ?? details.trade.psychology ?? null
    detailsImages.value = (details.images ?? [])
      .slice()
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
  } catch {
    detailsOpen.value = false
    uiStore.toast({
      type: 'error',
      title: 'Failed to load execution details',
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

function primaryTradeImage(trade: Trade): TradeImage | null {
  const images = trade.images ?? []
  return images.length > 0 ? images[0]! : null
}

function directionLabel(trade: Trade) {
  return trade.direction === 'buy' ? 'Long' : 'Short'
}

function directionClass(trade: Trade) {
  return trade.direction === 'buy'
    ? 'pill trade-dir-pill long'
    : 'pill trade-dir-pill short'
}

function setImageFallback(event: Event, fallbackUrl?: string | null) {
  if (!fallbackUrl) return

  const target = event.target
  if (!(target instanceof HTMLImageElement)) return
  if (target.dataset.fallbackApplied === 'true') return

  target.dataset.fallbackApplied = 'true'
  target.src = fallbackUrl
}

function rMultipleLabel(trade: Trade) {
  const source = trade.r_multiple ?? trade.rr
  const value = Number(source)
  if (!Number.isFinite(value)) return '-'
  const sign = value > 0 ? '+' : ''
  return `${sign}${value.toFixed(2)}R`
}

function imageCount(trade: Trade) {
  return Number(trade.images_count ?? trade.images?.length ?? 0)
}

function hasThinNotes(trade: Trade) {
  return (trade.notes ?? '').trim().length < 25
}

function needsReview(trade: Trade) {
  return (
    imageCount(trade) === 0
    || hasThinNotes(trade)
    || !trade.followed_rules
    || Number(trade.profit_loss) < 0
  )
}

function reviewPriority(trade: Trade): 'high' | 'medium' | 'low' {
  const negativePnl = Number(trade.profit_loss) < 0
  if ((!trade.followed_rules && negativePnl) || (imageCount(trade) === 0 && hasThinNotes(trade))) {
    return 'high'
  }
  if (needsReview(trade)) {
    return 'medium'
  }
  return 'low'
}

function reviewQualityScore(trade: Trade) {
  let score = 100
  if (imageCount(trade) === 0) score -= 35
  if (hasThinNotes(trade)) score -= 25
  if (!trade.followed_rules) score -= 20
  if (trade.emotion === 'neutral') score -= 10
  if (Number(trade.profit_loss) >= 0 && trade.followed_rules) score += 5
  return Math.max(0, Math.min(100, score))
}

function reviewTier(score: number) {
  if (score >= 85) return 'A'
  if (score >= 70) return 'B'
  if (score >= 55) return 'C'
  return 'D'
}

function priorityLabel(trade: Trade) {
  const priority = reviewPriority(trade)
  if (priority === 'high') return 'Review first'
  if (priority === 'medium') return 'Review soon'
  return 'Review done'
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

function imageContextLabel(value: string | null | undefined) {
  if (!value) return 'Unlabeled'
  if (value === 'pre_entry') return 'Pre Entry'
  if (value === 'post_review') return 'Post Review'
  return value.replace('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

function selectDetailImage(index: number) {
  if (index < 0 || index >= detailsImages.value.length) return
  detailImageIndex.value = index
}

function normalizeQuickFocus(value: unknown): TradeQuickFocus {
  const normalized = `${value ?? ''}`.trim().toLowerCase()
  return focusOptions.includes(normalized as TradeQuickFocus) ? (normalized as TradeQuickFocus) : 'all'
}

function applyQuickFocusFromRoute() {
  quickFocus.value = normalizeQuickFocus(route.query.focus)
}

async function removeTrade(id: number) {
  const confirmed = await uiStore.askConfirmation({
    title: 'Delete execution entry?',
    message: 'This action cannot be undone.',
    confirmText: 'Delete',
    danger: true,
  })
  if (!confirmed) return

  try {
    await tradeStore.deleteTrade(id)
    uiStore.toast({
      type: 'success',
      title: 'Execution deleted',
    })
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Delete failed',
      message: 'Could not remove this execution.',
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

  await tradeStore.fetchTrades(1)
  filtersExpanded.value = false
}

async function clearFilters() {
  tradeStore.resetFilters()
  await tradeStore.fetchTrades(1)
  filtersExpanded.value = false
}

async function changePage(delta: number) {
  const next = pagination.value.current_page + delta
  if (next < 1 || next > pagination.value.last_page) return
  await tradeStore.fetchTrades(next)
}

async function loadSavedViews() {
  try {
    await reportStore.fetchReports('trades')
  } catch {
    // Ignore saved view loading failures; core trade log can still work.
  }
}

async function saveCurrentView() {
  const name = window.prompt('Saved view name')
  if (!name || !name.trim()) return

  try {
    const report = await reportStore.createReport({
      name: name.trim(),
      scope: 'trades',
      filters_json: { ...filters.value },
      columns_json: null,
      is_default: false,
    })
    selectedSavedReportId.value = String(report.id)
    await loadSavedViews()
    uiStore.toast({ type: 'success', title: 'Saved view created' })
  } catch {
    uiStore.toast({ type: 'error', title: 'Failed to save view' })
  }
}

async function applySavedView(reportId: string) {
  selectedSavedReportId.value = reportId
  if (!reportId) return
  const report = reports.value.find((item) => String(item.id) === reportId)
  if (!report) return
  const savedFilters = (report.filters_json ?? {}) as Record<string, unknown>

  filters.value.pair = String(savedFilters.pair ?? '')
  filters.value.direction = (String(savedFilters.direction ?? '') as '' | 'buy' | 'sell')
  filters.value.model = String(savedFilters.model ?? '')
  filters.value.strategy_model_id = String(savedFilters.strategy_model_id ?? '')
  filters.value.setup_id = String(savedFilters.setup_id ?? '')
  filters.value.killzone_id = String(savedFilters.killzone_id ?? '')
  filters.value.session_enum = String(savedFilters.session_enum ?? '')
  filters.value.tag_ids = String(savedFilters.tag_ids ?? '')
  filters.value.date_from = String(savedFilters.date_from ?? '')
  filters.value.date_to = String(savedFilters.date_to ?? '')
  await applyFilters()
}

function exportCurrentCsv() {
  reportStore.exportAdHocCsv({
    scope: 'trades',
    name: 'trade-log-view',
    ...filters.value,
  })
}

onMounted(async () => {
  try {
    await tradeStore.fetchDictionaries()
    await tradeStore.fetchTrades()
    await loadSavedViews()
    filtersExpanded.value = hasFilters.value
    applyQuickFocusFromRoute()
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load trade log',
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
  <div class="space-y-5 trade-log-minimal">
    <GlassPanel class="command-filter-shell">
      <div class="command-filter-bar">
        <div class="command-filter-left">
          <h2 class="section-title">Execute Log Filters</h2>
          <div class="filter-summary-strip">
            <span v-if="activeFilterPills.length === 0" class="section-note">No filters applied</span>
            <span v-for="pill in activeFilterPills" :key="`trade-pill-${pill}`" class="filter-chip-mini">{{ pill }}</span>
          </div>
        </div>
        <div class="command-filter-right">
          <div class="min-w-[220px]">
            <BaseSelect
              v-model="selectedSavedReportId"
              label="Saved View"
              size="sm"
              :options="savedViewOptions"
              @update:model-value="applySavedView"
            />
          </div>
          <button class="btn btn-ghost px-3 py-2 text-sm" @click="saveCurrentView">Save View</button>
          <button class="btn btn-ghost px-3 py-2 text-sm" @click="exportCurrentCsv">Export CSV</button>
          <button class="btn btn-secondary inline-flex items-center gap-2 px-3 py-2 text-sm" @click="openQuickAddPage">
            <Plus class="h-4 w-4" />
            New Execute
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
          <div class="form-block grid grid-premium md:grid-cols-2 xl:grid-cols-4">
            <BaseInput v-model="filters.pair" label="Symbol" placeholder="EURUSD" size="sm" />
            <BaseInput v-model="filters.model" label="Strategy Model" placeholder="Breakout" size="sm" />
            <BaseSelect v-model="filters.direction" label="Direction" :options="filterDirectionOptions" size="sm" />
            <BaseSelect v-model="filters.strategy_model_id" label="Strategy Taxonomy" :options="strategyFilterOptions" size="sm" />
            <BaseSelect v-model="filters.setup_id" label="Setup" :options="setupFilterOptions" size="sm" />
            <BaseSelect v-model="filters.killzone_id" label="Killzone" :options="killzoneFilterOptions" size="sm" />
            <BaseSelect v-model="filters.session_enum" label="Session" :options="sessionFilterOptions" size="sm" />
          </div>

          <div class="panel p-3">
            <p class="kicker-label">Tag Filter</p>
            <div class="mt-2 flex flex-wrap gap-2">
              <button
                v-for="tag in tagFilterOptions"
                :key="`filter-tag-${tag.id}`"
                type="button"
                class="chip-btn"
                :class="{ 'is-active': selectedTagFilterIds.has(tag.id) }"
                @click="toggleTagFilter(tag.id)"
              >
                {{ tag.name }}
              </button>
              <span v-if="tagFilterOptions.length === 0" class="section-note">No tags configured</span>
            </div>
          </div>

          <div class="filter-drawer-row">
            <div class="trade-filter-date-grid form-block">
              <BaseDateInput
                v-model="filters.date_from"
                label="Date From"
                size="sm"
                :max="filters.date_to || undefined"
              />
              <BaseDateInput
                v-model="filters.date_to"
                label="Date To"
                size="sm"
                :min="filters.date_from || undefined"
              />
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

    <section class="trade-review-strip grid grid-premium md:grid-cols-5">
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">Needs Review</p>
        <p class="trade-review-kpi-value value-display negative">
          <AnimatedNumber :value="needsReviewCount" />
        </p>
      </GlassPanel>
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">Rule Breaks</p>
        <p class="trade-review-kpi-value value-display negative">
          <AnimatedNumber :value="ruleBreakCount" />
        </p>
      </GlassPanel>
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">Losing Trades</p>
        <p class="trade-review-kpi-value value-display negative">
          <AnimatedNumber :value="losersCount" />
        </p>
      </GlassPanel>
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">No Screenshot</p>
        <p class="trade-review-kpi-value value-display">
          <AnimatedNumber :value="noScreenshotCount" />
        </p>
      </GlassPanel>
      <GlassPanel class="trade-review-kpi">
        <p class="kicker-label">Avg Review Score</p>
        <p class="trade-review-kpi-value value-display">
          <AnimatedNumber :value="averageReviewScore" :decimals="0" />
        </p>
      </GlassPanel>
    </section>

    <GlassPanel class="trade-focus-shell">
      <div class="trade-focus-row">
        <span class="section-note">Review focus</span>
        <div class="trade-focus-actions">
          <button type="button" class="chip-btn" :class="{ 'is-active': quickFocus === 'all' }" @click="quickFocus = 'all'">All</button>
          <button
            type="button"
            class="chip-btn"
            :class="{ 'is-active': quickFocus === 'needs_review' }"
            @click="quickFocus = 'needs_review'"
          >
            Needs Review
          </button>
          <button
            type="button"
            class="chip-btn"
            :class="{ 'is-active': quickFocus === 'rule_breaks' }"
            @click="quickFocus = 'rule_breaks'"
          >
            Rule Breaks
          </button>
          <button type="button" class="chip-btn" :class="{ 'is-active': quickFocus === 'losers' }" @click="quickFocus = 'losers'">Losers</button>
          <button
            type="button"
            class="chip-btn"
            :class="{ 'is-active': quickFocus === 'no_screenshot' }"
            @click="quickFocus = 'no_screenshot'"
          >
            No Screenshot
          </button>
        </div>
      </div>
    </GlassPanel>

    <section class="trade-db-shell panel p-4 md:p-5">
      <div class="section-head">
        <h2 class="section-title">Execute Log</h2>
        <p class="section-note">
          <span v-if="loading">Loading...</span>
          <span v-else>
            <AnimatedNumber :value="visibleTrades.length" /> shown
            <template v-if="quickFocus !== 'all'"> of <AnimatedNumber :value="pagination.total" /></template>
          </span>
        </p>
      </div>

      <div v-if="loading" class="trade-db-grid">
        <SkeletonBlock v-for="row in 6" :key="`trade-skeleton-${row}`" height-class="h-64" rounded-class="rounded-2xl" />
      </div>

      <EmptyState
        v-else-if="visibleTrades.length === 0"
        :title="quickFocus === 'all' ? 'No executions found' : 'No trades in this focus'"
        :description="quickFocus === 'all'
          ? 'Log your first execution or adjust filters to see results.'
          : 'Switch focus or broaden filters to keep review moving.'"
        :icon="BarChart3"
        cta-text="New Execute"
        @cta="openQuickAddPage"
      />

      <div v-else class="trade-db-grid">
        <article v-for="trade in visibleTrades" :key="trade.id" class="trade-db-card trade-card-reference">
          <button type="button" class="trade-db-media trade-card-reference-media" @click="openDetails(trade)">
            <img
              v-if="primaryTradeImage(trade)"
              :src="primaryTradeImage(trade)?.thumbnail_url || primaryTradeImage(trade)?.image_url"
              :alt="`${trade.pair} execution chart`"
              loading="lazy"
              class="trade-db-image"
              @error="setImageFallback($event, primaryTradeImage(trade)?.image_url)"
            />
            <div v-else class="trade-db-empty-chart trade-card-reference-empty">
              <ImageOff class="h-6 w-6" />
              <span>No screenshot</span>
            </div>
          </button>

          <div class="trade-card-reference-body">
            <div class="trade-card-reference-head">
              <div class="trade-card-reference-id">
                <h3>{{ trade.pair }}</h3>
                <span :class="directionClass(trade)">{{ directionLabel(trade) }}</span>
                <span class="trade-card-priority" :class="`is-${reviewPriority(trade)}`">{{ priorityLabel(trade) }}</span>
              </div>
              <p class="trade-card-reference-pnl value-display" :class="Number(trade.profit_loss) >= 0 ? 'positive' : 'negative'">
                {{ asSignedCurrency(trade.profit_loss) }}
              </p>
            </div>

            <div class="trade-card-reference-meta">
              <span>{{ cardDate(trade.date) }}</span>
              <span class="trade-card-reference-dot">&middot;</span>
              <span class="trade-card-reference-model">{{ modelLabel(trade.model) }}</span>
              <span class="trade-card-reference-dot">&middot;</span>
              <span class="trade-card-reference-images">
                <Images class="h-3.5 w-3.5" />
                {{ imageCount(trade) }}
              </span>
            </div>

            <div class="trade-card-score-row">
              <span class="section-note">Review Score</span>
              <span class="trade-card-score-pill value-display" :class="`tier-${reviewTier(reviewQualityScore(trade)).toLowerCase()}`">
                {{ reviewTier(reviewQualityScore(trade)) }} - {{ reviewQualityScore(trade) }}
              </span>
            </div>

            <div class="trade-card-reference-footer">
              <button type="button" class="trade-card-reference-review" @click="openDetails(trade)">
                <MessageSquare class="h-4 w-4" />
                Review
              </button>

              <div class="trade-card-reference-actions">
                <button type="button" class="trade-card-reference-icon-btn" @click="openDetails(trade)" aria-label="View trade">
                  <Eye class="h-4 w-4" />
                </button>
                <button type="button" class="trade-card-reference-icon-btn" @click="openEditPage(trade)" aria-label="Edit trade">
                  <Pencil class="h-4 w-4" />
                </button>
                <button
                  type="button"
                  class="trade-card-reference-icon-btn is-danger"
                  @click="removeTrade(trade.id)"
                  aria-label="Delete trade"
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
        <button class="btn btn-ghost px-3 py-1.5" :disabled="pagination.current_page === pagination.last_page" @click="changePage(1)">
          Next
        </button>
      </div>
    </section>

    <Transition name="fade">
      <div v-if="detailsOpen" class="trade-details-modal-backdrop" @click.self="closeDetails">
        <div class="trade-details-modal panel p-4">
          <div class="section-head">
            <h3 class="section-title">Execution Details</h3>
            <button type="button" class="btn btn-ghost p-2" @click="closeDetails">
              <X class="h-4 w-4" />
            </button>
          </div>

          <div v-if="detailsLoading" class="space-y-3">
            <SkeletonBlock v-for="row in 4" :key="`detail-skeleton-${row}`" height-class="h-12" rounded-class="rounded-xl" />
          </div>

          <div v-else-if="detailsTrade" class="trade-simple-detail">
            <section class="trade-modal-summary">
              <div class="trade-modal-summary-main">
                <h4>{{ detailsTrade.pair }}</h4>
                <span :class="directionClass(detailsTrade)">{{ directionLabel(detailsTrade) }}</span>
              </div>

              <div class="trade-modal-summary-meta">
                <span class="trade-modal-summary-date">
                  <CalendarDays class="h-3.5 w-3.5" />
                  {{ asDate(detailsTrade.date) }}
                </span>
                <span class="trade-modal-summary-stat" :class="Number(detailsTrade.profit_loss) >= 0 ? 'positive' : 'negative'">
                  {{ asSignedCurrency(detailsTrade.profit_loss) }}
                </span>
                <span class="trade-modal-summary-stat value-display">{{ rMultipleLabel(detailsTrade) }}</span>
              </div>
            </section>

            <div class="trade-simple-layout">
              <div class="trade-simple-image-shell">
                <img
                  v-if="activeDetailImage"
                  :src="activeDetailImage.image_url"
                  alt="Execution screenshot"
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

                <div v-if="activeDetailImage" class="trade-simple-image-meta">
                  <p><strong>Context:</strong> {{ imageContextLabel(activeDetailImage.context_tag) }}</p>
                  <p><strong>Timeframe:</strong> {{ activeDetailImage.timeframe || '-' }}</p>
                  <p><strong>Replay Note:</strong> {{ activeDetailImage.annotation_notes || '-' }}</p>
                </div>

                <div v-if="detailsImages.length > 1" class="trade-simple-replay-seq">
                  <button
                    v-for="(image, index) in detailsImages"
                    :key="`replay-${image.id}`"
                    type="button"
                    class="chip-btn"
                    :class="{ 'is-active': index === detailImageIndex }"
                    @click="selectDetailImage(index)"
                  >
                    {{ index + 1 }}. {{ imageContextLabel(image.context_tag) }}
                  </button>
                </div>
              </div>

              <div class="trade-simple-metrics">
                <article>
                  <span>Session</span>
                  <strong>{{ detailsTrade.session || '-' }}</strong>
                </article>
                <article>
                  <span>Model</span>
                  <strong>{{ detailsTrade.model || '-' }}</strong>
                </article>
                <article>
                  <span>Emotion</span>
                  <strong>{{ detailsTrade.emotion || '-' }}</strong>
                </article>
                <article>
                  <span>Images</span>
                  <strong class="value-display">{{ detailsImages.length }}</strong>
                </article>
                <article>
                  <span>Psychology Confidence</span>
                  <strong>{{ detailsPsychology?.confidence_score ?? '-' }}</strong>
                </article>
                <article>
                  <span>Psychology Stress</span>
                  <strong>{{ detailsPsychology?.stress_score ?? '-' }}</strong>
                </article>
                <article>
                  <span>Flags</span>
                  <strong>
                    {{
                      [
                        detailsPsychology?.impulse_flag ? 'Impulse' : '',
                        detailsPsychology?.fomo_flag ? 'FOMO' : '',
                        detailsPsychology?.revenge_flag ? 'Revenge' : '',
                      ].filter(Boolean).join(', ') || '-'
                    }}
                  </strong>
                </article>
                <article class="trade-simple-note">
                  <span>Notes</span>
                  <strong>{{ detailsTrade.notes || '-' }}</strong>
                </article>
                <article class="trade-simple-note">
                  <span>Psych Notes</span>
                  <strong>{{ detailsPsychology?.notes || '-' }}</strong>
                </article>
              </div>
            </div>

            <section class="trade-review-checklist panel">
              <div class="trade-review-checklist-head">
                <p class="kicker-label">Review Checklist</p>
                <span class="trade-card-score-pill value-display" :class="`tier-${reviewTier(detailReviewScore).toLowerCase()}`">
                  {{ reviewTier(detailReviewScore) }} - {{ detailReviewScore }}
                </span>
              </div>

              <div class="trade-review-checklist-list">
                <p v-for="item in detailChecklist" :key="item.label" :class="item.done ? 'is-done' : 'is-pending'">
                  <CheckCircle2 v-if="item.done" class="h-4 w-4" />
                  <AlertCircle v-else class="h-4 w-4" />
                  <span>{{ item.label }}</span>
                </p>
              </div>

              <p class="section-note">{{ detailNextAction }}</p>
            </section>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
