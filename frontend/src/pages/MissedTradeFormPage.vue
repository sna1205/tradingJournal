<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { AxiosError } from 'axios'
import { ArrowLeft, Plus, Trash2, X } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseDateTime from '@/components/form/BaseDateTime.vue'
import TradeImageUploader from '@/components/trades/TradeImageUploader.vue'
import { useMissedTradeStore, type MissedTradePayload } from '@/stores/missedTradeStore'
import { useUiStore } from '@/stores/uiStore'
import type { MissedTrade, MissedTradeImage } from '@/types/trade'

const route = useRoute()
const router = useRouter()
const missedTradeStore = useMissedTradeStore()
const uiStore = useUiStore()

const loadingEntry = ref(false)
const submitAttempted = ref(false)
const customTag = ref('')

const reasonTagOptions = [
  'late-entry',
  'fear',
  'hesitation',
  'no-plan',
  'overtrading',
  'news-volatility',
  'session:london',
  'session:new-york',
  'session:asia',
]

const form = reactive({
  pair: '',
  model: '',
  date: '',
  notes: '',
  tags: [] as string[],
})

interface PendingMissedTradeImage {
  id: string
  file: File
  preview_url: string
  context_tag: 'pre_entry' | 'entry' | 'management' | 'exit' | 'post_review'
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
])

const existingImages = ref<MissedTradeImage[]>([])
const pendingImages = ref<PendingMissedTradeImage[]>([])
const imageUploadError = ref('')
const uploadingImages = ref(false)
const deletingImageIds = ref<number[]>([])
const uploadProgressByPendingId = ref<Record<string, number>>({})

const totalImageCount = computed(() => existingImages.value.length + pendingImages.value.length)
const totalImageSize = computed(() => {
  const existingTotal = existingImages.value.reduce((sum, image) => sum + Number(image.file_size || 0), 0)
  const pendingTotal = pendingImages.value.reduce((sum, image) => sum + image.file.size, 0)
  return existingTotal + pendingTotal
})

const missedTradeId = computed(() => {
  const value = Number(route.params.id)
  return Number.isInteger(value) && value > 0 ? value : null
})
const isEditMode = computed(() => missedTradeId.value !== null)
const pageTitle = computed(() => (isEditMode.value ? 'Edit Missed Setup' : 'Log Missed Setup'))
const missedSetupFormId = 'missed-setup-form'

const formErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}

  const pair = form.pair.trim().toUpperCase()
  const model = form.model.trim()
  const dateTimestamp = parseLocalDateTime(form.date)
  const now = Date.now()

  if (!pair) {
    errors.pair = 'Pair is required.'
  } else if (pair.length > 30) {
    errors.pair = 'Pair must be 30 characters or fewer.'
  }

  if (!model) {
    errors.model = 'Model is required.'
  } else if (model.length > 120) {
    errors.model = 'Model must be 120 characters or fewer.'
  }

  if (dateTimestamp === null) {
    errors.date = 'Date is required.'
  } else if (dateTimestamp > now + 60_000) {
    errors.date = 'Date cannot be in the future.'
  }

  if (form.tags.length === 0) {
    errors.tags = 'At least one reason tag is required.'
  }

  return errors
})

function fieldError(name: string) {
  return submitAttempted.value ? formErrors.value[name] : ''
}

function parseTags(reason: string): string[] {
  return reason
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)
}

function toLocalDateTime(value: string) {
  const date = new Date(value)
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

function nowLocalDateTime() {
  return toLocalDateTime(new Date().toISOString())
}

function parseLocalDateTime(value: string): number | null {
  if (!value) return null
  const timestamp = new Date(value).getTime()
  return Number.isNaN(timestamp) ? null : timestamp
}

function toggleTag(tag: string) {
  if (form.tags.includes(tag)) {
    form.tags = form.tags.filter((item) => item !== tag)
    return
  }

  form.tags = [...form.tags, tag]
}

function addCustomTag() {
  const value = customTag.value.trim().toLowerCase()
  if (!value || form.tags.includes(value)) return

  form.tags = [...form.tags, value]
  customTag.value = ''
}

function removeTag(tag: string) {
  form.tags = form.tags.filter((item) => item !== tag)
}

function setFormFromMissedTrade(entry: MissedTrade) {
  form.pair = entry.pair
  form.model = entry.model
  form.date = toLocalDateTime(entry.date)
  form.notes = entry.notes ?? ''
  form.tags = parseTags(entry.reason)
  existingImages.value = (entry.images ?? [])
    .slice()
    .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
}

function applyQuickDefaultsFromQuery() {
  if (isEditMode.value) return
  if (`${route.query.quick ?? ''}` !== '1') return

  const pair = `${route.query.pair ?? ''}`.trim().toUpperCase()
  const model = `${route.query.model ?? ''}`.trim()
  const reason = `${route.query.reason ?? ''}`.trim()

  if (pair) {
    form.pair = pair
  }
  if (model) {
    form.model = model
  }
  if (reason) {
    const parsed = parseTags(reason)
    if (parsed.length > 0) {
      form.tags = Array.from(new Set([...form.tags, ...parsed]))
    }
  }
}

function buildPayload(): MissedTradePayload {
  const dateTimestamp = parseLocalDateTime(form.date)
  if (dateTimestamp === null) {
    throw new Error('Date is invalid.')
  }

  return {
    pair: form.pair.trim().toUpperCase(),
    model: form.model.trim(),
    date: new Date(dateTimestamp).toISOString(),
    reason: form.tags.join(', '),
    notes: form.notes.trim() ? form.notes.trim() : null,
  }
}

function extractErrorMessage(error: unknown): string {
  const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>
  const responseMessage = axiosError.response?.data?.message
  const responseErrors = axiosError.response?.data?.errors
  const firstValidationError = responseErrors
    ? Object.values(responseErrors).flat().find((message) => Boolean(message))
    : null

  return firstValidationError || responseMessage || 'Please review values and try again.'
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

function reorderPendingImages(payload: { from: number; to: number }) {
  const { from, to } = payload
  if (from < 0 || to < 0 || from >= pendingImages.value.length || to >= pendingImages.value.length) return
  const items = pendingImages.value.slice()
  const [moved] = items.splice(from, 1)
  if (!moved) return
  items.splice(to, 0, moved)
  pendingImages.value = items
}

function updatePendingImageMetadata(payload: {
  id: string
  context_tag?: string
  timeframe?: string
  annotation_notes?: string
}) {
  pendingImages.value = pendingImages.value.map((image) => {
    if (image.id !== payload.id) return image
    return {
      ...image,
      context_tag: (payload.context_tag as PendingMissedTradeImage['context_tag'] | undefined) ?? image.context_tag,
      timeframe: payload.timeframe ?? image.timeframe,
      annotation_notes: payload.annotation_notes ?? image.annotation_notes,
    }
  })
}

async function removeExistingImage(imageId: number) {
  if (!isEditMode.value || missedTradeId.value === null) return
  if (deletingImageIds.value.includes(imageId)) return

  deletingImageIds.value = [...deletingImageIds.value, imageId]
  try {
    await missedTradeStore.deleteMissedTradeImage(imageId)
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

async function onSelectImageFiles(files: File[]) {
  imageUploadError.value = ''
  if (files.length === 0) return

  const availableSlots = MAX_IMAGE_COUNT - totalImageCount.value
  if (availableSlots <= 0) {
    imageUploadError.value = 'Maximum 5 images per missed trade allowed.'
    return
  }

  const selected = files.slice(0, availableSlots)
  const queued: PendingMissedTradeImage[] = []

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
    imageUploadError.value = 'Total image uploads per missed trade cannot exceed 20MB.'
    return
  }

  pendingImages.value = [...pendingImages.value, ...queued]
}

async function uploadPendingImages(entry: MissedTrade) {
  if (pendingImages.value.length === 0) return

  uploadingImages.value = true
  imageUploadError.value = ''

  try {
    const baseSort = existingImages.value.length
    const uploadedImages: MissedTradeImage[] = []

    for (let index = 0; index < pendingImages.value.length; index += 1) {
      const image = pendingImages.value[index]!
      uploadProgressByPendingId.value = {
        ...uploadProgressByPendingId.value,
        [image.id]: 0,
      }

      const uploaded = await missedTradeStore.uploadMissedTradeImage(
        entry.id,
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

async function submit() {
  submitAttempted.value = true
  const firstError = Object.values(formErrors.value)[0]
  if (firstError) {
    uiStore.toast({
      type: 'error',
      title: 'Invalid missed setup input',
      message: firstError,
    })
    return
  }

  try {
    const payload = buildPayload()
    const hadPendingImages = pendingImages.value.length > 0
    let savedEntry: MissedTrade

    if (isEditMode.value && missedTradeId.value !== null) {
      savedEntry = await missedTradeStore.updateMissedTrade(missedTradeId.value, payload)
      uiStore.toast({
        type: 'success',
        title: 'Missed setup updated',
      })
    } else {
      savedEntry = await missedTradeStore.createMissedTrade(payload)
      uiStore.toast({
        type: 'success',
        title: 'Missed setup logged',
      })
    }

    await uploadPendingImages(savedEntry)

    if (hadPendingImages) {
      uiStore.toast({
        type: 'success',
        title: 'Images uploaded',
        message: 'Screenshots were attached to this missed setup.',
      })
    }

    void router.push('/missed-trades')
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Failed to save missed setup',
      message: extractErrorMessage(error),
    })
  }
}

async function deleteEntry() {
  if (!isEditMode.value || missedTradeId.value === null) return

  const confirmed = await uiStore.askConfirmation({
    title: 'Delete missed setup entry?',
    message: 'This action cannot be undone.',
    confirmText: 'Delete',
    danger: true,
  })
  if (!confirmed) return

  try {
    await missedTradeStore.deleteMissedTrade(missedTradeId.value)
    uiStore.toast({
      type: 'success',
      title: 'Missed setup deleted',
    })
    void router.push('/missed-trades')
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Delete failed',
      message: extractErrorMessage(error),
    })
  }
}

async function loadEntryIfNeeded() {
  if (!isEditMode.value || missedTradeId.value === null) {
    form.date = nowLocalDateTime()
    return
  }

  loadingEntry.value = true
  try {
    const entry = await missedTradeStore.fetchMissedTrade(missedTradeId.value)
    setFormFromMissedTrade(entry)
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Missed setup not found',
      message: 'Could not load this entry for editing.',
    })
    void router.push('/missed-trades')
  } finally {
    loadingEntry.value = false
  }
}

onMounted(async () => {
  applyQuickDefaultsFromQuery()
  await loadEntryIfNeeded()
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
  <div class="space-y-4 missed-form-minimal">
    <GlassPanel class="form-command-shell">
      <div class="form-command-bar">
        <div class="form-command-left">
          <h2 class="section-title">{{ pageTitle }}</h2>
          <p class="section-note">Capture missed opportunities with clear reasons and screenshots.</p>
          <div class="form-command-chips">
            <span class="filter-chip-mini">Setup</span>
            <span class="filter-chip-mini">Tags</span>
            <span class="filter-chip-mini">Optional</span>
          </div>
        </div>
        <div class="form-command-right">
          <button type="button" class="btn btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm" @click="router.push('/missed-trades')">
            <ArrowLeft class="h-4 w-4" />
            Back
          </button>
          <button
            type="submit"
            :form="missedSetupFormId"
            class="btn btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm"
            :disabled="missedTradeStore.saving || uploadingImages || loadingEntry"
          >
            <Plus class="h-4 w-4" />
            {{
              uploadingImages
                ? 'Uploading...'
                : missedTradeStore.saving
                  ? 'Saving...'
                  : isEditMode ? 'Update' : 'Save'
            }}
          </button>
        </div>
      </div>
    </GlassPanel>

    <GlassPanel class="form-shell-panel">

      <div v-if="loadingEntry" class="space-y-3">
        <div class="skeleton-shimmer h-12 rounded-xl" />
        <div class="skeleton-shimmer h-12 rounded-xl" />
        <div class="skeleton-shimmer h-12 rounded-xl" />
      </div>

      <form v-else :id="missedSetupFormId" class="form-block space-y-4" @submit.prevent="submit">
        <section class="trade-form-section">
          <p class="trade-section-title">Setup Details</p>
          <div class="grid grid-premium md:grid-cols-2 xl:grid-cols-3">
            <BaseInput v-model="form.pair" label="Pair" required placeholder="EURUSD" :error="fieldError('pair')" />
            <BaseInput v-model="form.model" label="Model" required placeholder="Liquidity Sweep" :error="fieldError('model')" />
            <BaseDateTime v-model="form.date" label="Date" required :max="nowLocalDateTime()" :error="fieldError('date')" />
          </div>
        </section>

        <section class="trade-form-section">
          <p class="trade-section-title">Reason Tags</p>
          <div class="chip-row">
            <button
              v-for="tag in reasonTagOptions"
              :key="tag"
              type="button"
              class="chip-btn"
              :class="{ active: form.tags.includes(tag) }"
              @click="toggleTag(tag)"
            >
              {{ tag }}
            </button>
          </div>
          <p v-if="fieldError('tags')" class="field-error-text">{{ fieldError('tags') }}</p>
          <div class="form-field-shell mt-3">
            <span class="form-field-head">
              <span class="form-field-label">Custom Tag</span>
            </span>
            <div class="mt-2 flex gap-2">
              <input
                v-model="customTag"
                type="text"
                placeholder="discipline"
                class="field control-modern mt-0 w-full"
                @keydown.enter.prevent="addCustomTag"
              />
              <button type="button" class="btn btn-ghost px-3 text-xs" @click="addCustomTag">Add</button>
            </div>
          </div>
          <div v-if="form.tags.length > 0" class="chip-row mt-3">
            <span v-for="tag in form.tags" :key="`selected-${tag}`" class="pill">
              {{ tag }}
              <button type="button" class="inline-flex items-center text-[var(--muted)]" @click="removeTag(tag)">
                <X class="h-3 w-3" />
              </button>
            </span>
          </div>
        </section>

        <section class="trade-form-section">
          <p class="trade-section-title">Notes</p>
          <BaseInput v-model="form.notes" label="Notes" multiline :rows="4" />
        </section>

        <section class="trade-form-section">
          <details class="trade-estimate-details">
            <summary>Screenshots (Optional)</summary>
            <div class="mt-3">
              <TradeImageUploader
                title="Missed Setup Screenshots"
                upload-hint="Max 5 images - jpg, jpeg, png, webp - 5MB each"
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
                @update-pending-metadata="updatePendingImageMetadata"
              />
            </div>
          </details>
        </section>

        <div class="flex items-center justify-end gap-2">
          <button
            v-if="isEditMode"
            type="button"
            class="btn btn-ghost is-danger inline-flex items-center gap-2 px-4 py-2 text-sm"
            @click="deleteEntry"
          >
            <Trash2 class="h-4 w-4" />
            Delete
          </button>
          <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="router.push('/missed-trades')">Cancel</button>
          <button
            type="submit"
            class="btn btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm"
            :disabled="missedTradeStore.saving || uploadingImages"
          >
            <Plus class="h-4 w-4" />
            {{
              uploadingImages
                ? 'Uploading images...'
                : missedTradeStore.saving
                  ? 'Saving...'
                  : isEditMode ? 'Update Setup' : 'Save Setup'
            }}
          </button>
        </div>
      </form>
    </GlassPanel>
  </div>
</template>
