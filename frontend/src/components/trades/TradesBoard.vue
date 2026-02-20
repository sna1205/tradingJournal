<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { BarChart3, Pencil, Plus, Trash2, X } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import { useTradeStore, type TradePayload } from '@/stores/tradeStore'
import { useUiStore } from '@/stores/uiStore'
import { asDate, asSignedCurrency } from '@/utils/format'
import type { Trade } from '@/types/trade'

const tradeStore = useTradeStore()
const uiStore = useUiStore()
const { trades, pagination, filters, loading, saving, hasFilters } = storeToRefs(tradeStore)

const isModalOpen = ref(false)
const editingTradeId = ref<number | null>(null)

const form = reactive({
  pair: '',
  direction: 'buy' as 'buy' | 'sell',
  date: '',
  model: '',
  session: '',
  entry_price: 0,
  stop_loss: 0,
  take_profit: 0,
  lot_size: 0.01,
  profit_loss: 0,
  rr: 1,
  notes: '',
})

const modalTitle = computed(() => (editingTradeId.value ? 'Edit Trade' : 'Add Trade'))

function resetForm() {
  editingTradeId.value = null
  form.pair = ''
  form.direction = 'buy'
  form.date = ''
  form.model = ''
  form.session = ''
  form.entry_price = 0
  form.stop_loss = 0
  form.take_profit = 0
  form.lot_size = 0.01
  form.profit_loss = 0
  form.rr = 1
  form.notes = ''
}

function openAddModal() {
  resetForm()
  form.date = toLocalDateTime(new Date().toISOString())
  isModalOpen.value = true
}

function openEditModal(trade: Trade) {
  editingTradeId.value = trade.id
  form.pair = trade.pair
  form.direction = trade.direction
  form.date = toLocalDateTime(trade.date)
  form.model = trade.model
  form.session = trade.session
  form.entry_price = Number(trade.entry_price)
  form.stop_loss = Number(trade.stop_loss)
  form.take_profit = Number(trade.take_profit)
  form.lot_size = Number(trade.lot_size)
  form.profit_loss = Number(trade.profit_loss)
  form.rr = Number(trade.rr)
  form.notes = trade.notes || ''
  isModalOpen.value = true
}

function closeModal() {
  isModalOpen.value = false
}

function toLocalDateTime(value: string) {
  const date = new Date(value)
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

function buildPayload(): TradePayload {
  return {
    pair: form.pair.toUpperCase(),
    direction: form.direction,
    date: new Date(form.date).toISOString(),
    model: form.model,
    session: form.session,
    entry_price: Number(form.entry_price),
    stop_loss: Number(form.stop_loss),
    take_profit: Number(form.take_profit),
    lot_size: Number(form.lot_size),
    profit_loss: Number(form.profit_loss),
    rr: Number(form.rr),
    notes: form.notes.trim() ? form.notes.trim() : null,
  }
}

async function submitForm() {
  if (!form.pair || !form.model || !form.session || !form.date) return

  try {
    const payload = buildPayload()

    if (editingTradeId.value) {
      await tradeStore.updateTrade(editingTradeId.value, payload)
      uiStore.toast({
        type: 'success',
        title: 'Trade updated',
        message: `${payload.pair} was updated successfully.`,
      })
    } else {
      await tradeStore.addTrade(payload)
      uiStore.toast({
        type: 'success',
        title: 'Trade added',
        message: `${payload.pair} has been saved to your journal.`,
      })
    }

    closeModal()
    resetForm()
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to save trade',
      message: 'Please review input values and try again.',
    })
  }
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
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid min-w-[280px] flex-1 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
          <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
            Pair
            <input
              v-model="filters.pair"
              type="text"
              placeholder="EURUSD"
              class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            />
          </label>
          <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
            Model
            <input
              v-model="filters.model"
              type="text"
              placeholder="Breakout"
              class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            />
          </label>
          <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
            Direction
            <select
              v-model="filters.direction"
              class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
            >
              <option value="">All</option>
              <option value="buy">Buy</option>
              <option value="sell">Sell</option>
            </select>
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
        </div>

        <div class="flex items-center gap-2">
          <button
            class="rounded-2xl border border-emerald-400/70 bg-emerald-500/20 px-4 py-2 text-sm font-semibold text-emerald-100 transition-all duration-200 ease-in-out hover:bg-emerald-500/35"
            @click="openAddModal"
          >
            <span class="inline-flex items-center gap-2">
              <Plus class="h-4 w-4" />
              Add Trade
            </span>
          </button>
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
      </div>
    </GlassPanel>

    <GlassPanel>
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-bold">Trades</h2>
        <p class="text-sm text-slate-400">
          <span v-if="loading">Loading...</span>
          <span v-else>
            <AnimatedNumber :value="pagination.total" /> records
          </span>
        </p>
      </div>

      <div v-if="loading" class="space-y-3">
        <SkeletonBlock v-for="row in 6" :key="`trade-skeleton-${row}`" height-class="h-12" rounded-class="rounded-xl" />
      </div>

      <EmptyState
        v-else-if="trades.length === 0"
        title="No trades found"
        description="Add your first trade or adjust filters to see results."
        :icon="BarChart3"
        cta-text="Add Trade"
        @cta="openAddModal"
      />

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead>
            <tr class="border-b border-slate-700/80 text-xs uppercase tracking-[0.12em] text-slate-400">
              <th class="px-3 py-3">Pair</th>
              <th class="px-3 py-3">Direction</th>
              <th class="px-3 py-3">Date</th>
              <th class="px-3 py-3">Model</th>
              <th class="px-3 py-3">Profit/Loss</th>
              <th class="px-3 py-3">R:R</th>
              <th class="px-3 py-3">Session</th>
              <th class="px-3 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="trade in trades"
              :key="trade.id"
              class="border-b border-slate-800/70 transition-all duration-200 ease-in-out hover:bg-slate-900/70"
            >
              <td class="px-3 py-3 font-semibold text-slate-100">{{ trade.pair }}</td>
              <td class="px-3 py-3">
                <span
                  class="rounded-xl px-2.5 py-1 text-xs font-semibold uppercase"
                  :class="trade.direction === 'buy' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'"
                >
                  {{ trade.direction }}
                </span>
              </td>
              <td class="px-3 py-3 text-slate-300">{{ asDate(trade.date) }}</td>
              <td class="px-3 py-3 text-slate-300">{{ trade.model }}</td>
              <td
                class="px-3 py-3 font-semibold"
                :class="Number(trade.profit_loss) >= 0 ? 'text-emerald-400' : 'text-rose-400'"
              >
                {{ asSignedCurrency(trade.profit_loss) }}
              </td>
              <td class="px-3 py-3 text-slate-200">{{ Number(trade.rr).toFixed(2) }}</td>
              <td class="px-3 py-3 text-slate-300">{{ trade.session }}</td>
              <td class="px-3 py-3">
                <div class="flex items-center justify-end gap-2">
                  <button
                    class="rounded-xl border border-slate-600 p-2 text-slate-200 transition-all duration-200 ease-in-out hover:border-emerald-400/70 hover:bg-emerald-500/20"
                    @click="openEditModal(trade)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button
                    class="rounded-xl border border-rose-400/40 p-2 text-rose-300 transition-all duration-200 ease-in-out hover:bg-rose-500/20"
                    @click="removeTrade(trade.id)"
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

    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="isModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
      >
        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="translate-y-2 scale-[0.98] opacity-0"
          enter-to-class="translate-y-0 scale-100 opacity-100"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="translate-y-0 scale-100 opacity-100"
          leave-to-class="translate-y-2 scale-[0.98] opacity-0"
        >
          <div class="glass-card w-full max-w-3xl rounded-2xl border border-slate-700 p-6">
            <div class="mb-5 flex items-center justify-between">
              <h3 class="text-xl font-bold">{{ modalTitle }}</h3>
              <button
                class="rounded-xl border border-slate-600 p-2 text-slate-300 transition-all duration-200 ease-in-out hover:bg-slate-700/40"
                @click="closeModal"
              >
                <X class="h-4 w-4" />
              </button>
            </div>

            <form class="space-y-4" @submit.prevent="submitForm">
              <div class="grid gap-4 md:grid-cols-2">
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
                  Direction
                  <select
                    v-model="form.direction"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                  >
                    <option value="buy">Buy</option>
                    <option value="sell">Sell</option>
                  </select>
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
                  Session
                  <input
                    v-model="form.session"
                    required
                    type="text"
                    placeholder="London"
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
                  Lot Size
                  <input
                    v-model.number="form.lot_size"
                    required
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                  />
                </label>
                <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
                  Entry Price
                  <input
                    v-model.number="form.entry_price"
                    required
                    type="number"
                    step="0.000001"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                  />
                </label>
                <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
                  Stop Loss
                  <input
                    v-model.number="form.stop_loss"
                    required
                    type="number"
                    step="0.000001"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                  />
                </label>
                <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
                  Take Profit
                  <input
                    v-model.number="form.take_profit"
                    required
                    type="number"
                    step="0.000001"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                  />
                </label>
                <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
                  Profit/Loss
                  <input
                    v-model.number="form.profit_loss"
                    required
                    type="number"
                    step="0.01"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                  />
                </label>
                <label class="text-xs uppercase tracking-[0.14em] text-slate-400">
                  R:R
                  <input
                    v-model.number="form.rr"
                    required
                    type="number"
                    step="0.01"
                    min="0"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                  />
                </label>
              </div>

              <label class="block text-xs uppercase tracking-[0.14em] text-slate-400">
                Notes
                <textarea
                  v-model="form.notes"
                  rows="3"
                  class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900/60 text-sm text-slate-100"
                />
              </label>

              <div class="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  class="rounded-2xl border border-slate-600 px-4 py-2 text-sm font-semibold transition-all duration-200 ease-in-out hover:bg-slate-700/40"
                  @click="closeModal"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  class="rounded-2xl border border-emerald-400/70 bg-emerald-500/20 px-4 py-2 text-sm font-semibold text-emerald-100 transition-all duration-200 ease-in-out hover:bg-emerald-500/35"
                  :disabled="saving"
                >
                  {{ saving ? 'Saving...' : editingTradeId ? 'Update Trade' : 'Save Trade' }}
                </button>
              </div>
            </form>
          </div>
        </Transition>
      </div>
    </Transition>
  </div>
</template>
