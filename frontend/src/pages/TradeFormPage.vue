<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import type { AxiosError } from 'axios'
import { ArrowLeft } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseSelect from '@/components/form/BaseSelect.vue'
import BaseDateTime from '@/components/form/BaseDateTime.vue'
import TradeImageUploader from '@/components/trades/TradeImageUploader.vue'
import { useAccountStore } from '@/stores/accountStore'
import { useTradeStore, type TradePayload } from '@/stores/tradeStore'
import { useUiStore } from '@/stores/uiStore'
import type { Trade, TradeEmotion, TradeImage } from '@/types/trade'
import { asCurrency, asSignedCurrency } from '@/utils/format'

const router = useRouter()
const route = useRoute()
const tradeStore = useTradeStore()
const accountStore = useAccountStore()
const uiStore = useUiStore()
const { accounts } = storeToRefs(accountStore)

const loadingTrade = ref(false)
const submitAttempted = ref(false)
const showAdvanced = ref(false)
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
    badge: account.account_type,
    keywords: [account.broker, account.account_type, account.currency],
  }))
)

const form = reactive({
  account_id: '',
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
  followed_rules: true,
  emotion: 'neutral' as TradeEmotion,
  notes: '',
})

interface PendingTradeImage {
  id: string
  file: File
  preview_url: string
}

const MAX_IMAGE_COUNT = 5
const MAX_IMAGE_SIZE_BYTES = 5 * 1024 * 1024
const MAX_TOTAL_IMAGE_BYTES = 20 * 1024 * 1024
const allowedImageTypes = new Set([
  'image/jpeg',
  'image/jpg',
  'image/png',
  'image/webp',
])

const existingImages = ref<TradeImage[]>([])
const pendingImages = ref<PendingTradeImage[]>([])
const imageUploadError = ref('')
const uploadingImages = ref(false)
const deletingImageIds = ref<number[]>([])
const uploadProgressByPendingId = ref<Record<string, number>>({})

const tradeId = computed(() => {
  const value = Number(route.params.id)
  return Number.isInteger(value) && value > 0 ? value : null
})
const isEditMode = computed(() => tradeId.value !== null)
const pageTitle = computed(() => (isEditMode.value ? 'Edit Execution' : 'New Execution'))
const tradeFormId = 'trade-execution-form'
const closeDateMax = computed(() => maxDateTime(nowLocalDateTime(), form.date || ''))
const totalImageCount = computed(() => existingImages.value.length + pendingImages.value.length)
const totalImageSize = computed(() => {
  const existingTotal = existingImages.value.reduce((sum, image) => sum + Number(image.file_size || 0), 0)
  const pendingTotal = pendingImages.value.reduce((sum, image) => sum + image.file.size, 0)
  return existingTotal + pendingTotal
})

const liveEstimate = computed(() => {
  const entry = toNumber(form.entry_price)
  const stop = toNumber(form.stop_loss)
  const take = toNumber(form.take_profit)
  const exit = toNumber(form.actual_exit_price)
  const lotSize = toNumber(form.position_size)

  if (!(entry > 0) || !(stop > 0) || !(take > 0) || !(exit > 0) || !(lotSize > 0)) {
    return {
      ready: false as const,
      error: '',
      riskAmount: 0,
      targetPnl: 0,
      livePnl: 0,
      rAtExit: 0,
      rrPlan: 0,
    }
  }

  const direction = form.direction === 'buy' ? 1 : -1
  const riskMove = (entry - stop) * direction
  const rewardMove = (take - entry) * direction

  if (!(riskMove > 0)) {
    return {
      ready: false as const,
      error: 'Stop loss placement is invalid for the selected direction.',
      riskAmount: 0,
      targetPnl: 0,
      livePnl: 0,
      rAtExit: 0,
      rrPlan: 0,
    }
  }

  const positionUnits = lotSize
  const liveMove = (exit - entry) * direction
  const riskAmount = riskMove * positionUnits
  const targetPnl = rewardMove * positionUnits
  const livePnl = liveMove * positionUnits
  const rrPlan = rewardMove / riskMove
  const rAtExit = riskAmount !== 0 ? livePnl / riskAmount : 0

  return {
    ready: true as const,
    error: '',
    riskAmount,
    targetPnl,
    livePnl,
    rAtExit,
    rrPlan,
  }
})

type WeeklyLoopStage = 'capture' | 'triage' | 'action_plan' | 'follow_up'

const notesLength = computed(() => form.notes.trim().length)
const captureComplete = computed(() => {
  return form.symbol.trim().length > 0
    && toNumber(form.entry_price) > 0
    && toNumber(form.stop_loss) > 0
    && toNumber(form.take_profit) > 0
})
const triageComplete = computed(() => {
  return form.emotion !== 'neutral'
    || form.followed_rules === false
    || notesLength.value >= 24
})
const actionPlanComplete = computed(() => /action plan|next time|i will|prevent|if setup repeats/i.test(form.notes))
const followUpComplete = computed(() => /follow[\s-]?up|revisit|check back/i.test(form.notes))
const weeklyLoopScore = computed(() => {
  const checks = [captureComplete.value, triageComplete.value, actionPlanComplete.value, followUpComplete.value]
  return checks.filter(Boolean).length * 25
})
const suggestedFollowUpDate = computed(() => {
  const closeDate = parseLocalDateTime(form.date)
  const anchor = closeDate === null ? new Date() : new Date(closeDate)
  anchor.setDate(anchor.getDate() + 7)
  return anchor.toISOString().slice(0, 10)
})
const weeklyLoopSteps = computed(() => ([
  {
    id: 'capture',
    title: 'Capture',
    helper: 'Log setup facts and screenshot context.',
    done: captureComplete.value,
  },
  {
    id: 'triage',
    title: 'Triage',
    helper: 'State quality, mistakes, and rule status.',
    done: triageComplete.value,
  },
  {
    id: 'action_plan',
    title: 'Action Plan',
    helper: 'Write exactly what to do next time.',
    done: actionPlanComplete.value,
  },
  {
    id: 'follow_up',
    title: 'Follow-up',
    helper: `Set review date (${suggestedFollowUpDate.value}).`,
    done: followUpComplete.value,
  },
]))

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

function appendNotesBlock(title: string, lines: string[]) {
  if (form.notes.includes(`${title}:`)) {
    uiStore.toast({
      type: 'info',
      title: `${title} already added`,
      message: 'Update the existing section instead of duplicating it.',
    })
    return
  }

  const block = `${title}:\n${lines.join('\n')}`
  const current = form.notes.trim()
  form.notes = current ? `${current}\n\n${block}` : block
}

function insertWeeklyLoopTemplate(stage: WeeklyLoopStage) {
  if (stage === 'capture') {
    appendNotesBlock('Capture', [
      '- Setup:',
      '- Trigger:',
      '- Market context:',
    ])
    return
  }

  if (stage === 'triage') {
    appendNotesBlock('Triage', [
      '- Setup quality (1-5):',
      '- Rule adherence:',
      '- Main execution mistake:',
    ])
    return
  }

  if (stage === 'action_plan') {
    appendNotesBlock('Action Plan', [
      '- If setup repeats, I will:',
      '- Invalidation condition:',
      '- Risk cap:',
    ])
    return
  }

  appendNotesBlock('Follow-up', [
    `- Review date: ${suggestedFollowUpDate.value}`,
    '- Check if plan was followed:',
    '- Adjustment:',
  ])
}

function parseLocalDateTime(value: string): number | null {
  if (!value) return null
  const timestamp = new Date(value).getTime()
  if (Number.isNaN(timestamp)) return null
  return timestamp
}

function setFormFromTrade(trade: Trade) {
  form.account_id = String(trade.account_id || '')
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
  form.followed_rules = Boolean(trade.followed_rules)
  form.emotion = (trade.emotion ?? 'neutral') as TradeEmotion
  form.notes = trade.notes || ''
  showAdvanced.value = Boolean(
    form.session.trim()
    || form.model.trim()
    || form.emotion !== 'neutral'
    || form.followed_rules === false
  )
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

  if (!form.account_id) {
    errors.account_id = 'Account is required.'
  }

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
    account_id: Number(form.account_id),
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
    followed_rules: form.followed_rules,
    emotion: form.emotion,
    notes: form.notes.trim() ? form.notes.trim() : null,
  }
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
      imageUploadError.value = 'Only jpg, jpeg, png, and webp files are allowed.'
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

async function submitForm() {
  submitAttempted.value = true
  const firstError = Object.values(formErrors.value)[0]
  if (firstError) {
    uiStore.toast({
      type: 'error',
      title: 'Invalid execution input',
      message: firstError,
    })
    return
  }

  try {
    const payload = buildPayload()
    const hadPendingImages = pendingImages.value.length > 0
    let savedTrade: Trade

    if (isEditMode.value && tradeId.value !== null) {
      savedTrade = await tradeStore.updateTrade(tradeId.value, payload)
    } else {
      savedTrade = await tradeStore.addTrade(payload)
    }

    await uploadPendingImages(savedTrade)

    uiStore.toast({
      type: 'success',
      title: isEditMode.value ? 'Execution updated' : 'Execution logged',
      message: hadPendingImages
        ? `${payload.symbol} saved with images.`
        : `${payload.symbol} has been saved to your execution journal.`,
    })

    void router.push('/trades')
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Failed to save execution',
      message: extractErrorMessage(error),
    })
  }
}

async function loadTradeIfNeeded() {
  if (!isEditMode.value || tradeId.value === null) {
    form.date = nowLocalDateTime()
    showAdvanced.value = false
    return
  }

  loadingTrade.value = true
  try {
    const data = await tradeStore.fetchTradeDetails(tradeId.value)
    setFormFromTrade(data.trade)
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

onMounted(async () => {
  try {
    await accountStore.fetchAccounts()
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load accounts',
      message: 'Please refresh and try again.',
    })
  }

  if (!isEditMode.value && !form.account_id && accountSelectOptions.value.length > 0) {
    form.account_id = accountSelectOptions.value[0]?.value ?? ''
  }

  applyQuickDefaultsFromQuery()
  await loadTradeIfNeeded()
})

onBeforeUnmount(() => {
  clearPendingImages()
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
  <div class="space-y-4 trade-form-minimal">
    <GlassPanel class="form-command-shell">
      <div class="form-command-bar">
        <div class="form-command-left">
          <h2 class="section-title">{{ pageTitle }}</h2>
          <p class="section-note">Minimal execution ticket with server-calculated results.</p>
          <div class="form-command-chips">
            <span class="filter-chip-mini">Entry</span>
            <span class="filter-chip-mini">Exit</span>
            <span class="filter-chip-mini">Notes</span>
            <span class="filter-chip-mini">Images</span>
          </div>
        </div>
        <div class="form-command-right">
          <button type="button" class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="router.push('/trades')">
            <ArrowLeft class="h-4 w-4" />
            Back
          </button>
          <button
            type="submit"
            :form="tradeFormId"
            class="btn btn-primary px-4 py-2 text-sm"
            :disabled="tradeStore.saving || uploadingImages || loadingTrade"
          >
            {{
              uploadingImages
                ? 'Uploading...'
                : tradeStore.saving
                  ? 'Saving...'
                  : isEditMode ? 'Update' : 'Save'
            }}
          </button>
        </div>
      </div>
    </GlassPanel>

    <GlassPanel class="form-shell-panel">

      <div v-if="loadingTrade" class="space-y-3">
        <div class="skeleton-shimmer h-12 rounded-xl" />
        <div class="skeleton-shimmer h-12 rounded-xl" />
        <div class="skeleton-shimmer h-12 rounded-xl" />
      </div>

      <form v-else :id="tradeFormId" class="form-block space-y-4" @submit.prevent="submitForm">
        <p class="trade-form-disclaimer">
          P/L, risk, and R are recalculated server-side on save.
        </p>

        <section class="trade-form-section">
          <p class="trade-section-title">Entry</p>
          <div class="grid grid-premium md:grid-cols-2 xl:grid-cols-3">
            <BaseDateTime
              v-model="form.date"
              label="Date"
              required
              :max="closeDateMax"
              :error="fieldError('date')"
            />
            <BaseSelect
              v-model="form.account_id"
              label="Account"
              required
              searchable
              search-placeholder="Search account..."
              :options="accountSelectOptions"
              :error="fieldError('account_id')"
            />
            <BaseInput
              v-model="form.symbol"
              label="Symbol"
              required
              placeholder="EURUSD"
              :error="fieldError('symbol')"
            />
            <BaseSelect v-model="form.direction" label="Direction" :options="directionOptions" />
            <BaseInput
              v-model="form.entry_price"
              label="Entry Price"
              type="number"
              required
              min="0.000001"
              step="0.000001"
              :error="fieldError('entry_price')"
            />
            <BaseInput
              v-model="form.position_size"
              label="Position Size (Lot)"
              type="number"
              required
              min="0.0001"
              step="0.0001"
              :error="fieldError('position_size')"
            />
          </div>

          <div class="mt-3">
            <button type="button" class="btn btn-ghost px-3 py-1.5 text-xs" @click="showAdvanced = !showAdvanced">
              {{ showAdvanced ? 'Hide Advanced' : 'Advanced' }}
            </button>
          </div>

          <Transition name="fade">
            <div v-if="showAdvanced" class="mt-3 grid grid-premium md:grid-cols-2 xl:grid-cols-3">
              <BaseSelect v-model="form.emotion" label="Emotion" :options="emotionSelectOptions" />
              <BaseInput v-model="form.session" label="Session (Optional)" placeholder="London" />
              <BaseInput v-model="form.model" label="Strategy Model (Optional)" placeholder="Liquidity Sweep" />
              <label class="trade-checkbox-label">
                <input v-model="form.followed_rules" type="checkbox" class="h-4 w-4" />
                Followed Rules
              </label>
            </div>
          </Transition>
        </section>

        <section class="trade-form-section">
          <p class="trade-section-title">Exit</p>
          <div class="grid grid-premium md:grid-cols-2 xl:grid-cols-3">
            <BaseInput
              v-model="form.stop_loss"
              label="Stop Loss"
              type="number"
              required
              min="0.000001"
              step="0.000001"
              :error="fieldError('stop_loss')"
            />
            <BaseInput
              v-model="form.take_profit"
              label="Take Profit"
              type="number"
              required
              min="0.000001"
              step="0.000001"
              :error="fieldError('take_profit')"
            />
            <BaseInput
              v-model="form.actual_exit_price"
              label="Exit Price"
              type="number"
              required
              min="0.000001"
              step="0.000001"
              :error="fieldError('actual_exit_price')"
            />
          </div>
        </section>

        <section class="trade-form-section trade-estimate-quiet">
          <div class="section-head">
            <p class="trade-section-title">Live Estimate</p>
            <span class="section-note">Preview only</span>
          </div>
          <p v-if="liveEstimate.error" class="field-error-text">{{ liveEstimate.error }}</p>
          <div v-else-if="liveEstimate.ready" class="trade-preview-stack">
            <article class="trade-preview-primary">
              <p>Live P/L</p>
              <strong :class="liveEstimate.livePnl >= 0 ? 'positive' : 'negative'">
                {{ asSignedCurrency(liveEstimate.livePnl) }}
              </strong>
            </article>

            <details class="trade-estimate-details">
              <summary>Show plan details</summary>
              <div class="trade-preview-secondary">
                <article>
                  <p>Risk</p>
                  <strong class="negative">{{ asSignedCurrency(-Math.abs(liveEstimate.riskAmount)) }}</strong>
                </article>
                <article>
                  <p>Target P/L</p>
                  <strong :class="liveEstimate.targetPnl >= 0 ? 'positive' : 'negative'">
                    {{ asSignedCurrency(liveEstimate.targetPnl) }}
                  </strong>
                </article>
                <article>
                  <p>R @ Exit</p>
                  <strong class="value-display">{{ liveEstimate.rAtExit.toFixed(2) }}R</strong>
                </article>
                <article>
                  <p>Plan R:R</p>
                  <strong class="value-display">{{ liveEstimate.rrPlan.toFixed(2) }}R</strong>
                </article>
              </div>
            </details>
          </div>
          <p v-else class="section-note">Enter Entry, Stop Loss, Take Profit, Exit Price, and Position Size to preview.</p>
        </section>

        <section class="trade-form-section trade-workflow-loop">
          <div class="section-head">
            <p class="trade-section-title">Weekly Review Loop</p>
            <span class="section-note">Capture -> Triage -> Action Plan -> Follow-up</span>
          </div>

          <div class="trade-loop-grid">
            <article v-for="step in weeklyLoopSteps" :key="step.id" class="trade-loop-step" :class="{ 'is-done': step.done }">
              <p class="trade-loop-step-title">{{ step.title }}</p>
              <p class="section-note">{{ step.helper }}</p>
              <span class="trade-loop-step-status">{{ step.done ? 'Ready' : 'Pending' }}</span>
            </article>
          </div>

          <div class="trade-loop-actions">
            <button type="button" class="chip-btn" @click="insertWeeklyLoopTemplate('capture')">Capture</button>
            <button type="button" class="chip-btn" @click="insertWeeklyLoopTemplate('triage')">Triage</button>
            <button type="button" class="chip-btn" @click="insertWeeklyLoopTemplate('action_plan')">Action Plan</button>
            <button type="button" class="chip-btn" @click="insertWeeklyLoopTemplate('follow_up')">Follow-up</button>
            <span class="section-note">Loop score: {{ weeklyLoopScore }}/100</span>
          </div>
        </section>

        <section class="trade-form-section">
          <p class="trade-section-title">Notes</p>
          <p class="section-note">Use the loop templates to keep weekly review consistent.</p>
          <BaseInput
            v-model="form.notes"
            label="Execution Notes"
            multiline
            :rows="3"
            placeholder="Context, setup quality, execution notes..."
          />
        </section>

        <TradeImageUploader
          :existing-images="existingImages"
          :pending-images="pendingImages"
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

        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="router.push('/trades')">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 py-2 text-sm" :disabled="tradeStore.saving || uploadingImages">
            {{
              uploadingImages
                ? 'Uploading images...'
                : tradeStore.saving
                  ? 'Saving...'
                  : isEditMode ? 'Update Execution' : 'Save Execution'
            }}
          </button>
        </div>
      </form>
    </GlassPanel>
  </div>
</template>
