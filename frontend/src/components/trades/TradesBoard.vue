<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import {
  BarChart3,
  CalendarDays,
  ChevronDown,
  ChevronUp,
  ChevronLeft,
  ChevronRight,
  Eye,
  ImageOff,
  Images,
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
import { useTradeStore } from '@/stores/tradeStore'
import { useUiStore } from '@/stores/uiStore'
import { asDate, asSignedCurrency } from '@/utils/format'
import type { Trade, TradeImage } from '@/types/trade'

const router = useRouter()
const tradeStore = useTradeStore()
const uiStore = useUiStore()
const { trades, pagination, filters, loading, hasFilters } = storeToRefs(tradeStore)

const detailsOpen = ref(false)
const detailsLoading = ref(false)
const detailsTrade = ref<Trade | null>(null)
const detailsImages = ref<TradeImage[]>([])
const detailImageIndex = ref(0)
const activeDetailImage = computed(() => detailsImages.value[detailImageIndex.value] ?? null)
const filtersExpanded = ref(false)

const filterDirectionOptions = [
  { label: 'All', value: '' },
  { label: 'Buy', value: 'buy' },
  { label: 'Sell', value: 'sell' },
]

const activeFilterPills = computed(() => {
  const pills: string[] = []
  const pair = filters.value.pair.trim()
  const model = filters.value.model.trim()
  const direction = filters.value.direction
  const from = filters.value.date_from
  const to = filters.value.date_to

  if (pair) pills.push(`Symbol: ${pair.toUpperCase()}`)
  if (model) pills.push(`Model: ${model}`)
  if (direction) pills.push(`Direction: ${direction === 'buy' ? 'Long' : 'Short'}`)
  if (from && to) pills.push(`${from} to ${to}`)
  else if (from) pills.push(`From: ${from}`)
  else if (to) pills.push(`To: ${to}`)

  return pills
})

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
  detailImageIndex.value = 0

  try {
    const details = await tradeStore.fetchTradeDetails(trade.id)
    detailsTrade.value = details.trade
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

onMounted(async () => {
  try {
    await tradeStore.fetchTrades()
    filtersExpanded.value = hasFilters.value
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load trade log',
      message: 'Please refresh and try again.',
    })
  }
})
</script>

<template>
  <div class="space-y-5 trade-log-minimal">
    <GlassPanel class="command-filter-shell">
      <div class="command-filter-bar">
        <div class="command-filter-left">
          <h2 class="section-title">Trade Log Filters</h2>
          <div class="filter-summary-strip">
            <span v-if="activeFilterPills.length === 0" class="section-note">No filters applied</span>
            <span v-for="pill in activeFilterPills" :key="`trade-pill-${pill}`" class="filter-chip-mini">{{ pill }}</span>
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
          <div class="form-block grid grid-premium md:grid-cols-2 xl:grid-cols-3">
            <BaseInput v-model="filters.pair" label="Symbol" placeholder="EURUSD" size="sm" />
            <BaseInput v-model="filters.model" label="Strategy Model" placeholder="Breakout" size="sm" />
            <BaseSelect v-model="filters.direction" label="Direction" :options="filterDirectionOptions" size="sm" />
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

    <section class="trade-db-shell panel p-4 md:p-5">
      <div class="section-head">
        <h2 class="section-title">Trade Log</h2>
        <p class="section-note">
          <span v-if="loading">Loading...</span>
          <span v-else><AnimatedNumber :value="pagination.total" /> trades</span>
        </p>
      </div>

      <div v-if="loading" class="trade-db-grid">
        <SkeletonBlock v-for="row in 6" :key="`trade-skeleton-${row}`" height-class="h-64" rounded-class="rounded-2xl" />
      </div>

      <EmptyState
        v-else-if="trades.length === 0"
        title="No executions found"
        description="Log your first execution or adjust filters to see results."
        :icon="BarChart3"
        cta-text="Quick Add"
        @cta="openQuickAddPage"
      />

      <div v-else class="trade-db-grid">
        <article v-for="trade in trades" :key="trade.id" class="trade-db-card trade-log-row">
          <button type="button" class="trade-db-media" @click="openDetails(trade)">
            <img
              v-if="primaryTradeImage(trade)"
              :src="primaryTradeImage(trade)?.thumbnail_url || primaryTradeImage(trade)?.image_url"
              :alt="`${trade.pair} execution chart`"
              loading="lazy"
              class="trade-db-image"
              @error="setImageFallback($event, primaryTradeImage(trade)?.image_url)"
            />
            <div v-else class="trade-db-empty-chart">
              <ImageOff class="h-8 w-8" />
              <span>No chart uploaded</span>
            </div>
            <span class="pill trade-log-image-count">
              <Images class="h-3.5 w-3.5" />
              {{ trade.images_count ?? trade.images?.length ?? 0 }}
            </span>
          </button>

          <div class="trade-log-row-content">
            <div class="trade-log-row-head">
              <div class="trade-log-row-id">
                <h3>{{ trade.pair }}</h3>
                <span :class="directionClass(trade)">{{ directionLabel(trade) }}</span>
              </div>
              <span class="trade-log-row-date">
                <CalendarDays class="h-3.5 w-3.5" />
                {{ asDate(trade.date) }}
              </span>
            </div>

            <div class="trade-log-row-metrics">
              <article class="trade-log-metric">
                <p class="kicker-label">P/L</p>
                <strong class="trade-db-pnl" :class="Number(trade.profit_loss) >= 0 ? 'positive' : 'negative'">
                  {{ asSignedCurrency(trade.profit_loss) }}
                </strong>
              </article>
              <article class="trade-log-metric">
                <p class="kicker-label">R</p>
                <strong class="value-display">{{ rMultipleLabel(trade) }}</strong>
              </article>
            </div>

            <div class="trade-db-actions">
              <button type="button" class="btn btn-ghost px-3 py-1.5 text-xs" @click="openDetails(trade)">
                <Eye class="h-3.5 w-3.5" />
                View
              </button>
              <button type="button" class="btn btn-ghost px-3 py-1.5 text-xs" @click="openEditPage(trade)">
                <Pencil class="h-3.5 w-3.5" />
                Edit
              </button>
              <button type="button" class="btn btn-secondary px-3 py-1.5 text-xs" @click="removeTrade(trade.id)">
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
        <button class="btn btn-ghost px-3 py-1.5" :disabled="pagination.current_page === pagination.last_page" @click="changePage(1)">
          Next
        </button>
      </div>
    </section>

    <Transition name="fade">
      <div v-if="detailsOpen" class="trade-details-modal-backdrop" @click.self="closeDetails">
        <div class="trade-details-modal panel p-5">
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
            <div class="trade-simple-image-shell">
              <img
                v-if="activeDetailImage"
                :src="activeDetailImage.image_url"
                alt="Execution screenshot"
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
                <span>Symbol</span>
                <strong>{{ detailsTrade.pair }}</strong>
              </article>
              <article>
                <span>Direction</span>
                <strong>{{ directionLabel(detailsTrade) }}</strong>
              </article>
              <article>
                <span>Date</span>
                <strong>{{ asDate(detailsTrade.date) }}</strong>
              </article>
              <article>
                <span>Total P/L</span>
                <strong :class="Number(detailsTrade.profit_loss) >= 0 ? 'positive' : 'negative'">
                  {{ asSignedCurrency(detailsTrade.profit_loss) }}
                </strong>
              </article>
              <article>
                <span>R</span>
                <strong class="value-display">{{ rMultipleLabel(detailsTrade) }}</strong>
              </article>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
