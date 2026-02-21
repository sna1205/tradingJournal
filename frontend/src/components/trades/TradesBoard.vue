<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import {
  BarChart3,
  CalendarDays,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Eye,
  ImageOff,
  Images,
  MessageSquareText,
  Pencil,
  Plus,
  Trash2,
  X,
  Zap,
  ZoomIn,
  ZoomOut,
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
const lightboxOpen = ref(false)
const lightboxIndex = ref(0)
const lightboxZoom = ref(1)
const activeLightboxImage = computed(() => detailsImages.value[lightboxIndex.value] ?? null)

const filterDirectionOptions = [
  { label: 'All', value: '' },
  { label: 'Buy', value: 'buy' },
  { label: 'Sell', value: 'sell' },
]

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

function openAddPage() {
  void router.push('/trades/new')
}

function openEditPage(trade: Trade) {
  void router.push(`/trades/${trade.id}/edit`)
}

async function openDetails(trade: Trade) {
  detailsOpen.value = true
  detailsLoading.value = true
  detailsTrade.value = null
  detailsImages.value = []
  lightboxOpen.value = false
  lightboxIndex.value = 0

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
      title: 'Failed to load trade details',
      message: 'Please try again.',
    })
  } finally {
    detailsLoading.value = false
  }
}

function closeDetails() {
  detailsOpen.value = false
  lightboxOpen.value = false
  lightboxZoom.value = 1
}

function openLightbox(index: number) {
  lightboxIndex.value = index
  lightboxOpen.value = true
  lightboxZoom.value = 1
}

function closeLightbox() {
  lightboxOpen.value = false
  lightboxZoom.value = 1
}

function nextLightboxImage() {
  if (detailsImages.value.length <= 1) return
  lightboxIndex.value = (lightboxIndex.value + 1) % detailsImages.value.length
}

function previousLightboxImage() {
  if (detailsImages.value.length <= 1) return
  lightboxIndex.value = (lightboxIndex.value - 1 + detailsImages.value.length) % detailsImages.value.length
}

function zoomIn() {
  lightboxZoom.value = Math.min(3, Number((lightboxZoom.value + 0.25).toFixed(2)))
}

function zoomOut() {
  lightboxZoom.value = Math.max(1, Number((lightboxZoom.value - 0.25).toFixed(2)))
}

function primaryTradeImage(trade: Trade): TradeImage | null {
  const images = trade.images ?? []
  return images.length > 0 ? images[0]! : null
}

function reviewedClass(trade: Trade) {
  return primaryTradeImage(trade)
    ? 'trade-db-status reviewed'
    : 'trade-db-status open'
}

function reviewedLabel(trade: Trade) {
  return primaryTradeImage(trade) ? 'Reviewed' : 'Open'
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

function truncateModel(value: string) {
  const normalized = value?.trim() || 'General'
  if (normalized.length <= 20) return normalized
  return `${normalized.slice(0, 20)}...`
}

async function removeTrade(id: number) {
  const confirmed = await uiStore.askConfirmation({
    title: 'Delete trade entry?',
    message: 'This action cannot be undone.',
    confirmText: 'Delete',
    danger: true,
  })
  if (!confirmed) return

  try {
    await tradeStore.deleteTrade(id)
    uiStore.toast({
      type: 'success',
      title: 'Trade deleted',
    })
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Delete failed',
      message: 'Could not remove this trade.',
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
}

async function clearFilters() {
  tradeStore.resetFilters()
  await tradeStore.fetchTrades(1)
}

async function changePage(delta: number) {
  const next = pagination.value.current_page + delta
  if (next < 1 || next > pagination.value.last_page) return
  await tradeStore.fetchTrades(next)
}

onMounted(async () => {
  try {
    await tradeStore.fetchTrades()
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load trades',
      message: 'Please refresh and try again.',
    })
  }
})
</script>

<template>
  <div class="space-y-6">
    <GlassPanel>
      <div class="section-head">
        <h2 class="section-title">Trade Filters</h2>
        <div class="flex flex-wrap items-center gap-2">
          <button class="btn btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm" @click="openAddPage">
            <Plus class="h-4 w-4" />
            Add Trade
          </button>
          <button class="btn btn-ghost px-4 py-2 text-sm" @click="applyFilters">Apply</button>
          <button v-if="hasFilters" class="btn btn-ghost px-4 py-2 text-sm" @click="clearFilters">Reset</button>
        </div>
      </div>

      <div class="form-block grid grid-premium md:grid-cols-2 xl:grid-cols-3">
        <BaseInput v-model="filters.pair" label="Symbol" placeholder="EURUSD" size="sm" />
        <BaseInput v-model="filters.model" label="Strategy Model" placeholder="Breakout" size="sm" />
        <BaseSelect v-model="filters.direction" label="Direction" :options="filterDirectionOptions" size="sm" />
      </div>

      <div class="trade-filter-date-panel">
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
    </GlassPanel>

    <section class="trade-db-shell panel p-4 md:p-5">
      <div class="section-head">
        <h2 class="section-title text-white">Trades</h2>
        <p class="section-note">
          <span v-if="loading">Loading...</span>
          <span v-else><AnimatedNumber :value="pagination.total" /> records</span>
        </p>
      </div>

      <div v-if="loading" class="trade-db-grid">
        <SkeletonBlock v-for="row in 6" :key="`trade-skeleton-${row}`" height-class="h-64" rounded-class="rounded-2xl" />
      </div>

      <EmptyState
        v-else-if="trades.length === 0"
        title="No trades found"
        description="Add your first trade or adjust filters to see results."
        :icon="BarChart3"
        cta-text="Add Trade"
        @cta="openAddPage"
      />

      <div v-else class="trade-db-grid">
        <article v-for="trade in trades" :key="trade.id" class="trade-db-card">
          <button type="button" class="trade-db-media" @click="openDetails(trade)">
            <img
              v-if="primaryTradeImage(trade)"
              :src="primaryTradeImage(trade)?.thumbnail_url || primaryTradeImage(trade)?.image_url"
              :alt="`${trade.pair} chart`"
              loading="lazy"
              class="trade-db-image"
              @error="setImageFallback($event, primaryTradeImage(trade)?.image_url)"
            />
            <div v-else class="trade-db-empty-chart">
              <ImageOff class="h-9 w-9" />
              <span>No chart uploaded</span>
            </div>

            <span :class="reviewedClass(trade)">
              <CheckCircle2 class="h-3.5 w-3.5" />
              {{ reviewedLabel(trade) }}
            </span>
          </button>

          <div class="trade-db-body">
            <div class="trade-db-title-row">
              <h3>{{ trade.pair }}</h3>
              <span :class="directionClass(trade)">{{ directionLabel(trade) }}</span>
              <strong class="trade-db-pnl" :class="Number(trade.profit_loss) >= 0 ? 'positive' : 'negative'">
                {{ asSignedCurrency(trade.profit_loss) }}
              </strong>
            </div>

            <div class="trade-db-meta-row">
              <span class="inline-flex items-center gap-1">
                <CalendarDays class="h-3.5 w-3.5" />
                {{ asDate(trade.date) }}
              </span>
              <span class="trade-dot">•</span>
              <span class="trade-db-model">{{ truncateModel(trade.model) }}</span>
              <span class="trade-dot">•</span>
              <span class="inline-flex items-center gap-1">
                <Images class="h-3.5 w-3.5" />
                {{ trade.images_count ?? trade.images?.length ?? 0 }}
              </span>
              <span v-if="trade.notes" class="inline-flex items-center gap-1 text-amber-300">
                <Zap class="h-3.5 w-3.5" />
                1
              </span>
            </div>

            <div class="trade-db-actions">
              <button type="button" class="btn btn-ghost px-3 py-1.5 text-xs" @click="openDetails(trade)">
                <Eye class="h-3.5 w-3.5" />
                Open
              </button>
              <button type="button" class="btn btn-ghost px-3 py-1.5 text-xs" @click="openEditPage(trade)">
                <Pencil class="h-3.5 w-3.5" />
                Edit
              </button>
              <button type="button" class="btn btn-danger px-3 py-1.5 text-xs" @click="removeTrade(trade.id)">
                <Trash2 class="h-3.5 w-3.5" />
                Delete
              </button>
              <span v-if="trade.notes" class="ml-auto inline-flex items-center gap-1 text-xs muted">
                <MessageSquareText class="h-3.5 w-3.5" />
                note
              </span>
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
            <h3 class="section-title">Trade Details</h3>
            <button type="button" class="btn btn-ghost p-2" @click="closeDetails">
              <X class="h-4 w-4" />
            </button>
          </div>

          <div v-if="detailsLoading" class="space-y-3">
            <SkeletonBlock v-for="row in 4" :key="`detail-skeleton-${row}`" height-class="h-12" rounded-class="rounded-xl" />
          </div>

          <div v-else-if="detailsTrade" class="space-y-4">
            <div class="grid grid-premium md:grid-cols-2 xl:grid-cols-3">
              <article class="panel p-3">
                <p class="kicker-label">Symbol</p>
                <p class="mt-1 font-semibold">{{ detailsTrade.pair }}</p>
              </article>
              <article class="panel p-3">
                <p class="kicker-label">Direction</p>
                <p class="mt-1 font-semibold">{{ detailsTrade.direction }}</p>
              </article>
              <article class="panel p-3">
                <p class="kicker-label">Date</p>
                <p class="mt-1 font-semibold">{{ asDate(detailsTrade.date) }}</p>
              </article>
              <article class="panel p-3">
                <p class="kicker-label">P/L</p>
                <p class="mt-1 font-semibold" :class="Number(detailsTrade.profit_loss) >= 0 ? 'positive' : 'negative'">
                  {{ asSignedCurrency(detailsTrade.profit_loss) }}
                </p>
              </article>
              <article class="panel p-3">
                <p class="kicker-label">R-Multiple</p>
                <p class="mt-1 font-semibold value-display">{{ Number(detailsTrade.r_multiple ?? detailsTrade.rr).toFixed(2) }}</p>
              </article>
              <article class="panel p-3">
                <p class="kicker-label">Risk %</p>
                <p class="mt-1 font-semibold value-display">{{ Number(detailsTrade.risk_percent ?? 0).toFixed(2) }}%</p>
              </article>
            </div>

            <article class="panel p-3">
              <p class="kicker-label">Notes</p>
              <p class="mt-2 text-sm">{{ detailsTrade.notes || 'No notes.' }}</p>
            </article>

            <div>
              <div class="section-head">
                <h4 class="section-title">Screenshots</h4>
                <p class="section-note">{{ detailsImages.length }} image(s)</p>
              </div>

              <div v-if="detailsImages.length === 0" class="panel p-4 text-sm muted">
                No screenshots attached to this trade.
              </div>

              <div v-else class="trade-image-grid">
                <button
                  v-for="(image, index) in detailsImages"
                  :key="`details-image-${image.id}`"
                  type="button"
                  class="trade-image-card trade-image-card-button"
                  @click="openLightbox(index)"
                >
                  <img
                    :src="image.thumbnail_url || image.image_url"
                    alt="Trade screenshot thumbnail"
                    loading="lazy"
                    class="trade-image-thumb"
                    @error="setImageFallback($event, image.image_url)"
                  />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="fade">
      <div v-if="lightboxOpen && activeLightboxImage" class="trade-lightbox-backdrop" @click.self="closeLightbox">
        <button type="button" class="trade-lightbox-nav left" @click="previousLightboxImage">
          <ChevronLeft class="h-5 w-5" />
        </button>

        <div class="trade-lightbox-content">
          <img
            :src="activeLightboxImage.image_url"
            alt="Trade screenshot full"
            class="trade-lightbox-image"
            :style="{ transform: `scale(${lightboxZoom})` }"
          />
        </div>

        <button type="button" class="trade-lightbox-nav right" @click="nextLightboxImage">
          <ChevronRight class="h-5 w-5" />
        </button>

        <button type="button" class="trade-lightbox-close btn btn-ghost p-2" @click="closeLightbox">
          <X class="h-4 w-4" />
        </button>

        <div class="trade-lightbox-zoom">
          <button type="button" class="btn btn-ghost p-2" @click="zoomOut">
            <ZoomOut class="h-4 w-4" />
          </button>
          <span class="value-display text-xs">{{ (lightboxZoom * 100).toFixed(0) }}%</span>
          <button type="button" class="btn btn-ghost p-2" @click="zoomIn">
            <ZoomIn class="h-4 w-4" />
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>
