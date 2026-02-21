<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import type { AxiosError } from 'axios'
import { BarChart3, Pencil, Plus, Trash2, X } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import AnimatedNumber from '@/components/layout/AnimatedNumber.vue'
import { useTradeStore, type TradePayload } from '@/stores/tradeStore'
import { useUiStore } from '@/stores/uiStore'
import { asDate, asSignedCurrency } from '@/utils/format'
import type { Trade, TradeEmotion } from '@/types/trade'

const tradeStore = useTradeStore()
const uiStore = useUiStore()
const { trades, pagination, filters, loading, saving, hasFilters } = storeToRefs(tradeStore)

const isModalOpen = ref(false)
const editingTradeId = ref<number | null>(null)
const submitAttempted = ref(false)
const closeDateMax = ref('')
const emotionOptions: TradeEmotion[] = ['neutral', 'calm', 'confident', 'fearful', 'greedy', 'hesitant', 'revenge']

const form = reactive({
  symbol: '',
  direction: 'buy' as 'buy' | 'sell',
  date: '',
  model: '',
  session: '',
  entry_price: 0,
  stop_loss: 0,
  take_profit: 0,
  actual_exit_price: 0,
  position_size: 0.01,
  account_balance_before_trade: 0,
  followed_rules: true,
  emotion: 'neutral' as TradeEmotion,
  notes: '',
})

const modalTitle = computed(() => (editingTradeId.value ? 'Edit Trade' : 'Add Trade'))

function resetForm() {
  editingTradeId.value = null
  submitAttempted.value = false
  form.symbol = ''
  form.direction = 'buy'
  form.date = ''
  form.model = ''
  form.session = ''
  form.entry_price = 0
  form.stop_loss = 0
  form.take_profit = 0
  form.actual_exit_price = 0
  form.position_size = 0.01
  form.account_balance_before_trade = 0
  form.followed_rules = true
  form.emotion = 'neutral'
  form.notes = ''
}

function openAddModal() {
  resetForm()
  closeDateMax.value = nowLocalDateTime()
  form.date = closeDateMax.value
  isModalOpen.value = true
}

function openEditModal(trade: Trade) {
  editingTradeId.value = trade.id
  form.symbol = trade.pair
  form.direction = trade.direction
  form.date = toLocalDateTime(trade.date)
  form.model = trade.model
  form.session = trade.session
  form.entry_price = Number(trade.entry_price)
  form.stop_loss = Number(trade.stop_loss)
  form.take_profit = Number(trade.take_profit)
  form.actual_exit_price = Number(trade.actual_exit_price ?? trade.entry_price)
  form.position_size = Number(trade.lot_size)
  form.account_balance_before_trade = Number(trade.account_balance_before_trade ?? 0)
  form.followed_rules = Boolean(trade.followed_rules)
  form.emotion = (trade.emotion ?? 'neutral') as TradeEmotion
  form.notes = trade.notes || ''
  closeDateMax.value = maxDateTime(nowLocalDateTime(), form.date)
  submitAttempted.value = false
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

function nowLocalDateTime() {
  return toLocalDateTime(new Date().toISOString())
}

function maxDateTime(a: string, b: string) {
  return a > b ? a : b
}

function toNumber(value: number) {
  return Number(value)
}

function parseLocalDateTime(value: string): number | null {
  if (!value) return null
  const timestamp = new Date(value).getTime()
  if (Number.isNaN(timestamp)) return null
  return timestamp
}

const formErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}
  const symbol = form.symbol.trim().toUpperCase()
  const closeDate = parseLocalDateTime(form.date)
  const now = Date.now()

  const entry = toNumber(form.entry_price)
  const stop = toNumber(form.stop_loss)
  const take = toNumber(form.take_profit)
  const exit = toNumber(form.actual_exit_price)
  const positionSize = toNumber(form.position_size)
  const accountBefore = toNumber(form.account_balance_before_trade)

  if (!symbol) {
    errors.symbol = 'Symbol is required.'
  } else if (symbol.length > 30) {
    errors.symbol = 'Symbol must be 30 characters or fewer.'
  } else if (!/^[A-Z0-9._/-]+$/.test(symbol)) {
    errors.symbol = 'Use only letters, numbers, dot, underscore, slash, or dash.'
  }

  if (closeDate === null) {
    errors.date = 'Close date is required.'
  } else if (closeDate > now + 60_000) {
    errors.date = 'Close date cannot be in the future.'
  }

  if (!(entry > 0)) errors.entry_price = 'Entry price must be greater than 0.'
  if (!(stop > 0)) errors.stop_loss = 'Stop loss must be greater than 0.'
  if (!(take > 0)) errors.take_profit = 'Take profit must be greater than 0.'
  if (!(exit > 0)) errors.actual_exit_price = 'Actual exit price must be greater than 0.'
  if (!(positionSize >= 0.0001)) errors.position_size = 'Position size must be at least 0.0001.'
  if (!(accountBefore > 0)) errors.account_balance_before_trade = 'Account balance before trade must be greater than 0.'

  if (entry > 0 && stop > 0 && entry === stop) {
    errors.stop_loss = 'Stop loss must differ from entry price.'
  }
  if (entry > 0 && take > 0 && entry === take) {
    errors.take_profit = 'Take profit must differ from entry price.'
  }

  if (entry > 0 && stop > 0 && take > 0) {
    if (form.direction === 'buy') {
      if (stop >= entry) errors.stop_loss = 'For buy trades, stop loss must be below entry.'
      if (take <= entry) errors.take_profit = 'For buy trades, take profit must be above entry.'
    } else {
      if (stop <= entry) errors.stop_loss = 'For sell trades, stop loss must be above entry.'
      if (take >= entry) errors.take_profit = 'For sell trades, take profit must be below entry.'
    }
  }

  return errors
})

function fieldError(name: string) {
  return submitAttempted.value ? formErrors.value[name] : ''
}

function extractErrorMessage(error: unknown): string {
  const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>
  const responseMessage = axiosError.response?.data?.message
  const responseErrors = axiosError.response?.data?.errors
  const firstValidationError = responseErrors
    ? Object.values(responseErrors).flat().find((message) => Boolean(message))
    : null

  return firstValidationError || responseMessage || 'Please review input values and try again.'
}

function buildPayload(): TradePayload {
  const closeDate = parseLocalDateTime(form.date)
  if (closeDate === null) {
    throw new Error('Close date is invalid.')
  }

  return {
    symbol: form.symbol.trim().toUpperCase(),
    direction: form.direction,
    close_date: new Date(closeDate).toISOString(),
    session: form.session.trim() || undefined,
    strategy_model: form.model.trim() || undefined,
    entry_price: Number(form.entry_price),
    stop_loss: Number(form.stop_loss),
    take_profit: Number(form.take_profit),
    actual_exit_price: Number(form.actual_exit_price),
    position_size: Number(form.position_size),
    account_balance_before_trade: Number(form.account_balance_before_trade),
    followed_rules: form.followed_rules,
    emotion: form.emotion,
    notes: form.notes.trim() ? form.notes.trim() : null,
  }
}

async function submitForm() {
  submitAttempted.value = true
  const firstError = Object.values(formErrors.value)[0]
  if (firstError) {
    uiStore.toast({
      type: 'error',
      title: 'Invalid trade input',
      message: firstError,
    })
    return
  }

  try {
    const payload = buildPayload()

    if (editingTradeId.value) {
      await tradeStore.updateTrade(editingTradeId.value, payload)
      uiStore.toast({
        type: 'success',
        title: 'Trade updated',
        message: `${payload.symbol} was updated successfully.`,
      })
    } else {
      await tradeStore.addTrade(payload)
      uiStore.toast({
        type: 'success',
        title: 'Trade added',
        message: `${payload.symbol} has been saved to your journal.`,
      })
    }

    closeModal()
    resetForm()
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Failed to save trade',
      message: extractErrorMessage(error),
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
          <button class="btn btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm" @click="openAddModal">
            <Plus class="h-4 w-4" />
            Add Trade
          </button>
          <button class="btn btn-ghost px-4 py-2 text-sm" @click="applyFilters">Apply</button>
          <button v-if="hasFilters" class="btn btn-ghost px-4 py-2 text-sm" @click="clearFilters">Reset</button>
        </div>
      </div>

      <div class="form-block grid grid-premium md:grid-cols-2 xl:grid-cols-5">
        <label>
          Symbol
          <input v-model="filters.pair" type="text" placeholder="EURUSD" class="field field-sm" />
        </label>
        <label>
          Strategy Model
          <input v-model="filters.model" type="text" placeholder="Breakout" class="field field-sm" />
        </label>
        <label>
          Direction
          <select v-model="filters.direction" class="field field-sm">
            <option value="">All</option>
            <option value="buy">Buy</option>
            <option value="sell">Sell</option>
          </select>
        </label>
        <label>
          Date From
          <input v-model="filters.date_from" :max="filters.date_to || undefined" type="date" class="field field-sm" />
        </label>
        <label>
          Date To
          <input v-model="filters.date_to" :min="filters.date_from || undefined" type="date" class="field field-sm" />
        </label>
      </div>
    </GlassPanel>

    <GlassPanel>
      <div class="section-head">
        <h2 class="section-title">Trades</h2>
        <p class="section-note">
          <span v-if="loading">Loading...</span>
          <span v-else><AnimatedNumber :value="pagination.total" /> records</span>
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

      <div v-else class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Symbol</th>
              <th>Direction</th>
              <th>Date</th>
              <th>Profit/Loss</th>
              <th>R-Multiple</th>
              <th>Risk %</th>
              <th>Emotion</th>
              <th>Rules</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="trade in trades" :key="trade.id">
              <td class="font-semibold">{{ trade.pair }}</td>
              <td>
                <span :class="trade.direction === 'buy' ? 'pill pill-positive' : 'pill pill-negative'">
                  {{ trade.direction }}
                </span>
              </td>
              <td class="muted">{{ asDate(trade.date) }}</td>
              <td class="font-semibold" :class="Number(trade.profit_loss) >= 0 ? 'positive' : 'negative'">
                {{ asSignedCurrency(trade.profit_loss) }}
              </td>
              <td class="value-display">{{ Number(trade.r_multiple ?? trade.rr).toFixed(2) }}</td>
              <td class="value-display">{{ Number(trade.risk_percent ?? 0).toFixed(2) }}%</td>
              <td class="muted">{{ trade.emotion || 'neutral' }}</td>
              <td>
                <span :class="trade.followed_rules ? 'pill pill-positive' : 'pill pill-negative'">
                  {{ trade.followed_rules ? 'Followed' : 'Broke' }}
                </span>
              </td>
              <td>
                <div class="flex items-center justify-end gap-2">
                  <button class="btn btn-ghost p-2" @click="openEditModal(trade)">
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button class="btn btn-danger p-2" @click="removeTrade(trade.id)">
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

    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm">
        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="translate-y-2 scale-[0.98] opacity-0"
          enter-to-class="translate-y-0 scale-100 opacity-100"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="translate-y-0 scale-100 opacity-100"
          leave-to-class="translate-y-2 scale-[0.98] opacity-0"
        >
          <div class="panel w-full max-w-4xl p-6">
            <div class="mb-5 flex items-center justify-between">
              <h3 class="text-xl font-bold">{{ modalTitle }}</h3>
              <button class="btn btn-ghost p-2" @click="closeModal">
                <X class="h-4 w-4" />
              </button>
            </div>

            <form class="form-block space-y-4" @submit.prevent="submitForm">
              <p class="text-sm muted">
                Profit/Loss, R-Multiple, Risk %, and account balance after trade are calculated server-side.
              </p>

              <div class="grid grid-premium md:grid-cols-2">
                <label>
                  Symbol
                  <input
                    v-model="form.symbol"
                    required
                    type="text"
                    placeholder="EURUSD"
                    class="field"
                    :class="{ 'field-invalid': fieldError('symbol') }"
                  />
                  <p v-if="fieldError('symbol')" class="field-error-text">{{ fieldError('symbol') }}</p>
                </label>
                <label>
                  Direction
                  <select v-model="form.direction" class="field">
                    <option value="buy">Buy</option>
                    <option value="sell">Sell</option>
                  </select>
                </label>
                <label>
                  Close Date
                  <input
                    v-model="form.date"
                    required
                    type="datetime-local"
                    :max="closeDateMax"
                    class="field"
                    :class="{ 'field-invalid': fieldError('date') }"
                  />
                  <p v-if="fieldError('date')" class="field-error-text">{{ fieldError('date') }}</p>
                </label>
                <label>
                  Emotion
                  <select v-model="form.emotion" class="field">
                    <option v-for="emotion in emotionOptions" :key="emotion" :value="emotion">
                      {{ emotion }}
                    </option>
                  </select>
                </label>
                <label>
                  Entry Price
                  <input
                    v-model.number="form.entry_price"
                    required
                    type="number"
                    min="0.000001"
                    step="0.000001"
                    class="field"
                    :class="{ 'field-invalid': fieldError('entry_price') }"
                  />
                  <p v-if="fieldError('entry_price')" class="field-error-text">{{ fieldError('entry_price') }}</p>
                </label>
                <label>
                  Stop Loss
                  <input
                    v-model.number="form.stop_loss"
                    required
                    type="number"
                    min="0.000001"
                    step="0.000001"
                    class="field"
                    :class="{ 'field-invalid': fieldError('stop_loss') }"
                  />
                  <p v-if="fieldError('stop_loss')" class="field-error-text">{{ fieldError('stop_loss') }}</p>
                </label>
                <label>
                  Take Profit
                  <input
                    v-model.number="form.take_profit"
                    required
                    type="number"
                    min="0.000001"
                    step="0.000001"
                    class="field"
                    :class="{ 'field-invalid': fieldError('take_profit') }"
                  />
                  <p v-if="fieldError('take_profit')" class="field-error-text">{{ fieldError('take_profit') }}</p>
                </label>
                <label>
                  Actual Exit Price
                  <input
                    v-model.number="form.actual_exit_price"
                    required
                    type="number"
                    min="0.000001"
                    step="0.000001"
                    class="field"
                    :class="{ 'field-invalid': fieldError('actual_exit_price') }"
                  />
                  <p v-if="fieldError('actual_exit_price')" class="field-error-text">{{ fieldError('actual_exit_price') }}</p>
                </label>
                <label>
                  Position Size
                  <input
                    v-model.number="form.position_size"
                    required
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    class="field"
                    :class="{ 'field-invalid': fieldError('position_size') }"
                  />
                  <p v-if="fieldError('position_size')" class="field-error-text">{{ fieldError('position_size') }}</p>
                </label>
                <label>
                  Account Balance Before
                  <input
                    v-model.number="form.account_balance_before_trade"
                    required
                    type="number"
                    step="0.01"
                    min="0.01"
                    class="field"
                    :class="{ 'field-invalid': fieldError('account_balance_before_trade') }"
                  />
                  <p v-if="fieldError('account_balance_before_trade')" class="field-error-text">
                    {{ fieldError('account_balance_before_trade') }}
                  </p>
                </label>
                <label>
                  Session (Optional)
                  <input v-model="form.session" type="text" placeholder="London" class="field" />
                </label>
                <label>
                  Strategy Model (Optional)
                  <input v-model="form.model" type="text" placeholder="Liquidity Sweep" class="field" />
                </label>
              </div>

              <label class="inline-flex items-center gap-2 text-sm font-semibold">
                <input v-model="form.followed_rules" type="checkbox" class="h-4 w-4" />
                Followed Rules
              </label>

              <label>
                Notes
                <textarea v-model="form.notes" rows="3" class="field" />
              </label>

              <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="closeModal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 py-2 text-sm" :disabled="saving">
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
