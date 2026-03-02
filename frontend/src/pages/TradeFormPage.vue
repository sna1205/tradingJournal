<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { ArrowLeft, Check, DollarSign, TrendingUp } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseSelect from '@/components/form/BaseSelect.vue'
import BaseDateTime from '@/components/form/BaseDateTime.vue'
import InstrumentPairSelect from '@/components/form/InstrumentPairSelect.vue'
import TradeImageUploader from '@/components/trades/TradeImageUploader.vue'
import TradeRulesPanel from '@/components/rules/TradeRulesPanel.vue'
import api from '@/services/api'
import { createTradeEditLock, type TradeEditLockHandle, type TradeEditLockState } from '@/services/editLockService'
import {
  FxRateResolutionError,
  FxToUsdService,
  resolveQuoteToUsdFromTable,
  type FxQuoteToUsdResolution,
} from '@/services/fxToUsdService'
import { createDebouncedLatestRunner, stableStringify } from '@/services/precheckScheduler'
import { livePriceFeedService } from '@/services/priceFeedService'
import { useAccountStore } from '@/stores/accountStore'
import {
  useTradeStore,
  type TradePayload,
  type TradePrecheckResult,
} from '@/stores/tradeStore'
import { useTradeRulesStore } from '@/stores/tradeRulesStore'
import { useSyncStatusStore } from '@/stores/syncStatusStore'
import { useUiStore } from '@/stores/uiStore'
import type { ImageContextTag, Instrument, Paginated, SessionEnum, Trade, TradeEmotion, TradeImage, TradeLeg, TradePsychology } from '@/types/trade'
import { normalizeApiError, type NormalizedError } from '@/utils/apiError'
import { asCurrency, asSignedCurrency } from '@/utils/format'

const router = useRouter()
const route = useRoute()
const tradeStore = useTradeStore()
const tradeChecklistStore = useTradeRulesStore()
const accountStore = useAccountStore()
const syncStatusStore = useSyncStatusStore()
const uiStore = useUiStore()
const { accounts } = storeToRefs(accountStore)
const { instruments, strategyModels, setups, killzones, tradeTags, sessionOptions, fxRates } = storeToRefs(tradeStore)
const { isFallbackMode } = storeToRefs(syncStatusStore)
const {
  checklist: activeChecklist,
  items: checklistItems,
  requiredItems: checklistRequiredItems,
  optionalItems: checklistOptionalItems,
  archivedResponses: checklistArchivedResponses,
  readiness: checklistReadiness,
  serverReadiness: checklistServerReadiness,
  executionSnapshot: checklistExecutionSnapshot,
  loading: checklistLoading,
  saving: checklistSaving,
  resolverContext: checklistResolverContext,
  resolverContextCurrent: checklistResolverContextCurrent,
  submitAttempted: checklistSubmitAttempted,
  isStrict: checklistStrictMode,
  checklistIncomplete,
  strictSubmitBlocked: checklistStrictSubmitBlocked,
  serverReadinessMismatch: checklistServerReadinessMismatch,
  serverReadinessReasons: checklistServerReadinessReasons,
  hasChecklist,
} = storeToRefs(tradeChecklistStore)

const loadingTrade = ref(false)
const submitAttempted = ref(false)
const serverFieldErrors = ref<Record<string, string[]>>({})
const loadedTradeUpdatedAt = ref<string | null>(null)
const staleWriteCheckInProgress = ref(false)
const staleWriteConfirmedFingerprint = ref<string | null>(null)
const emotionOptions: TradeEmotion[] = ['neutral', 'calm', 'confident', 'fearful', 'greedy', 'hesitant', 'revenge']
const directionOptions = [
  { label: 'Buy', value: 'buy' },
  { label: 'Sell', value: 'sell' },
]
const emotionSelectOptions = emotionOptions.map((emotion) => ({
  label: emotion.charAt(0).toUpperCase() + emotion.slice(1),
  value: emotion,
}))
const accountSelectOptions = computed(() =>
  accounts.value.map((account) => ({
    label: account.name,
    value: String(account.id),
    subtitle: `${account.currency} ${asCurrency(Number(account.current_balance))}`,
    badge: account.account_type === 'funded'
      ? 'Phase 1'
      : (account.account_type === 'personal' ? 'live' : 'demo'),
    keywords: [account.broker, account.account_type, account.currency],
  }))
)
const strategyModelOptions = computed(() =>
  strategyModels.value.map((item) => ({
    label: item.name,
    value: String(item.id),
    subtitle: item.description ?? '',
    keywords: [item.slug],
  }))
)
const setupOptions = computed(() =>
  setups.value.map((item) => ({
    label: item.name,
    value: String(item.id),
    subtitle: item.description ?? '',
    keywords: [item.slug],
  }))
)
const killzoneOptions = computed(() =>
  killzones.value.map((item) => ({
    label: item.name,
    value: String(item.id),
    subtitle: item.session_enum,
    keywords: [item.slug, item.session_enum],
  }))
)
const sessionEnumOptions = computed(() =>
  sessionOptions.value.map((item) => ({
    label: item.label,
    value: item.value,
  }))
)
const selectedTagIds = computed(() => new Set(form.tag_ids))
const filteredTagOptions = computed(() => {
  const term = form.tag_search.trim().toLowerCase()
  return tradeTags.value.filter((tag) => {
    if (selectedTagIds.value.has(tag.id)) return false
    if (!term) return true
    return tag.name.toLowerCase().includes(term) || tag.slug.toLowerCase().includes(term)
  })
})

const form = reactive({
  account_id: '',
  instrument_id: '',
  strategy_model_id: '',
  setup_id: '',
  killzone_id: '',
  session_enum: '' as '' | SessionEnum,
  symbol: '',
  direction: 'buy' as 'buy' | 'sell',
  date: '',
  entry_price: 0,
  stop_loss: 0,
  take_profit: 0,
  position_size: 0.01,
  commission: 0,
  swap: 0,
  spread_cost: 0,
  slippage_cost: 0,
  risk_override_reason: '',
  followed_rules: true,
  emotion: 'neutral' as TradeEmotion,
  notes: '',
  tag_ids: [] as number[],
  tag_search: '',
})

const psychology = reactive<{
  pre_emotion: string
  post_emotion: string
  confidence_score: number | null
  stress_score: number | null
  sleep_hours: number | null
  impulse_flag: boolean
  fomo_flag: boolean
  revenge_flag: boolean
  notes: string
}>({
  pre_emotion: '',
  post_emotion: '',
  confidence_score: null,
  stress_score: null,
  sleep_hours: null,
  impulse_flag: false,
  fomo_flag: false,
  revenge_flag: false,
  notes: '',
})

interface ExitLegRow {
  id: string
  price: number
  quantity_lots: number
  executed_at: string
  fees: number
  notes: string
}

interface PrimaryExitRow {
  price: number
  quantity_lots: number
  executed_at: string
  fees: number
  notes: string
}

const primaryExit = reactive<PrimaryExitRow>({
  price: 0,
  quantity_lots: 0,
  executed_at: '',
  fees: 0,
  notes: '',
})

const exitLegs = ref<ExitLegRow[]>([])

interface PendingTradeImage {
  id: string
  file: File
  preview_url: string
  context_tag: ImageContextTag
  timeframe: string
  annotation_notes: string
}

const MAX_IMAGE_COUNT = 5
const MAX_IMAGE_SIZE_BYTES = 5 * 1024 * 1024
const MAX_TOTAL_IMAGE_BYTES = 20 * 1024 * 1024
const allowedImageTypes = new Set([
  'image/jpeg',
  'image/jpg',
  'image/png',
  'image/webp',
  'image/bmp',
])

const existingImages = ref<TradeImage[]>([])
const pendingImages = ref<PendingTradeImage[]>([])
const imageUploadError = ref('')
const uploadingImages = ref(false)
const deletingImageIds = ref<number[]>([])
const uploadProgressByPendingId = ref<Record<string, number>>({})
const editLockState = ref<TradeEditLockState | null>(null)
const staleWriteWarning = ref<{
  fingerprint: string
  loaded_updated_at: string
  latest_updated_at: string
  diffs: Array<{ field: string; local: string; latest: string }>
} | null>(null)
let editLockHandle: TradeEditLockHandle | null = null
let releaseEditLockSubscription: (() => void) | null = null

const tradeId = computed(() => {
  const value = Number(route.params.id)
  return Number.isInteger(value) && value > 0 ? value : null
})
const isEditMode = computed(() => tradeId.value !== null)
const loadingSmartDefaults = ref(false)
const tradeCompleted = ref(true)
const pageTitle = computed(() => (isEditMode.value ? 'Edit Execute' : 'New Execute'))
const tradeFormId = 'trade-execution-form'
const closeDateMax = computed(() => maxDateTime(nowLocalDateTime(), form.date || ''))
const totalImageCount = computed(() => existingImages.value.length + pendingImages.value.length)
const totalImageSize = computed(() => {
  const existingTotal = existingImages.value.reduce((sum, image) => sum + Number(image.file_size || 0), 0)
  const pendingTotal = pendingImages.value.reduce((sum, image) => sum + image.file.size, 0)
  return existingTotal + pendingTotal
})
const selectedInstrument = computed<Instrument | null>(() => {
  const id = Number(form.instrument_id)
  if (!Number.isInteger(id) || id <= 0) return null
  return instruments.value.find((instrument) => instrument.id === id) ?? null
})
const fxResolver = new FxToUsdService(livePriceFeedService)
const quoteTickVersion = ref(0)
let stopTrackingFxSymbols: (() => void) | null = null
let unsubscribeFxListeners: Array<() => void> = []
const liveFxConversion = ref<FxQuoteToUsdResolution | null>(null)
const liveFxLoading = ref(false)
const liveFxAttemptedSymbols = ref<string[]>([])
const liveFxConversionErrorMessage = ref('')
let liveFxResolveRequestId = 0
const isFxPending = computed(() => {
  const instrument = selectedInstrument.value
  return Boolean(
    instrument
    && instrument.quote_currency.toUpperCase() !== 'USD'
    && liveFxLoading.value
  )
})
const liveFxConversionError = computed(() => {
  if (isFxPending.value) return ''
  return liveFxConversionErrorMessage.value
})
const selectedStrategyModel = computed(() => {
  const id = Number(form.strategy_model_id)
  if (!Number.isInteger(id) || id <= 0) return null
  return strategyModels.value.find((item) => item.id === id) ?? null
})
const selectedSetup = computed(() => {
  const id = Number(form.setup_id)
  if (!Number.isInteger(id) || id <= 0) return null
  return setups.value.find((item) => item.id === id) ?? null
})
const instrumentSymbol = computed(() => selectedInstrument.value?.symbol ?? form.symbol.trim().toUpperCase())
const precheckLoading = ref(false)
const precheckError = ref('')
const precheckResult = ref<TradePrecheckResult | null>(null)
const precheckActiveRequestId = ref<number | null>(null)
const failedRequiredRuleIds = ref<number[]>([])
const localRiskOverrideAccepted = ref(false)
const precheckUpdating = computed(() => precheckLoading.value && precheckResult.value !== null)
const isRiskEngineUnavailable = computed(() => Boolean(precheckResult.value?.risk_engine_unavailable))
const canUseLocalRiskOverride = computed(() =>
  isRiskEngineUnavailable.value
  && Boolean(precheckResult.value?.local_only_override_allowed)
)
const hasAcceptedLocalRiskOverride = computed(() =>
  canUseLocalRiskOverride.value
  && localRiskOverrideAccepted.value
)
const selectedChecklistAccountId = computed(() => {
  const id = Number(form.account_id)
  return Number.isInteger(id) && id > 0 ? id : null
})
const selectedChecklistStrategyModelId = computed(() => {
  const id = Number(form.strategy_model_id)
  return Number.isInteger(id) && id > 0 ? id : null
})
const selectedChecklistTradeId = computed(() =>
  isEditMode.value && tradeId.value !== null ? tradeId.value : null
)
const isChecklistContextMismatch = computed(() => {
  const context = checklistResolverContext.value
  if (!context) return true
  if (!checklistResolverContextCurrent.value) return true

  return context.requested_account_id !== selectedChecklistAccountId.value
    || context.requested_strategy_model_id !== selectedChecklistStrategyModelId.value
    || context.trade_id !== selectedChecklistTradeId.value
})
const isSaveBlocked = computed(() =>
  isFxPending.value
  || Boolean(precheckError.value)
  || ((precheckResult.value !== null) && !precheckResult.value.allowed && !hasAcceptedLocalRiskOverride.value)
  || Boolean(liveFxConversionError.value)
)
const isChecklistStrictBlocked = computed(() =>
  checklistStrictSubmitBlocked.value
)
const checklistGateIncomplete = computed(() =>
  hasChecklist.value && (checklistIncomplete.value || failedRequiredRuleIds.value.length > 0)
)
const showSoftChecklistNotice = computed(() =>
  hasChecklist.value
  && !checklistStrictMode.value
  && checklistGateIncomplete.value
)
const isLockedByOtherTab = computed(() =>
  isEditMode.value
  && editLockState.value?.holder === 'other'
)
const isSubmittingDisabled = computed(() =>
  tradeStore.saving
  || uploadingImages.value
  || precheckLoading.value
  || staleWriteCheckInProgress.value
  || checklistLoading.value
  || isSaveBlocked.value
  || (isEditMode.value && isLockedByOtherTab.value)
  || isChecklistContextMismatch.value
  || isChecklistStrictBlocked.value
)
const lockHolderLabel = computed(() => {
  const label = editLockState.value?.record?.holder_label?.trim()
  return label || 'another tab'
})
const riskStatusLabel = computed(() => {
  if (precheckLoading.value && precheckActiveRequestId.value !== null) return 'Checking...'
  if (isFxPending.value) return 'Fetching FX'
  if (precheckError.value) return 'Blocked'
  if (liveFxConversionError.value) return 'Blocked'
  if (precheckResult.value?.allowed) return 'Allowed'
  if (precheckResult.value) return 'Blocked'
  return 'Awaiting check'
})
const riskStatusClass = computed(() => {
  if (precheckLoading.value) return ''
  if (isFxPending.value) return ''
  if (precheckError.value) return 'is-blocked'
  if (liveFxConversionError.value) return 'is-blocked'
  if (precheckResult.value?.allowed) return 'is-allowed'
  if (precheckResult.value) return 'is-blocked'
  return ''
})
const blockedSummary = computed(() => {
  if (!submitAttempted.value || (!isSaveBlocked.value && !isChecklistContextMismatch.value && !isChecklistStrictBlocked.value)) return ''
  if (isFxPending.value) return 'Fetching FX quote...'
  if (isChecklistContextMismatch.value) return 'Rule context is refreshing for the selected account and strategy.'
  if (isChecklistStrictBlocked.value) {
    const firstReason = checklistServerReadinessReasons.value[0]?.reason?.trim()
    return firstReason || 'Strict mode is blocked by server checklist readiness.'
  }
  if (precheckError.value) return precheckError.value
  if (liveFxConversionError.value) return liveFxConversionError.value
  if (isRiskEngineUnavailable.value && !hasAcceptedLocalRiskOverride.value) {
    return 'Risk engine is unavailable. Confirm local-only override or reconnect before saving.'
  }
  return 'Blocked by account risk policy. Resolve violations or provide override reason when allowed.'
})

async function refreshLiveFxConversion() {
  const previousFxMaterialSignature = getFxMaterialSignature(liveFxConversion.value)
  const instrument = selectedInstrument.value
  if (!instrument) {
    liveFxConversion.value = null
    liveFxConversionErrorMessage.value = ''
    liveFxAttemptedSymbols.value = []
    liveFxLoading.value = false
    return
  }

  const quoteCurrency = instrument.quote_currency.toUpperCase()
  if (quoteCurrency === 'USD') {
    liveFxConversion.value = {
      rate: 1,
      symbolUsed: null,
      method: 'identity',
      mode: 'mid',
      ts: null,
      attemptedSymbols: [],
    }
    liveFxConversionErrorMessage.value = ''
    liveFxAttemptedSymbols.value = []
    liveFxLoading.value = false
    return
  }

  const requestId = ++liveFxResolveRequestId
  liveFxLoading.value = true
  liveFxConversionErrorMessage.value = ''
  liveFxAttemptedSymbols.value = []

  try {
    const rate = await fxResolver.getRate(quoteCurrency, 'mid')
    if (requestId !== liveFxResolveRequestId) return
    liveFxConversion.value = rate
    if (getFxMaterialSignature(rate) !== previousFxMaterialSignature) {
      schedulePrecheck()
    }
    liveFxConversionErrorMessage.value = ''
    liveFxAttemptedSymbols.value = []
  } catch (error) {
    if (requestId !== liveFxResolveRequestId) return
    const fallback = resolveQuoteToUsdFromTable(quoteCurrency, fxRates.value)
    if (fallback) {
      liveFxConversion.value = fallback
      if (getFxMaterialSignature(fallback) !== previousFxMaterialSignature) {
        schedulePrecheck()
      }
      liveFxConversionErrorMessage.value = ''
      liveFxAttemptedSymbols.value = []
      return
    }

    liveFxConversion.value = null
    if (error instanceof FxRateResolutionError) {
      liveFxAttemptedSymbols.value = error.attemptedSymbols
      liveFxConversionErrorMessage.value = error.message
    } else {
      liveFxAttemptedSymbols.value = []
      liveFxConversionErrorMessage.value = `Missing live FX quote to convert ${quoteCurrency}->USD`
    }
  } finally {
    if (requestId === liveFxResolveRequestId) {
      liveFxLoading.value = false
    }
  }
}

const validExitLegs = computed(() => {
  const rows: Array<{ price: number; quantity_lots: number; fees: number }> = []
  if (toNumber(primaryExit.price) > 0 && toNumber(primaryExit.quantity_lots) > 0) {
    rows.push({
      price: toNumber(primaryExit.price),
      quantity_lots: toNumber(primaryExit.quantity_lots),
      fees: toNumber(primaryExit.fees),
    })
  }
  for (const leg of exitLegs.value) {
    if (toNumber(leg.price) > 0 && toNumber(leg.quantity_lots) > 0) {
      rows.push({
        price: toNumber(leg.price),
        quantity_lots: toNumber(leg.quantity_lots),
        fees: toNumber(leg.fees),
      })
    }
  }
  return rows
})

const exitLegSummary = computed(() => {
  const rows = validExitLegs.value
  const quantity = rows.reduce((sum, leg) => sum + toNumber(leg.quantity_lots), 0)
  const weighted = rows.reduce((sum, leg) => sum + (toNumber(leg.price) * toNumber(leg.quantity_lots)), 0)
  const weightedPrice = quantity > 0 ? (weighted / quantity) : toNumber(form.entry_price)
  const fees = rows.reduce((sum, leg) => sum + toNumber(leg.fees), 0)

  return {
    quantity,
    weightedPrice,
    fees,
  }
})

function estimateLegPnl(leg: Pick<ExitLegRow, 'price' | 'quantity_lots' | 'fees'>) {
  const instrument = selectedInstrument.value
  const entry = toNumber(form.entry_price)
  const exit = toNumber(leg.price)
  const quantity = toNumber(leg.quantity_lots)
  if (!(entry > 0) || !(exit > 0) || !(quantity > 0)) return 0

  const direction = form.direction === 'buy' ? 1 : -1
  const move = (exit - entry) * direction

  if (!instrument) {
    return move * quantity - toNumber(leg.fees)
  }

  const tickSize = toNumber(instrument.tick_size)
  const tickValue = toNumber(instrument.tick_value)
  const gross = tickSize > 0 && tickValue > 0
    ? (move / tickSize) * tickValue * quantity
    : move * quantity

  return gross - toNumber(leg.fees)
}

function estimateLegPnlLabel(leg: Pick<ExitLegRow, 'price' | 'quantity_lots' | 'fees'>) {
  return asSignedCurrency(estimateLegPnl(leg))
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

function toNumber(value: unknown) {
  return Number(value || 0)
}

function makeExitLeg(partial?: Partial<ExitLegRow>): ExitLegRow {
  return {
    id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
    price: partial?.price ?? toNumber(form.entry_price),
    quantity_lots: partial?.quantity_lots ?? toNumber(form.position_size),
    executed_at: partial?.executed_at ?? (form.date || nowLocalDateTime()),
    fees: partial?.fees ?? 0,
    notes: partial?.notes ?? '',
  }
}

function resetPrimaryExit(partial?: Partial<PrimaryExitRow>) {
  primaryExit.price = partial?.price ?? toNumber(form.take_profit || form.entry_price)
  primaryExit.quantity_lots = partial?.quantity_lots ?? toNumber(form.position_size)
  primaryExit.executed_at = partial?.executed_at ?? (form.date || nowLocalDateTime())
  primaryExit.fees = partial?.fees ?? 0
  primaryExit.notes = partial?.notes ?? ''
}

function addExitLeg() {
  const alreadyAllocated = toNumber(primaryExit.quantity_lots) + exitLegSummary.value.quantity
  const defaultQty = Math.max(0.0001, toNumber(form.position_size) - alreadyAllocated)
  exitLegs.value = [...exitLegs.value, makeExitLeg({ quantity_lots: defaultQty > 0 ? defaultQty : 0.0001 })]
}

function removeExitLeg(id: string) {
  exitLegs.value = exitLegs.value.filter((leg) => leg.id !== id)
}

function ensureDefaultExitLeg() {
  resetPrimaryExit()
  exitLegs.value = []
}

function addTag(tagId: number) {
  if (selectedTagIds.value.has(tagId)) return
  form.tag_ids = [...form.tag_ids, tagId]
  form.tag_search = ''
}

function removeTag(tagId: number) {
  form.tag_ids = form.tag_ids.filter((value) => value !== tagId)
}

const selectedTags = computed(() =>
  tradeTags.value.filter((tag) => selectedTagIds.value.has(tag.id))
)

function setPsychologyFromPayload(payload?: TradePsychology | null) {
  psychology.pre_emotion = payload?.pre_emotion ?? ''
  psychology.post_emotion = payload?.post_emotion ?? ''
  psychology.confidence_score = payload?.confidence_score === null || payload?.confidence_score === undefined
    ? null
    : Number(payload.confidence_score)
  psychology.stress_score = payload?.stress_score === null || payload?.stress_score === undefined
    ? null
    : Number(payload.stress_score)
  psychology.sleep_hours = payload?.sleep_hours === null || payload?.sleep_hours === undefined
    ? null
    : Number(payload.sleep_hours)
  psychology.impulse_flag = Boolean(payload?.impulse_flag)
  psychology.fomo_flag = Boolean(payload?.fomo_flag)
  psychology.revenge_flag = Boolean(payload?.revenge_flag)
  psychology.notes = payload?.notes ?? ''
}

function parseLocalDateTime(value: string): number | null {
  if (!value) return null
  const timestamp = new Date(value).getTime()
  if (Number.isNaN(timestamp)) return null
  return timestamp
}

function setFormFromTrade(trade: Trade, legs: TradeLeg[] = [], tradePsychology?: TradePsychology | null) {
  form.account_id = String(trade.account_id || '')
  form.instrument_id = trade.instrument_id ? String(trade.instrument_id) : ''
  form.strategy_model_id = trade.strategy_model_id ? String(trade.strategy_model_id) : ''
  form.setup_id = trade.setup_id ? String(trade.setup_id) : ''
  form.killzone_id = trade.killzone_id ? String(trade.killzone_id) : ''
  form.session_enum = (trade.session_enum ?? '') as '' | SessionEnum
  form.symbol = trade.pair
  form.direction = trade.direction
  form.date = toLocalDateTime(trade.date)
  form.entry_price = Number(trade.entry_price)
  form.stop_loss = Number(trade.stop_loss)
  form.take_profit = Number(trade.take_profit)
  form.position_size = Number(trade.lot_size)
  form.commission = Number(trade.commission ?? 0)
  form.swap = Number(trade.swap ?? 0)
  form.spread_cost = Number(trade.spread_cost ?? 0)
  form.slippage_cost = Number(trade.slippage_cost ?? 0)
  form.risk_override_reason = trade.risk_override_reason ?? ''
  form.followed_rules = Boolean(trade.followed_rules)
  form.emotion = (trade.emotion ?? 'neutral') as TradeEmotion
  form.notes = trade.notes || ''
  form.tag_ids = trade.tags?.map((tag) => Number(tag.id)) ?? (trade.tag_ids ?? [])
  form.tag_search = ''

  const sourceLegs = (legs.length > 0 ? legs : (trade.legs ?? [])).map((leg) => ({
    ...leg,
    price: Number(leg.price),
    quantity_lots: Number(leg.quantity_lots),
    fees: Number(leg.fees ?? 0),
  }))

  const exitRows = sourceLegs
    .filter((leg) => leg.leg_type === 'exit')
    .map((leg) => makeExitLeg({
      price: Number(leg.price),
      quantity_lots: Number(leg.quantity_lots),
      executed_at: leg.executed_at ? toLocalDateTime(leg.executed_at) : form.date,
      fees: Number(leg.fees ?? 0),
      notes: leg.notes ?? '',
    }))

  if (exitRows.length > 0) {
    const [firstExit, ...partials] = exitRows
    resetPrimaryExit({
      price: toNumber(firstExit?.price),
      quantity_lots: toNumber(firstExit?.quantity_lots),
      executed_at: firstExit?.executed_at || form.date,
      fees: toNumber(firstExit?.fees),
      notes: firstExit?.notes ?? '',
    })
    exitLegs.value = partials
  } else {
    resetPrimaryExit({
      price: Number(trade.actual_exit_price ?? trade.entry_price),
      quantity_lots: Number(trade.lot_size),
      executed_at: form.date,
    })
    exitLegs.value = []
  }
  setPsychologyFromPayload(tradePsychology ?? trade.psychology ?? null)

}

function roundLot(value: number) {
  return Math.max(0.0001, Math.round(value * 10_000) / 10_000)
}

function formatPriceField(field: 'entry_price' | 'stop_loss' | 'take_profit') {
  const value = toNumber(form[field])
  if (!(value > 0)) return
  form[field] = Number(value.toFixed(6))
}

function formatLotField(field: 'position_size') {
  const value = roundLot(toNumber(form[field]))
  form[field] = value
}

function onQuickTicketEnter(event: KeyboardEvent) {
  const target = event.target as HTMLElement | null
  const currentTarget = event.currentTarget as HTMLElement | null
  if (!target || !currentTarget) return
  if (!(target instanceof HTMLInputElement)) return

  const controls = Array.from(
    currentTarget.querySelectorAll<HTMLInputElement>('input:not([disabled]):not([type="hidden"])')
  )
  const index = controls.findIndex((control) => control === target)
  if (index < 0) return

  event.preventDefault()

  const next = controls[index + 1]
  if (!next) {
    target.blur()
    return
  }

  next.focus()
  next.select()
}

async function applySmartDefaultsFromLastTrade() {
  if (isEditMode.value || loadingSmartDefaults.value) return
  loadingSmartDefaults.value = true

  try {
    const { data } = await api.get<Paginated<Trade>>('/trades', {
      params: {
        page: 1,
        per_page: 10,
      },
    })

    const latestTrade = (data.data ?? [])
      .slice()
      .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime())[0]

    if (!latestTrade) return

    if (!form.account_id && latestTrade.account_id) {
      form.account_id = String(latestTrade.account_id)
    }
    if (!form.strategy_model_id && latestTrade.strategy_model_id) {
      form.strategy_model_id = String(latestTrade.strategy_model_id)
    }
    if (!form.setup_id && latestTrade.setup_id) {
      form.setup_id = String(latestTrade.setup_id)
    }
    if (!form.killzone_id && latestTrade.killzone_id) {
      form.killzone_id = String(latestTrade.killzone_id)
    }
    if (!form.session_enum && latestTrade.session_enum) {
      form.session_enum = latestTrade.session_enum
    }

    if (toNumber(form.commission) === 0) {
      form.commission = Number(latestTrade.commission ?? 0)
    }
    if (toNumber(form.swap) === 0) {
      form.swap = Number(latestTrade.swap ?? 0)
    }
    if (toNumber(form.spread_cost) === 0) {
      form.spread_cost = Number(latestTrade.spread_cost ?? 0)
    }
    if (toNumber(form.slippage_cost) === 0) {
      form.slippage_cost = Number(latestTrade.slippage_cost ?? 0)
    }

    if (!form.instrument_id && latestTrade.instrument_id) {
      const hasInstrument = instruments.value.some((instrument) => instrument.id === Number(latestTrade.instrument_id))
      if (hasInstrument) {
        form.instrument_id = String(latestTrade.instrument_id)
      } else if (latestTrade.pair) {
        selectInstrumentBySymbol(latestTrade.pair)
      }
    } else if (!form.instrument_id && latestTrade.pair) {
      selectInstrumentBySymbol(latestTrade.pair)
    }
  } catch {
    // Keep deterministic fallback defaults when no previous trade can be fetched.
  } finally {
    loadingSmartDefaults.value = false
  }
}

function applyQuickDefaultsFromQuery() {
  if (isEditMode.value) return
  if (`${route.query.quick ?? ''}` !== '1') return

  const symbol = `${route.query.symbol ?? ''}`.trim().toUpperCase()
  const direction = `${route.query.direction ?? ''}`.trim().toLowerCase()

  if (symbol) {
    form.symbol = symbol
  }
  if (direction === 'buy' || direction === 'sell') {
    form.direction = direction
  }

  if (symbol) {
    selectInstrumentBySymbol(symbol)
  }
}

function selectInstrumentBySymbol(symbol: string) {
  const normalized = symbol.trim().toUpperCase()
  if (!normalized) return

  const match = instruments.value.find((instrument) => instrument.symbol.toUpperCase() === normalized)
  if (!match) return

  form.instrument_id = String(match.id)
  form.symbol = match.symbol
}

function findInstrumentById(value: string): Instrument | null {
  const id = Number(value)
  if (!Number.isInteger(id) || id <= 0) return null
  return instruments.value.find((instrument) => instrument.id === id) ?? null
}

const formErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}
  const closeDate = parseLocalDateTime(form.date)
  const now = Date.now()

  const entry = toNumber(form.entry_price)
  const stop = toNumber(form.stop_loss)
  const take = toNumber(form.take_profit)
  const positionSize = toNumber(form.position_size)
  const commission = toNumber(form.commission)
  const spreadCost = toNumber(form.spread_cost)
  const slippageCost = toNumber(form.slippage_cost)

  if (!form.account_id) {
    errors.account_id = 'Account is required.'
  }

  if (!form.instrument_id) {
    errors.instrument_id = 'Instrument is required.'
  } else if (!findInstrumentById(form.instrument_id)) {
    errors.instrument_id = 'Please select a valid instrument from the list.'
  }
  if (!form.strategy_model_id) {
    errors.strategy_model_id = 'Strategy model is required.'
  }
  if (!form.setup_id) {
    errors.setup_id = 'Setup is required.'
  }
  if (!form.killzone_id) {
    errors.killzone_id = 'Killzone is required.'
  }
  if (!form.session_enum) {
    errors.session_enum = 'Session is required.'
  }

  if (closeDate === null) {
    errors.date = 'Close date is required.'
  } else if (closeDate > now + 60_000) {
    errors.date = 'Close date cannot be in the future.'
  }

  if (!(entry > 0)) errors.entry_price = 'Entry price must be greater than 0.'
  if (!(stop > 0)) errors.stop_loss = 'Stop loss must be greater than 0.'
  if (!(take > 0)) errors.take_profit = 'Take profit must be greater than 0.'
  if (!(positionSize >= 0.0001)) errors.position_size = 'Position size must be at least 0.0001.'
  if (commission < 0) errors.commission = 'Commission cannot be negative.'
  if (spreadCost < 0) errors.spread_cost = 'Spread cost cannot be negative.'
  if (slippageCost < 0) errors.slippage_cost = 'Slippage cost cannot be negative.'
  if (!tradeCompleted.value) errors.trade_completed = 'Mark trade as completed to log this execution.'

  if (tradeCompleted.value) {
    const primaryPrice = toNumber(primaryExit.price)
    const primaryQty = toNumber(primaryExit.quantity_lots)
    const primaryDate = parseLocalDateTime(primaryExit.executed_at)

    if (!(primaryPrice > 0)) {
      errors.exit_price = 'Exit price must be greater than 0.'
    }
    if (!(primaryQty > 0)) {
      errors.exit_quantity = 'Exit size must be greater than 0.'
    }
    if (primaryDate === null) {
      errors.exit_time = 'Exit date/time is required.'
    } else if (primaryDate > now + 60_000) {
      errors.exit_time = 'Exit date/time cannot be in the future.'
    }

    let exitQuantity = primaryQty
    for (let index = 0; index < exitLegs.value.length; index += 1) {
      const leg = exitLegs.value[index]!
      const price = toNumber(leg.price)
      const quantity = toNumber(leg.quantity_lots)
      const legDate = parseLocalDateTime(leg.executed_at)

      if (!(price > 0)) {
        errors[`legs.${index}.price`] = `Partial exit ${index + 1} price must be greater than 0.`
      }
      if (!(quantity > 0)) {
        errors[`legs.${index}.quantity_lots`] = `Partial exit ${index + 1} size must be greater than 0.`
      }
      if (legDate === null) {
        errors[`legs.${index}.executed_at`] = `Partial exit ${index + 1} time is required.`
      } else if (legDate > now + 60_000) {
        errors[`legs.${index}.executed_at`] = `Partial exit ${index + 1} time cannot be in the future.`
      }

      exitQuantity += quantity
    }

    if (positionSize > 0 && exitQuantity > (positionSize + 0.0001)) {
      errors.legs = 'Total exit size cannot exceed position size.'
    }
  }

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
  if (!submitAttempted.value) return ''
  const localMessage = formErrors.value[name]
  if (localMessage) return localMessage
  return serverFieldErrors.value[name]?.[0] ?? ''
}

function extractErrorMessage(error: unknown): string {
  return normalizeApiError(error).message
}

function extractFailingRuleIds(error: unknown): number[] {
  return normalizeApiError(error).failingRuleIds
}

function isTradeRevisionConflict(error: unknown): boolean {
  return normalizeApiError(error).isConflict
}

function mapServerFieldName(field: string): string {
  const normalized = field.trim()
  if (normalized === 'pair') return 'instrument_id'
  if (normalized === 'lot_size') return 'position_size'
  if (normalized === 'actual_exit_price') return 'exit_price'
  return normalized
}

function applyServerFieldErrors(normalized: NormalizedError): void {
  if (!normalized.isValidation) {
    serverFieldErrors.value = {}
    return
  }

  const next: Record<string, string[]> = {}
  for (const [field, messages] of Object.entries(normalized.fieldErrors)) {
    const mappedField = mapServerFieldName(field)
    const cleanedMessages = messages
      .map((message) => `${message ?? ''}`.trim())
      .filter((message) => message.length > 0)
    if (cleanedMessages.length > 0) {
      next[mappedField] = cleanedMessages
    }
  }

  serverFieldErrors.value = next
}

function buildPayload(): TradePayload {
  if (!tradeCompleted.value) {
    throw new Error('Trade must be marked complete before saving.')
  }

  const closeDate = parseLocalDateTime(form.date)
  if (closeDate === null) {
    throw new Error('Close date is invalid.')
  }
  let instrument = findInstrumentById(form.instrument_id)
  if (!instrument && form.symbol.trim()) {
    const symbolMatch = instruments.value.find((item) => item.symbol.toUpperCase() === form.symbol.trim().toUpperCase())
    if (symbolMatch) {
      form.instrument_id = String(symbolMatch.id)
      form.symbol = symbolMatch.symbol
      instrument = symbolMatch
    }
  }
  if (!instrument) {
    throw new Error('Please select a valid instrument from the list.')
  }
  if (instrument.quote_currency.toUpperCase() !== 'USD') {
    if (liveFxLoading.value) {
      throw new Error('Fetching FX quote...')
    }
    if (liveFxConversion.value === null) {
      throw new Error(liveFxConversionError.value || `Missing live FX quote to convert ${instrument.quote_currency.toUpperCase()}->USD`)
    }
  }
  const strategyModelId = Number(form.strategy_model_id)
  const setupId = Number(form.setup_id)
  const killzoneId = Number(form.killzone_id)
  if (!Number.isInteger(strategyModelId) || strategyModelId <= 0) {
    throw new Error('Strategy model is required.')
  }
  if (!Number.isInteger(setupId) || setupId <= 0) {
    throw new Error('Setup is required.')
  }
  if (!Number.isInteger(killzoneId) || killzoneId <= 0) {
    throw new Error('Killzone is required.')
  }
  if (!form.session_enum) {
    throw new Error('Session is required.')
  }

  const selectedModel = selectedStrategyModel.value
  const selectedSetupValue = selectedSetup.value
  const sessionLabel = sessionEnumOptions.value.find((option) => option.value === form.session_enum)?.label ?? form.session_enum
  const strategyModelLabel = selectedModel?.name ?? 'General'
  const setupLabel = selectedSetupValue?.name ?? 'Setup'

  const entryExecutedAt = new Date(closeDate).toISOString()
  const legs = [
    {
      leg_type: 'entry' as const,
      price: Number(form.entry_price),
      quantity_lots: Number(form.position_size),
      executed_at: entryExecutedAt,
      fees: 0,
      notes: null,
    },
    {
      leg_type: 'exit' as const,
      price: Number(primaryExit.price),
      quantity_lots: Number(primaryExit.quantity_lots),
      executed_at: new Date(parseLocalDateTime(primaryExit.executed_at) ?? closeDate).toISOString(),
      fees: Number(primaryExit.fees || 0),
      notes: primaryExit.notes.trim() ? primaryExit.notes.trim() : null,
    },
    ...exitLegs.value.map((leg) => {
      const executedAt = parseLocalDateTime(leg.executed_at)
      return {
        leg_type: 'exit' as const,
        price: Number(leg.price),
        quantity_lots: Number(leg.quantity_lots),
        executed_at: new Date(executedAt ?? closeDate).toISOString(),
        fees: Number(leg.fees || 0),
        notes: leg.notes.trim() ? leg.notes.trim() : null,
      }
    }),
  ]

  const exitQuantity = legs
    .filter((leg) => leg.leg_type === 'exit')
    .reduce((sum, leg) => sum + leg.quantity_lots, 0)
  if (exitQuantity <= 0) {
    throw new Error('Exit details are required.')
  }

  const weightedExitPrice = exitQuantity > 0
    ? legs
      .filter((leg) => leg.leg_type === 'exit')
      .reduce((sum, leg) => sum + (leg.price * leg.quantity_lots), 0) / exitQuantity
    : Number(form.entry_price)

  return {
    account_id: Number(form.account_id),
    instrument_id: instrument.id,
    strategy_model_id: strategyModelId,
    setup_id: setupId,
    killzone_id: killzoneId,
    session_enum: form.session_enum,
    tag_ids: form.tag_ids.map((id) => Number(id)).filter((id) => Number.isInteger(id) && id > 0),
    symbol: instrumentSymbol.value,
    direction: form.direction,
    close_date: new Date(closeDate).toISOString(),
    session: sessionLabel,
    strategy_model: `${strategyModelLabel} - ${setupLabel}`,
    entry_price: Number(form.entry_price),
    stop_loss: Number(form.stop_loss),
    take_profit: Number(form.take_profit),
    actual_exit_price: weightedExitPrice,
    position_size: Number(form.position_size),
    commission: Number(form.commission || 0),
    swap: Number(form.swap || 0),
    spread_cost: Number(form.spread_cost || 0),
    slippage_cost: Number(form.slippage_cost || 0),
    fx_rate_quote_to_usd: liveFxConversion.value?.rate ?? (instrument.quote_currency.toUpperCase() === 'USD' ? 1 : null),
    fx_symbol_used: liveFxConversion.value?.symbolUsed ?? null,
    fx_rate_timestamp: liveFxConversion.value?.ts ? new Date(liveFxConversion.value.ts).toISOString() : null,
    legs,
    risk_override_reason: form.risk_override_reason.trim() ? form.risk_override_reason.trim() : null,
    followed_rules: form.followed_rules,
    checklist_responses: checklistItems.value.map((item) => ({
      checklist_item_id: item.id,
      value: item.response.value,
    })),
    checklist_evaluation: {
      status: checklistReadiness.value.status,
      ready: checklistReadiness.value.ready,
      completed_required: checklistReadiness.value.completed_required,
      total_required: checklistReadiness.value.total_required,
    },
    precheck_snapshot: precheckResult.value?.calculated ?? null,
    checklist_incomplete: checklistGateIncomplete.value,
    emotion: form.emotion,
    notes: form.notes.trim() ? form.notes.trim() : null,
  }
}

function getFxMaterialSignature(value: FxQuoteToUsdResolution | null): string {
  if (!value) return 'none'
  // Only fields that influence risk math should trigger precheck refresh on quote updates.
  return `${value.method}|${value.mode}|${value.symbolUsed ?? 'identity'}|${value.rate.toFixed(8)}`
}

function getMaterialPrecheckPayload(payload: TradePayload) {
  return {
    account_id: payload.account_id,
    instrument_id: payload.instrument_id,
    strategy_model_id: payload.strategy_model_id ?? null,
    setup_id: payload.setup_id ?? null,
    killzone_id: payload.killzone_id ?? null,
    session_enum: payload.session_enum ?? null,
    symbol: payload.symbol,
    direction: payload.direction,
    close_date: payload.close_date,
    entry_price: payload.entry_price,
    stop_loss: payload.stop_loss,
    take_profit: payload.take_profit,
    actual_exit_price: payload.actual_exit_price,
    position_size: payload.position_size,
    commission: payload.commission ?? 0,
    swap: payload.swap ?? 0,
    spread_cost: payload.spread_cost ?? 0,
    slippage_cost: payload.slippage_cost ?? 0,
    fx_rate_quote_to_usd: payload.fx_rate_quote_to_usd ?? null,
    fx_symbol_used: payload.fx_symbol_used ?? null,
    risk_override_reason: payload.risk_override_reason ?? null,
    followed_rules: payload.followed_rules,
    checklist_incomplete: payload.checklist_incomplete ?? false,
    checklist_evaluation: payload.checklist_evaluation ?? null,
    checklist_responses: (payload.checklist_responses ?? []).map((item) => ({
      checklist_item_id: item.checklist_item_id,
      value: item.value,
    })),
    legs: (payload.legs ?? []).map((leg) => ({
      leg_type: leg.leg_type,
      price: leg.price,
      quantity_lots: leg.quantity_lots,
      executed_at: leg.executed_at,
      fees: leg.fees ?? 0,
      notes: leg.notes ?? null,
    })),
  }
}

function payloadHash(payload: ReturnType<typeof getMaterialPrecheckPayload>): string {
  return stableStringify(payload)
}

const precheckRunner = createDebouncedLatestRunner<
  { payload: TradePayload; hash: string },
  TradePrecheckResult
>({
  debounceMs: 800,
  getHash: (input) => input.hash,
  execute: async ({ payload }, context) => {
    return await tradeStore.precheckTrade(payload, tradeId.value ?? undefined, {
      signal: context.signal,
    })
  },
  onRequestStart: (context) => {
    precheckActiveRequestId.value = context.requestId
    precheckLoading.value = true
    precheckError.value = ''
    localRiskOverrideAccepted.value = false
  },
  onRequestSuccess: (result, context) => {
    if (precheckActiveRequestId.value !== context.requestId) return
    precheckResult.value = result
    tradeChecklistStore.applyServerReadinessPreview(result.checklist_gate ?? null)
    if (Array.isArray(result.checklist_gate?.failed_required_rule_ids)) {
      failedRequiredRuleIds.value = result.checklist_gate.failed_required_rule_ids
    }
    precheckError.value = ''
  },
  onRequestError: (error, context) => {
    if (precheckActiveRequestId.value !== context.requestId) return
    precheckError.value = extractErrorMessage(error)
  },
  onRequestSettled: (context) => {
    if (precheckActiveRequestId.value !== context.requestId) return
    precheckLoading.value = false
  },
})

async function runPrecheck(options?: { force?: boolean }) {
  const firstError = Object.values(formErrors.value)[0]
  if (firstError) return
  if (isFxPending.value) return

  try {
    const payload = buildPayload()
    const materialPayload = getMaterialPrecheckPayload(payload)
    precheckRunner.schedule(
      {
        payload,
        hash: payloadHash(materialPayload),
      },
      { force: options?.force === true }
    )
  } catch (error) {
    precheckError.value = extractErrorMessage(error)
  }
}

function schedulePrecheck() {
  void runPrecheck()
}

function clearPendingImages() {
  for (const image of pendingImages.value) {
    URL.revokeObjectURL(image.preview_url)
  }
  pendingImages.value = []
  uploadProgressByPendingId.value = {}
}

function removePendingImage(id: string) {
  const index = pendingImages.value.findIndex((image) => image.id === id)
  if (index < 0) return
  URL.revokeObjectURL(pendingImages.value[index]!.preview_url)
  pendingImages.value.splice(index, 1)
}

async function removeExistingImage(imageId: number) {
  if (!isEditMode.value || tradeId.value === null) return
  if (deletingImageIds.value.includes(imageId)) return

  deletingImageIds.value = [...deletingImageIds.value, imageId]
  try {
    await tradeStore.deleteTradeImage(imageId)
    existingImages.value = existingImages.value.filter((image) => image.id !== imageId)
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Failed to delete image',
      message: extractErrorMessage(error),
    })
  } finally {
    deletingImageIds.value = deletingImageIds.value.filter((id) => id !== imageId)
  }
}

function reorderPendingImages(payload: { from: number; to: number }) {
  const { from, to } = payload
  if (from < 0 || to < 0 || from >= pendingImages.value.length || to >= pendingImages.value.length) return
  const items = pendingImages.value.slice()
  const [moved] = items.splice(from, 1)
  if (!moved) return
  items.splice(to, 0, moved)
  pendingImages.value = items
}

async function onSelectImageFiles(files: File[]) {
  imageUploadError.value = ''
  if (files.length === 0) return

  const availableSlots = MAX_IMAGE_COUNT - totalImageCount.value
  if (availableSlots <= 0) {
    imageUploadError.value = 'Maximum 5 images per trade allowed.'
    return
  }

  const selected = files.slice(0, availableSlots)
  const queued: PendingTradeImage[] = []

  for (const file of selected) {
    if (!allowedImageTypes.has(file.type)) {
      imageUploadError.value = 'Only jpg, jpeg, png, webp, and bmp files are allowed.'
      continue
    }

    if (file.size > MAX_IMAGE_SIZE_BYTES) {
      imageUploadError.value = 'Each image must be 5MB or smaller.'
      continue
    }

    const compressed = await compressImage(file)
    if (compressed.size > MAX_IMAGE_SIZE_BYTES) {
      imageUploadError.value = 'Compressed image still exceeds 5MB. Use a smaller image.'
      continue
    }

    const previewUrl = URL.createObjectURL(compressed)
    queued.push({
      id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
      file: compressed,
      preview_url: previewUrl,
      context_tag: 'entry',
      timeframe: '',
      annotation_notes: '',
    })
  }

  if (queued.length === 0) return

  const queuedBytes = queued.reduce((sum, image) => sum + image.file.size, 0)
  if ((totalImageSize.value + queuedBytes) > MAX_TOTAL_IMAGE_BYTES) {
    for (const image of queued) {
      URL.revokeObjectURL(image.preview_url)
    }
    imageUploadError.value = 'Total image uploads per trade cannot exceed 20MB.'
    return
  }

  pendingImages.value = [...pendingImages.value, ...queued]
}

async function uploadPendingImages(trade: Trade) {
  if (pendingImages.value.length === 0) return

  uploadingImages.value = true
  imageUploadError.value = ''

  try {
    const baseSort = existingImages.value.length
    const uploadedImages: TradeImage[] = []

    for (let index = 0; index < pendingImages.value.length; index += 1) {
      const image = pendingImages.value[index]!
      uploadProgressByPendingId.value = {
        ...uploadProgressByPendingId.value,
        [image.id]: 0,
      }

      const uploaded = await tradeStore.uploadTradeImage(
        trade.id,
        image.file,
        baseSort + index,
        {
          context_tag: image.context_tag,
          timeframe: image.timeframe || null,
          annotation_notes: image.annotation_notes || null,
        },
        (progress) => {
          uploadProgressByPendingId.value = {
            ...uploadProgressByPendingId.value,
            [image.id]: progress,
          }
        }
      )

      uploadedImages.push(uploaded)
    }

    existingImages.value = [...existingImages.value, ...uploadedImages]
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
    clearPendingImages()
  } catch (error) {
    imageUploadError.value = extractErrorMessage(error)
    throw error
  } finally {
    uploadingImages.value = false
  }
}

function buildPsychologyPayload() {
  return {
    pre_emotion: psychology.pre_emotion.trim() || null,
    post_emotion: psychology.post_emotion.trim() || null,
    confidence_score: psychology.confidence_score,
    stress_score: psychology.stress_score,
    sleep_hours: psychology.sleep_hours,
    impulse_flag: psychology.impulse_flag,
    fomo_flag: psychology.fomo_flag,
    revenge_flag: psychology.revenge_flag,
    notes: psychology.notes.trim() ? psychology.notes.trim() : null,
  }
}

function setupEditLock() {
  if (!isEditMode.value || tradeId.value === null) {
    editLockState.value = null
    return
  }

  if (releaseEditLockSubscription) {
    releaseEditLockSubscription()
    releaseEditLockSubscription = null
  }
  if (editLockHandle) {
    editLockHandle.stop()
    editLockHandle = null
  }

  editLockHandle = createTradeEditLock(tradeId.value)
  releaseEditLockSubscription = editLockHandle.subscribe((next) => {
    editLockState.value = next
  })
  editLockHandle.start()
}

function teardownEditLock() {
  if (releaseEditLockSubscription) {
    releaseEditLockSubscription()
    releaseEditLockSubscription = null
  }
  if (editLockHandle) {
    editLockHandle.stop()
    editLockHandle = null
  }
  editLockState.value = null
}

function takeOverEditLock() {
  if (!editLockHandle) return
  const approved = typeof window === 'undefined'
    ? true
    : window.confirm('Take over edit lock from the other tab?')
  if (!approved) return

  const acquired = editLockHandle.takeOver()
  if (acquired) {
    uiStore.toast({
      type: 'success',
      title: 'Edit lock acquired',
      message: 'This tab now controls saving for this trade.',
    })
    return
  }

  uiStore.toast({
    type: 'error',
    title: 'Could not take over lock',
    message: 'Try again in a moment.',
  })
}

function normalizeUpdatedAt(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  if (!trimmed) return null
  const parsed = Date.parse(trimmed)
  if (!Number.isFinite(parsed)) return null
  return new Date(parsed).toISOString()
}

function formatUpdatedAtForBanner(value: string): string {
  const parsed = Date.parse(value)
  if (!Number.isFinite(parsed)) return value
  return new Date(parsed).toLocaleString()
}

function stringValue(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (Array.isArray(value)) return value.map((item) => String(item)).join(', ')
  return String(value)
}

function pushDiff(
  diffs: Array<{ field: string; local: string; latest: string }>,
  field: string,
  localValue: unknown,
  latestValue: unknown
) {
  const local = stringValue(localValue).trim()
  const latest = stringValue(latestValue).trim()
  if (local === latest) return
  diffs.push({
    field,
    local: local || '-',
    latest: latest || '-',
  })
}

function staleWriteDiffs(payload: TradePayload, latestTrade: Trade): Array<{ field: string; local: string; latest: string }> {
  const diffs: Array<{ field: string; local: string; latest: string }> = []
  pushDiff(diffs, 'Account', payload.account_id, latestTrade.account_id)
  pushDiff(diffs, 'Symbol', payload.symbol.toUpperCase(), latestTrade.pair)
  pushDiff(diffs, 'Direction', payload.direction, latestTrade.direction)
  pushDiff(diffs, 'Entry Price', payload.entry_price, latestTrade.entry_price)
  pushDiff(diffs, 'Stop Loss', payload.stop_loss, latestTrade.stop_loss)
  pushDiff(diffs, 'Take Profit', payload.take_profit, latestTrade.take_profit)
  pushDiff(diffs, 'Exit Price', payload.actual_exit_price, latestTrade.actual_exit_price)
  pushDiff(diffs, 'Position Size', payload.position_size, latestTrade.lot_size)
  pushDiff(diffs, 'Close Date', payload.close_date, latestTrade.date)
  pushDiff(diffs, 'Emotion', payload.emotion, latestTrade.emotion)
  pushDiff(diffs, 'Followed Rules', payload.followed_rules, latestTrade.followed_rules)
  pushDiff(
    diffs,
    'Tags',
    [...(payload.tag_ids ?? [])].sort((a, b) => a - b).join(','),
    [...(latestTrade.tag_ids ?? [])].sort((a, b) => a - b).join(',')
  )
  pushDiff(diffs, 'Notes', payload.notes ?? '', latestTrade.notes ?? '')
  return diffs
}

function clearStaleWriteWarning() {
  staleWriteWarning.value = null
  staleWriteConfirmedFingerprint.value = null
}

async function verifyStaleWriteBeforeSave(payload: TradePayload): Promise<boolean> {
  if (!isEditMode.value || tradeId.value === null) return true
  const baselineUpdatedAt = normalizeUpdatedAt(loadedTradeUpdatedAt.value)
  if (!baselineUpdatedAt) return true

  let latestTrade: Trade | null = null
  const snapshot = tradeStore.trades.find((row) => row.id === tradeId.value) ?? null
  const snapshotUpdatedAt = normalizeUpdatedAt(snapshot?.updated_at)
  if (snapshot && snapshotUpdatedAt && snapshotUpdatedAt !== baselineUpdatedAt) {
    latestTrade = snapshot
  }

  if (!latestTrade) {
    staleWriteCheckInProgress.value = true
    try {
      const latest = await tradeStore.fetchTradeDetails(tradeId.value)
      latestTrade = latest.trade
    } catch {
      staleWriteCheckInProgress.value = false
      return true
    } finally {
      staleWriteCheckInProgress.value = false
    }
  }

  const latestUpdatedAt = normalizeUpdatedAt(latestTrade.updated_at)
  if (!latestUpdatedAt || latestUpdatedAt === baselineUpdatedAt) {
    clearStaleWriteWarning()
    return true
  }

  const fingerprint = `${baselineUpdatedAt}|${latestUpdatedAt}`
  staleWriteWarning.value = {
    fingerprint,
    loaded_updated_at: baselineUpdatedAt,
    latest_updated_at: latestUpdatedAt,
    diffs: staleWriteDiffs(payload, latestTrade),
  }

  if (staleWriteConfirmedFingerprint.value === fingerprint) {
    return true
  }

  uiStore.toast({
    type: 'info',
    title: 'Trade was updated elsewhere',
    message: 'Review differences and confirm overwrite before saving.',
  })
  return false
}

function confirmStaleOverwriteAndSave() {
  if (!staleWriteWarning.value) return
  staleWriteConfirmedFingerprint.value = staleWriteWarning.value.fingerprint
  void submitForm()
}

async function reloadTradeFromLatest() {
  if (!isEditMode.value || tradeId.value === null) return
  const approved = typeof window === 'undefined'
    ? true
    : window.confirm('Reload latest server trade data? Unsaved changes and pending images will be discarded.')
  if (!approved) return

  clearPendingImages()
  clearStaleWriteWarning()
  await loadTradeIfNeeded()
  await loadChecklistState()
  schedulePrecheck()
}

function handleGlobalHotkeys(event: KeyboardEvent) {
  const key = event.key.toLowerCase()
  if (!(event.ctrlKey || event.metaKey)) return

  if (key === 's') {
    event.preventDefault()
    handleExecuteClick()
  }
}

async function submitForm() {
  editLockHandle?.refresh()
  if (isEditMode.value && isLockedByOtherTab.value) {
    uiStore.toast({
      type: 'error',
      title: 'Edit locked by another tab',
      message: 'Use "Take over" before saving from this tab.',
    })
    return
  }

  submitAttempted.value = true
  serverFieldErrors.value = {}
  tradeChecklistStore.markSubmitAttempted(true)
  const firstError = Object.values(formErrors.value)[0]
  if (firstError) {
    return
  }

  if (precheckLoading.value) {
    uiStore.toast({
      type: 'info',
      title: 'Risk check in progress',
      message: 'Please wait for pre-trade risk validation to finish.',
    })
    return
  }

  if (isSaveBlocked.value) {
    return
  }

  if (isChecklistContextMismatch.value) {
    uiStore.toast({
      type: 'info',
      title: 'Rules refreshing',
      message: 'Waiting for rule context to match selected account and strategy.',
    })
    return
  }

  if (isChecklistStrictBlocked.value) {
    uiStore.toast({
      type: 'error',
      title: 'Blocked by strict rules',
      message: checklistServerReadinessReasons.value[0]?.reason
        || 'Server checklist readiness is not ready yet.',
    })
    return
  }

  try {
    const payload = buildPayload()
    const staleSafe = await verifyStaleWriteBeforeSave(payload)
    if (!staleSafe) {
      return
    }

    const hadPendingImages = pendingImages.value.length > 0
    let savedTrade: Trade

    if (isEditMode.value && tradeId.value !== null) {
      savedTrade = await tradeStore.updateTrade(tradeId.value, payload)
    } else {
      savedTrade = await tradeStore.addTrade(payload)
    }

    await tradeStore.upsertTradePsychology(savedTrade.id, buildPsychologyPayload())

    await uploadPendingImages(savedTrade)
    loadedTradeUpdatedAt.value = normalizeUpdatedAt(savedTrade.updated_at)
    clearStaleWriteWarning()
    serverFieldErrors.value = {}

    uiStore.toast({
      type: 'success',
      title: isEditMode.value ? 'Execution updated' : 'Execution logged',
      message: hadPendingImages
        ? `${payload.symbol} saved with images.`
        : `${payload.symbol} has been saved to your execution journal.`,
    })

    tradeChecklistStore.clearSubmitAttempted()
    void router.push('/trades')
  } catch (error) {
    const normalized = normalizeApiError(error)
    applyServerFieldErrors(normalized)

    if (isTradeRevisionConflict(error) && isEditMode.value && tradeId.value !== null) {
      clearPendingImages()
      await loadTradeIfNeeded()
      await loadChecklistState()
      schedulePrecheck()
      uiStore.toast({
        type: 'info',
        title: 'Trade updated elsewhere',
        message: 'This trade was updated elsewhere. Reloaded latest version.',
      })
      return
    }

    const failingRuleIds = extractFailingRuleIds(error)
    if (failingRuleIds.length > 0) {
      failedRequiredRuleIds.value = failingRuleIds
    }

    uiStore.toast({
      type: 'error',
      title: 'Failed to save execution',
      message: normalized.message,
    })
  }
}

function handleExecuteClick() {
  if (isSubmittingDisabled.value) return
  void submitForm()
}

async function loadTradeIfNeeded() {
  if (!isEditMode.value || tradeId.value === null) {
    tradeCompleted.value = true
    loadedTradeUpdatedAt.value = null
    clearStaleWriteWarning()
    serverFieldErrors.value = {}
    form.date = nowLocalDateTime()
    ensureDefaultExitLeg()
    setPsychologyFromPayload(null)
    return
  }

  tradeCompleted.value = true
  loadingTrade.value = true
  try {
    const data = await tradeStore.fetchTradeDetails(tradeId.value)
    loadedTradeUpdatedAt.value = normalizeUpdatedAt(data.trade.updated_at)
    clearStaleWriteWarning()
    serverFieldErrors.value = {}
    setFormFromTrade(data.trade, data.legs ?? [], data.psychology ?? null)
    existingImages.value = (data.images ?? [])
      .slice()
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Execution not found',
      message: 'Could not load this execution for editing.',
    })
    void router.push('/trades')
  } finally {
    loadingTrade.value = false
  }
}

async function loadChecklistState() {
  tradeChecklistStore.clearSubmitAttempted()
  await tradeChecklistStore.loadForContext(
    selectedChecklistAccountId.value,
    selectedChecklistStrategyModelId.value,
    selectedChecklistTradeId.value
  )
}

function onChecklistResponseChange(itemId: number, value: unknown) {
  tradeChecklistStore.updateResponse(itemId, value, false)
}

function onChecklistEvaluationChange(payload: { failedRequiredIds: number[]; firstFailingId: number | null }) {
  failedRequiredRuleIds.value = payload.failedRequiredIds
}

onMounted(async () => {
  window.addEventListener('keydown', handleGlobalHotkeys)
  setupEditLock()

  try {
    await Promise.all([
      accountStore.fetchAccounts(),
      tradeStore.fetchInstruments(),
      tradeStore.fetchFxRates(),
      tradeStore.fetchDictionaries(),
    ])
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load setup data',
      message: 'Please refresh and try again.',
    })
  }

  await applySmartDefaultsFromLastTrade()

  if (!isEditMode.value && !form.account_id && accountSelectOptions.value.length > 0) {
    form.account_id = accountSelectOptions.value[0]?.value ?? ''
  }
  if (!isEditMode.value && !form.instrument_id && instruments.value.length > 0) {
    form.instrument_id = String(instruments.value[0]?.id ?? '')
  }
  if (!isEditMode.value && !form.strategy_model_id && strategyModelOptions.value.length > 0) {
    form.strategy_model_id = strategyModelOptions.value[0]?.value ?? ''
  }
  if (!isEditMode.value && !form.setup_id && setupOptions.value.length > 0) {
    form.setup_id = setupOptions.value[0]?.value ?? ''
  }
  if (!isEditMode.value && !form.killzone_id && killzoneOptions.value.length > 0) {
    form.killzone_id = killzoneOptions.value[0]?.value ?? ''
  }
  if (!isEditMode.value && !form.session_enum && sessionEnumOptions.value.length > 0) {
    form.session_enum = (sessionEnumOptions.value[0]?.value as SessionEnum | undefined) ?? ''
  }

  applyQuickDefaultsFromQuery()
  await loadTradeIfNeeded()
  if (!form.instrument_id && form.symbol.trim()) {
    selectInstrumentBySymbol(form.symbol)
  }
  await loadChecklistState()
  schedulePrecheck()
})

watch(
  () => tradeId.value,
  (next, previous) => {
    if (next === previous) return
    setupEditLock()
    void loadTradeIfNeeded()
    void loadChecklistState()
    schedulePrecheck()
  }
)

watch(
  () => [form.account_id, form.strategy_model_id],
  ([accountId, strategyModelId], [previousAccountId, previousStrategyModelId]) => {
    if (accountId === previousAccountId && strategyModelId === previousStrategyModelId) return
    void loadChecklistState()
  }
)

watch(
  () => selectedInstrument.value?.quote_currency ?? '',
  (quoteCurrency) => {
    for (const unsubscribe of unsubscribeFxListeners) {
      unsubscribe()
    }
    unsubscribeFxListeners = []
    if (stopTrackingFxSymbols) {
      stopTrackingFxSymbols()
      stopTrackingFxSymbols = null
    }

    const symbols = fxResolver.getTrackedSymbolsForQuoteCurrency(quoteCurrency)
    if (symbols.length === 0) {
      quoteTickVersion.value += 1
      void refreshLiveFxConversion()
      return
    }

    stopTrackingFxSymbols = livePriceFeedService.trackSymbols(symbols)
    unsubscribeFxListeners = symbols.map((symbol) =>
      livePriceFeedService.subscribe(symbol, () => {
        quoteTickVersion.value += 1
        // Do not trigger precheck on every quote tick.
        // We only re-run precheck when `refreshLiveFxConversion` detects a material FX change.
      })
    )
    quoteTickVersion.value += 1
    void refreshLiveFxConversion()
    schedulePrecheck()
  },
  { immediate: true }
)

watch(
  () => quoteTickVersion.value,
  () => {
    void refreshLiveFxConversion()
  }
)

watch(
  () => form.instrument_id,
  (value) => {
    const id = Number(value)
    const instrument = instruments.value.find((item) => item.id === id)
    if (instrument) {
      form.symbol = instrument.symbol
      if (toNumber(form.position_size) < toNumber(instrument.min_lot)) {
        form.position_size = toNumber(instrument.min_lot)
      }
    }
    void refreshLiveFxConversion()
    schedulePrecheck()
  }
)

watch(
  () => form.killzone_id,
  (value) => {
    const id = Number(value)
    const match = killzones.value.find((item) => item.id === id)
    if (match?.session_enum) {
      form.session_enum = match.session_enum
    }
    schedulePrecheck()
  }
)

watch(
  () => [
    form.account_id,
    form.strategy_model_id,
    form.setup_id,
    form.killzone_id,
    form.session_enum,
    form.direction,
    form.date,
    form.entry_price,
    form.stop_loss,
    form.take_profit,
    form.position_size,
    form.commission,
    form.swap,
    form.spread_cost,
    form.slippage_cost,
    form.risk_override_reason,
    tradeCompleted.value,
    primaryExit.price,
    primaryExit.quantity_lots,
    primaryExit.executed_at,
    primaryExit.fees,
  ],
  () => {
    schedulePrecheck()
  }
)

watch(
  () => isRiskEngineUnavailable.value,
  (unavailable) => {
    if (!unavailable) {
      localRiskOverrideAccepted.value = false
    }
  }
)

watch(
  exitLegs,
  () => {
    schedulePrecheck()
  },
  { deep: true }
)

watch(
  () => form.tag_ids.slice(),
  () => {
    schedulePrecheck()
  }
)

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleGlobalHotkeys)
  teardownEditLock()
  clearPendingImages()
  tradeChecklistStore.resetState()
  precheckRunner.cancel()
  for (const unsubscribe of unsubscribeFxListeners) {
    unsubscribe()
  }
  unsubscribeFxListeners = []
  if (stopTrackingFxSymbols) {
    stopTrackingFxSymbols()
    stopTrackingFxSymbols = null
  }
})

async function compressImage(file: File): Promise<File> {
  try {
    const image = await loadImage(file)
    const maxDimension = 1920
    const ratio = Math.min(1, maxDimension / Math.max(image.width, image.height))
    const targetWidth = Math.max(1, Math.round(image.width * ratio))
    const targetHeight = Math.max(1, Math.round(image.height * ratio))

    const canvas = document.createElement('canvas')
    canvas.width = targetWidth
    canvas.height = targetHeight

    const context = canvas.getContext('2d')
    if (!context) return file

    context.drawImage(image, 0, 0, targetWidth, targetHeight)

    const outputType = file.type === 'image/webp' ? 'image/webp' : 'image/jpeg'
    const blob = await new Promise<Blob | null>((resolve) => {
      canvas.toBlob(resolve, outputType, 0.82)
    })

    if (!blob) return file
    if (blob.size >= file.size) return file

    const normalizedName = normalizeFileName(file.name, outputType)
    return new File([blob], normalizedName, {
      type: outputType,
      lastModified: Date.now(),
    })
  } catch {
    return file
  }
}

function normalizeFileName(name: string, mimeType: string) {
  const base = name.replace(/\.[^/.]+$/, '')
  const ext = mimeType === 'image/webp' ? 'webp' : 'jpg'
  return `${base}.${ext}`
}

async function loadImage(file: File): Promise<HTMLImageElement> {
  const url = URL.createObjectURL(file)

  return await new Promise((resolve, reject) => {
    const image = new Image()
    image.onload = () => {
      URL.revokeObjectURL(url)
      resolve(image)
    }
    image.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('Unable to load image'))
    }
    image.src = url
  })
}
</script>

<template>
  <div class="space-y-4 trade-form-minimal execution-long-page" data-testid="trade-form-page">
    <GlassPanel class="form-shell-panel execution-long-shell form-shell-unified">
      <div v-if="loadingTrade" class="space-y-3">
        <div class="skeleton-shimmer h-12 rounded-xl" />
        <div class="skeleton-shimmer h-12 rounded-xl" />
        <div class="skeleton-shimmer h-12 rounded-xl" />
      </div>

      <div v-else class="trade-form-with-checklist">
        <form :id="tradeFormId" class="form-block space-y-4 trade-form-main" @submit.prevent="handleExecuteClick">
        <div v-if="hasAcceptedLocalRiskOverride" class="risk-unverified-watermark">
          RISK UNVERIFIED · LOCAL DRAFT
        </div>
        <div class="execution-long-header">
          <div>
            <h2 class="section-title">{{ pageTitle }}</h2>
            <p class="section-note">
              Minimal form for fast execution logging.
            </p>
          </div>
          <button type="button" class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="router.push('/trades')">
            <ArrowLeft class="h-4 w-4" />
            Back
          </button>
        </div>

        <p v-if="blockedSummary" class="field-error-text">{{ blockedSummary }}</p>
        <div v-if="isLockedByOtherTab" class="panel p-3 text-sm risk-unverified-banner">
          <p class="font-semibold">Edit lock active in {{ lockHolderLabel }}</p>
          <p class="mt-1">
            Another tab currently owns this trade edit session. Saving is blocked in this tab until you take over.
          </p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="btn btn-secondary px-3 py-1.5 text-sm" @click="takeOverEditLock">
              Take over
            </button>
            <button type="button" class="btn btn-ghost px-3 py-1.5 text-sm" @click="void loadTradeIfNeeded()">
              Refresh
            </button>
          </div>
        </div>

        <div v-if="staleWriteWarning" class="panel p-3 text-sm">
          <p class="font-semibold">Stale write warning</p>
          <p class="mt-1">
            This trade changed after you loaded the edit form.
            Loaded: <strong>{{ formatUpdatedAtForBanner(staleWriteWarning.loaded_updated_at) }}</strong> ·
            Latest: <strong>{{ formatUpdatedAtForBanner(staleWriteWarning.latest_updated_at) }}</strong>
          </p>
          <ul v-if="staleWriteWarning.diffs.length > 0" class="mt-2 list-disc pl-5">
            <li v-for="diff in staleWriteWarning.diffs" :key="diff.field">
              {{ diff.field }}: current form <strong>{{ diff.local }}</strong> vs latest <strong>{{ diff.latest }}</strong>
            </li>
          </ul>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="btn btn-secondary px-3 py-1.5 text-sm" @click="confirmStaleOverwriteAndSave">
              Confirm overwrite and save
            </button>
            <button type="button" class="btn btn-ghost px-3 py-1.5 text-sm" @click="void reloadTradeFromLatest()">
              Reload latest
            </button>
          </div>
        </div>

        <div v-if="isRiskEngineUnavailable" class="panel p-3 text-sm risk-unverified-banner">
          <p class="font-semibold">Risk engine unavailable</p>
          <p class="mt-1">
            This submission is blocked by default. You can proceed only as a local-only draft and it will remain
            <strong>risk unverified</strong> until server confirmation.
          </p>
          <label class="mt-2 inline-flex items-center gap-2">
            <input v-model="localRiskOverrideAccepted" type="checkbox">
            <span>I accept risk (local-only draft)</span>
          </label>
        </div>

        <section class="trade-form-section execution-long-section">
          <div class="execution-section-heading">
            <TrendingUp class="execution-section-icon h-5 w-5" />
            <p class="section-title">Entry Details</p>
          </div>

          <div class="grid grid-premium md:grid-cols-2 execution-entry-grid" @keydown.enter="onQuickTicketEnter">
            <InstrumentPairSelect
              v-model="form.instrument_id"
              label="Instrument / Pair"
              required
              :instruments="instruments"
              :error="fieldError('instrument_id')"
            />
            <BaseSelect
              v-model="form.account_id"
              label="Accounts"
              required
              searchable
              search-placeholder="Search account..."
              :options="accountSelectOptions"
              :error="fieldError('account_id')"
            />
            <BaseInput
              v-model="form.position_size"
              label="Position Size (Units)"
              type="number"
              required
              min="0.0001"
              step="0.0001"
              :error="fieldError('position_size')"
              @blur="formatLotField('position_size')"
            />
            <BaseSelect v-model="form.direction" label="Trade Type" :options="directionOptions" />
            <BaseDateTime
              v-model="form.date"
              label="Date & Entry Time"
              required
              :max="closeDateMax"
              :error="fieldError('date')"
            />
            <div class="execution-tag-panel">
              <p class="kicker-label">Tags</p>
              <BaseInput
                v-model="form.tag_search"
                class="mt-2 execution-tag-search"
                label="Search Tags"
                placeholder="Type to search tags..."
              />
              <div v-if="filteredTagOptions.length > 0" class="mt-2 flex flex-wrap gap-2">
                <button
                  v-for="tag in filteredTagOptions.slice(0, 10)"
                  :key="`tag-option-${tag.id}`"
                  type="button"
                  class="chip-btn"
                  @click="addTag(tag.id)"
                >
                  + {{ tag.name }}
                </button>
              </div>
              <div class="mt-2 flex flex-wrap gap-2">
                <span v-for="tag in selectedTags" :key="`selected-tag-${tag.id}`" class="pill pill-positive inline-flex items-center gap-1">
                  {{ tag.name }}
                  <button type="button" class="btn btn-ghost p-0 text-xs" @click="removeTag(tag.id)">x</button>
                </span>
              </div>
            </div>
            <BaseInput
              v-model="form.entry_price"
              label="Entry Price"
              type="number"
              required
              min="0.000001"
              step="0.000001"
              :error="fieldError('entry_price')"
              @blur="formatPriceField('entry_price')"
            />
            <BaseInput
              v-model="form.stop_loss"
              label="Stop Loss"
              type="number"
              required
              min="0.000001"
              step="0.000001"
              :error="fieldError('stop_loss')"
              @blur="formatPriceField('stop_loss')"
            />
            <BaseInput
              v-model="form.take_profit"
              label="Take Profit"
              type="number"
              required
              min="0.000001"
              step="0.000001"
              :error="fieldError('take_profit')"
              @blur="formatPriceField('take_profit')"
            />
            <BaseSelect
              v-model="form.strategy_model_id"
              label="Strategy"
              required
              searchable
              search-placeholder="Search strategy..."
              :options="strategyModelOptions"
              :error="fieldError('strategy_model_id')"
            />
            <BaseSelect
              v-model="form.setup_id"
              label="Setup"
              required
              searchable
              search-placeholder="Search setup..."
              :options="setupOptions"
              :error="fieldError('setup_id')"
            />
            <BaseSelect
              v-model="form.killzone_id"
              label="Killzone"
              required
              searchable
              search-placeholder="Search killzone..."
              :options="killzoneOptions"
              :error="fieldError('killzone_id')"
            />
            <BaseSelect
              v-model="form.session_enum"
              label="Session"
              required
              :options="sessionEnumOptions"
              :error="fieldError('session_enum')"
            />
          </div>
        </section>

        <TradeRulesPanel
          mode="mobile"
          :checklist="activeChecklist"
          :required-items="checklistRequiredItems"
          :optional-items="checklistOptionalItems"
          :archived-responses="checklistArchivedResponses"
          :readiness="checklistReadiness"
          :server-readiness="checklistServerReadiness"
          :server-readiness-mismatch="checklistServerReadinessMismatch"
          :server-readiness-reasons="checklistServerReadinessReasons"
          :execution-snapshot="checklistExecutionSnapshot"
          :loading="checklistLoading"
          :saving="checklistSaving"
          :submit-attempted="checklistSubmitAttempted || submitAttempted"
          :strict-mode="checklistStrictMode"
          :risk-precheck="precheckResult"
          @update-response="onChecklistResponseChange"
          @evaluation-change="onChecklistEvaluationChange"
        />

        <section class="trade-form-section execution-long-section">
          <div class="execution-section-heading">
            <DollarSign class="execution-section-icon h-5 w-5" />
            <p class="section-title">Exit Details</p>
          </div>

          <button
            type="button"
            class="execution-complete-toggle"
            :class="{ 'is-on': tradeCompleted }"
            :aria-pressed="tradeCompleted"
            @click="tradeCompleted = !tradeCompleted"
          >
            <span class="execution-complete-toggle-icon" aria-hidden="true">
              <Check class="h-3.5 w-3.5" />
            </span>
            <span>Trade is completed</span>
          </button>
          <p v-if="fieldError('trade_completed')" class="field-error-text mt-2">{{ fieldError('trade_completed') }}</p>

          <div v-if="tradeCompleted" class="execution-exit-stack">
            <article class="panel execution-partial-card">
              <div class="execution-partial-head">
                <p class="text-sm font-semibold">Main Exit</p>
                <button type="button" class="chip-btn" @click="void runPrecheck({ force: true })">Recalculate</button>
              </div>
              <div class="grid grid-premium md:grid-cols-2 xl:grid-cols-4">
                <BaseInput
                  v-model="primaryExit.price"
                  label="Exit Price"
                  type="number"
                  min="0.000001"
                  step="0.000001"
                  :error="fieldError('exit_price')"
                />
                <BaseInput
                  v-model="primaryExit.quantity_lots"
                  label="Exit Size"
                  type="number"
                  min="0.0001"
                  step="0.0001"
                  :error="fieldError('exit_quantity')"
                />
                <BaseInput
                  v-model="primaryExit.fees"
                  label="Commission Fee"
                  type="number"
                  step="0.01"
                />
                <BaseInput
                  :model-value="estimateLegPnlLabel(primaryExit)"
                  label="P&L"
                  disabled
                />
                <BaseDateTime
                  v-model="primaryExit.executed_at"
                  label="Exit Date & Time"
                  :max="closeDateMax"
                  :show-quick-actions="false"
                  :error="fieldError('exit_time')"
                />
                <BaseInput
                  v-model="primaryExit.notes"
                  class="execution-exit-note"
                  label="Exit Note"
                  multiline
                  :rows="2"
                  placeholder="Optional note..."
                />
              </div>
            </article>

            <div class="execution-exit-actions">
              <p class="kicker-label m-0">Partial Exits (Optional)</p>
              <button type="button" class="btn btn-ghost px-3 py-1.5 text-xs" @click="addExitLeg">
                Add Partial Exit
              </button>
            </div>
            <p v-if="fieldError('legs')" class="field-error-text">{{ fieldError('legs') }}</p>

            <article v-for="(leg, index) in exitLegs" :key="leg.id" class="panel execution-partial-card">
              <div class="execution-partial-head">
                <p class="text-sm font-semibold">Partial Exit #{{ index + 1 }}</p>
                <button
                  type="button"
                  class="btn btn-ghost px-2 py-1 text-xs"
                  @click="removeExitLeg(leg.id)"
                >
                  Remove
                </button>
              </div>
              <div class="grid grid-premium md:grid-cols-2 xl:grid-cols-4">
                <BaseInput
                  v-model="leg.price"
                  label="Exit Price"
                  type="number"
                  min="0.000001"
                  step="0.000001"
                  :error="fieldError(`legs.${index}.price`)"
                />
                <BaseInput
                  v-model="leg.quantity_lots"
                  label="Exit Size"
                  type="number"
                  min="0.0001"
                  step="0.0001"
                  :error="fieldError(`legs.${index}.quantity_lots`)"
                />
                <BaseInput
                  v-model="leg.fees"
                  label="Commission Fee"
                  type="number"
                  step="0.01"
                />
                <BaseInput
                  :model-value="estimateLegPnlLabel(leg)"
                  label="P&L"
                  disabled
                />
                <BaseDateTime
                  v-model="leg.executed_at"
                  label="Exit Date & Time"
                  :max="closeDateMax"
                  :show-quick-actions="false"
                  :error="fieldError(`legs.${index}.executed_at`)"
                />
                <BaseInput
                  v-model="leg.notes"
                  class="execution-exit-note"
                  label="Exit Note"
                  multiline
                  :rows="2"
                  placeholder="Optional note..."
                />
              </div>
            </article>

            <div class="execution-exit-summary">
              <p>Total Exit Size: <strong>{{ exitLegSummary.quantity.toFixed(4) }}</strong></p>
              <p>Average Exit Price: <strong>{{ exitLegSummary.weightedPrice.toFixed(6) }}</strong></p>
              <p>Total Exit Fees: <strong>{{ asCurrency(exitLegSummary.fees) }}</strong></p>
            </div>
          </div>
        </section>

        <section class="trade-form-section execution-long-section">
          <div class="section-head">
            <p class="trade-section-title">Risk Check</p>
            <span class="filter-chip-mini" :class="riskStatusClass">{{ riskStatusLabel }}</span>
          </div>
          <p v-if="isRiskEngineUnavailable" class="field-error-text">
            Risk precheck unavailable. Confirm local-only override to save as draft.
          </p>
          <p v-if="precheckUpdating" class="text-xs muted">Updating risk check...</p>
          <p v-if="isFxPending" class="text-xs muted">Fetching FX quote...</p>
          <p v-if="liveFxConversionError" class="field-error-text">{{ liveFxConversionError }}</p>
          <details v-if="liveFxConversionError && liveFxAttemptedSymbols.length > 0" class="mt-1 text-xs muted">
            <summary>Tried symbols</summary>
            <p class="mt-1">{{ liveFxAttemptedSymbols.join(', ') }}</p>
          </details>
          <p v-if="precheckError" class="field-error-text">{{ precheckError }}</p>
          <div v-else-if="precheckResult" class="panel p-3 text-sm">
            <div class="grid grid-cols-2 gap-2 text-xs">
              <p>Risk $: <strong>{{ asCurrency(precheckResult.stats.monetary_risk) }}</strong></p>
              <p>Risk %: <strong>{{ precheckResult.stats.risk_percent.toFixed(2) }}%</strong></p>
              <p>Projected Daily Loss %: <strong>{{ precheckResult.stats.projected_daily_loss_pct.toFixed(2) }}%</strong></p>
              <p>R:R: <strong>{{ precheckResult.calculated.rr.toFixed(2) }}R</strong></p>
              <p>Risk Currency: <strong>USD</strong></p>
              <p>
                FX:
                <strong>
                  {{
                    isFxPending
                      ? 'Fetching quote...'
                      : liveFxConversion
                        ? `${selectedInstrument?.quote_currency ?? ''}->USD via ${liveFxConversion.symbolUsed ?? 'identity'} (${liveFxConversion.method}) @ ${liveFxConversion.rate.toFixed(6)} (${liveFxConversion.mode})`
                        : (selectedInstrument?.quote_currency?.toUpperCase() === 'USD' ? 'USD->USD @ 1 (identity)' : '-')
                  }}
                </strong>
              </p>
            </div>
            <ul v-if="precheckResult.violations.length > 0" class="mt-2 list-disc pl-5">
              <li v-for="violation in precheckResult.violations" :key="violation.code">
                {{ violation.message }} ({{ violation.actual.toFixed(2) }} vs {{ violation.limit.toFixed(2) }})
              </li>
            </ul>
          </div>

          <BaseInput
            v-if="precheckResult?.requires_override_reason"
            v-model="form.risk_override_reason"
            class="mt-3"
            label="Risk Override Reason"
            multiline
            :rows="2"
            placeholder="Required by policy to override risk limits..."
          />
        </section>

        <section class="trade-form-section execution-long-section">
          <p class="trade-section-title">Notes</p>
          <BaseInput
            v-model="form.notes"
            label="Execution Notes"
            multiline
            :rows="3"
            placeholder="Context, setup quality, execution notes..."
          />
        </section>

        <TradeImageUploader
          title="Screenshots (Optional)"
          :existing-images="existingImages"
          :pending-images="pendingImages"
          :offline-warning="isFallbackMode"
          :max-files="MAX_IMAGE_COUNT"
          :uploading="uploadingImages"
          :upload-progress="uploadProgressByPendingId"
          :deleting-image-ids="deletingImageIds"
          :error="imageUploadError"
          @select-files="onSelectImageFiles"
          @remove-pending="removePendingImage"
          @remove-existing="removeExistingImage"
          @reorder-pending="reorderPendingImages"
        />

        <section class="trade-form-section execution-long-section">
          <details class="trade-estimate-details">
            <summary>Advanced</summary>
            <div class="mt-3 space-y-4">
              <div class="grid grid-premium md:grid-cols-2 xl:grid-cols-4">
                <BaseSelect v-model="form.emotion" label="Emotion" :options="emotionSelectOptions" />
                <BaseInput
                  v-model="form.commission"
                  label="Commission"
                  type="number"
                  min="0"
                  step="0.01"
                  :error="fieldError('commission')"
                />
                <BaseInput
                  v-model="form.swap"
                  label="Swap"
                  type="number"
                  step="0.01"
                />
                <BaseInput
                  v-model="form.spread_cost"
                  label="Spread Cost"
                  type="number"
                  min="0"
                  step="0.01"
                  :error="fieldError('spread_cost')"
                />
                <BaseInput
                  v-model="form.slippage_cost"
                  label="Slippage Cost"
                  type="number"
                  min="0"
                  step="0.01"
                  :error="fieldError('slippage_cost')"
                />
                <BaseInput v-model="psychology.pre_emotion" label="Pre Emotion" placeholder="calm / anxious" />
                <BaseInput v-model="psychology.post_emotion" label="Post Emotion" placeholder="confident / tilted" />
                <BaseInput
                  v-model="psychology.confidence_score"
                  label="Confidence (1-10)"
                  type="number"
                  min="1"
                  max="10"
                  step="1"
                />
                <BaseInput
                  v-model="psychology.stress_score"
                  label="Stress (1-10)"
                  type="number"
                  min="1"
                  max="10"
                  step="1"
                />
              </div>
              <BaseInput
                v-model="psychology.notes"
                label="Psychology Notes"
                multiline
                :rows="2"
                placeholder="State trigger, self-talk, discipline notes..."
              />
            </div>
          </details>
        </section>

        <div class="execution-sticky-bar">
          <span class="execution-status-chip" :class="riskStatusClass">{{ riskStatusLabel }}</span>
          <span v-if="hasAcceptedLocalRiskOverride" class="execution-status-chip is-blocked">
            Risk unverified (local-only)
          </span>
          <span v-if="showSoftChecklistNotice" class="text-xs muted">
            Soft mode: rules incomplete, execution allowed.
          </span>
          <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="router.push('/trades')">Cancel</button>
          <button
            type="button"
            class="btn btn-primary px-4 py-2 text-sm"
            :disabled="isSubmittingDisabled"
            :class="{ 'opacity-60': isChecklistStrictBlocked }"
            :title="isChecklistStrictBlocked ? 'Strict mode: blocked until server readiness is Ready.' : ''"
            @click="handleExecuteClick"
          >
            {{
              uploadingImages
                ? 'Uploading images...'
                : tradeStore.saving
                  ? 'Saving...'
                  : isChecklistStrictBlocked
                    ? 'Blocked by Rules'
                    : isEditMode ? 'Update Execute' : 'Save Execute'
            }}
          </button>
        </div>
        </form>

        <TradeRulesPanel
          mode="desktop"
          :checklist="activeChecklist"
          :required-items="checklistRequiredItems"
          :optional-items="checklistOptionalItems"
          :archived-responses="checklistArchivedResponses"
          :readiness="checklistReadiness"
          :server-readiness="checklistServerReadiness"
          :server-readiness-mismatch="checklistServerReadinessMismatch"
          :server-readiness-reasons="checklistServerReadinessReasons"
          :execution-snapshot="checklistExecutionSnapshot"
          :loading="checklistLoading"
          :saving="checklistSaving"
          :submit-attempted="checklistSubmitAttempted || submitAttempted"
          :strict-mode="checklistStrictMode"
          :risk-precheck="precheckResult"
          @update-response="onChecklistResponseChange"
          @evaluation-change="onChecklistEvaluationChange"
        />
      </div>
    </GlassPanel>
  </div>
</template>
