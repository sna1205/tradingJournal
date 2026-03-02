<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { GripVertical, ImagePlus, Loader2, Trash2, UploadCloud } from 'lucide-vue-next'
import type { ImageContextTag, TradeImage } from '@/types/trade'

export interface PendingTradeImage {
  id: string
  file: File
  preview_url: string
  context_tag: ImageContextTag
  timeframe: string
  annotation_notes: string
}

const props = withDefaults(
  defineProps<{
    existingImages: TradeImage[]
    pendingImages: PendingTradeImage[]
    uploading?: boolean
    maxFiles?: number
    uploadProgress?: Record<string, number>
    deletingImageIds?: number[]
    error?: string
    title?: string
    uploadHint?: string
  }>(),
  {
    uploading: false,
    maxFiles: 5,
    uploadProgress: () => ({}),
    deletingImageIds: () => [],
    error: '',
    title: 'Execution Screenshots',
    uploadHint: 'Max 5 images - jpg, jpeg, png, webp, bmp - 5MB each - paste with Ctrl+V',
  }
)

const emit = defineEmits<{
  (event: 'select-files', files: File[]): void
  (event: 'remove-pending', id: string): void
  (event: 'remove-existing', id: number): void
  (event: 'reorder-pending', payload: { from: number; to: number }): void
}>()

const inputRef = ref<HTMLInputElement | null>(null)
const isHovering = ref(false)
const dragIndex = ref<number | null>(null)

const totalImages = computed(() => props.existingImages.length + props.pendingImages.length)
const canSelectMore = computed(() => totalImages.value < props.maxFiles)

function openPicker() {
  if (!canSelectMore.value || props.uploading) return
  inputRef.value?.click()
}

function onInputChange(event: Event) {
  const target = event.target as HTMLInputElement
  const files = target.files ? Array.from(target.files) : []
  if (files.length > 0) {
    emit('select-files', files)
  }

  target.value = ''
}

async function onPaste(event: ClipboardEvent) {
  if (!canSelectMore.value || props.uploading) return

  const files = await extractImageFilesFromClipboard(event.clipboardData)
  if (files.length === 0) return

  event.preventDefault()
  emit('select-files', files)
}

function onDrop(event: DragEvent) {
  event.preventDefault()
  isHovering.value = false
  if (!canSelectMore.value || props.uploading) return

  const files = event.dataTransfer?.files ? Array.from(event.dataTransfer.files) : []
  if (files.length > 0) {
    emit('select-files', files)
  }
}

function onDragOver(event: DragEvent) {
  event.preventDefault()
  if (props.uploading) return
  isHovering.value = true
}

function onDragLeave(event: DragEvent) {
  event.preventDefault()
  isHovering.value = false
}

function onPendingDragStart(index: number) {
  dragIndex.value = index
}

function onPendingDrop(index: number) {
  if (dragIndex.value === null || dragIndex.value === index) {
    dragIndex.value = null
    return
  }

  emit('reorder-pending', {
    from: dragIndex.value,
    to: index,
  })
  dragIndex.value = null
}

function isDeleting(imageId: number) {
  return props.deletingImageIds.includes(imageId)
}

async function extractImageFilesFromClipboard(clipboardData: DataTransfer | null): Promise<File[]> {
  const files: File[] = []
  const seenSignatures = new Set<string>()
  const timestamp = Date.now()
  let index = 0

  const fileList = clipboardData?.files ? Array.from(clipboardData.files) : []
  const items = clipboardData?.items ? Array.from(clipboardData.items) : []

  for (const file of fileList) {
    if (!file.type.startsWith('image/')) continue
    if (isDuplicateClipboardFile(file, seenSignatures)) continue
    files.push(normalizeClipboardFile(file, timestamp, index))
    index += 1
  }

  for (const item of items) {
    if (item.kind !== 'file' || !item.type.startsWith('image/')) continue
    const file = item.getAsFile()
    if (!file) continue

    if (isDuplicateClipboardFile(file, seenSignatures)) continue
    files.push(normalizeClipboardFile(file, timestamp, index))
    index += 1
  }

  if (files.length === 0) {
    const dataUrl = extractImageDataUrlFromClipboard(clipboardData)
    if (dataUrl) {
      const file = dataUrlToFile(dataUrl, timestamp, index)
      if (file && !isDuplicateClipboardFile(file, seenSignatures)) {
        files.push(file)
        index += 1
      }
    }
  }

  if (files.length === 0) {
    const clipboardApiFiles = await readClipboardApiImages(timestamp, index)
    for (const file of clipboardApiFiles) {
      if (isDuplicateClipboardFile(file, seenSignatures)) continue
      files.push(file)
      index += 1
    }
  }

  return files
}

function extractImageDataUrlFromClipboard(clipboardData: DataTransfer | null): string | null {
  if (!clipboardData) return null

  const html = clipboardData.getData('text/html') || ''
  const htmlMatch = html.match(/src=["'](data:image\/[^"']+)["']/i)
  if (htmlMatch?.[1]) return htmlMatch[1]

  const plainText = clipboardData.getData('text/plain') || ''
  const normalized = plainText.trim()
  if (normalized.startsWith('data:image/')) return normalized

  return null
}

function dataUrlToFile(dataUrl: string, timestamp: number, index: number): File | null {
  const match = dataUrl.match(/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/)
  if (!match?.[1] || !match?.[2]) return null

  try {
    const mimeType = match[1]
    const base64Payload = match[2]
    const binary = atob(base64Payload)
    const bytes = new Uint8Array(binary.length)

    for (let i = 0; i < binary.length; i += 1) {
      bytes[i] = binary.charCodeAt(i)
    }

    const extension = extensionForMimeType(mimeType)
    const name = `pasted-image-${timestamp}-${index}.${extension}`
    return new File([bytes], name, { type: mimeType, lastModified: Date.now() })
  } catch {
    return null
  }
}

async function readClipboardApiImages(timestamp: number, startIndex: number): Promise<File[]> {
  if (!('clipboard' in navigator) || !navigator.clipboard?.read) return []

  try {
    const items = await navigator.clipboard.read()
    const files: File[] = []
    let index = startIndex

    for (const item of items) {
      const imageType = item.types.find((type) => type.startsWith('image/'))
      if (!imageType) continue

      const blob = await item.getType(imageType)
      const extension = extensionForMimeType(imageType)
      const name = `pasted-image-${timestamp}-${index}.${extension}`
      files.push(new File([blob], name, { type: imageType, lastModified: Date.now() }))
      index += 1
    }

    return files
  } catch {
    return []
  }
}

function isDuplicateClipboardFile(file: File, seenSignatures: Set<string>): boolean {
  const signature = `${file.type}|${file.size}|${file.lastModified}`
  if (seenSignatures.has(signature)) return true
  seenSignatures.add(signature)
  return false
}

function normalizeClipboardFile(file: File, timestamp: number, index: number): File {
  const extension = extensionForMimeType(file.type)
  const generatedName = `pasted-image-${timestamp}-${index}.${extension}`
  const fileName = file.name && file.name.trim().length > 0 ? file.name : generatedName

  return new File([file], fileName, {
    type: file.type,
    lastModified: Date.now(),
  })
}

function extensionForMimeType(mimeType: string): string {
  if (mimeType === 'image/png') return 'png'
  if (mimeType === 'image/jpeg' || mimeType === 'image/jpg') return 'jpg'
  if (mimeType === 'image/webp') return 'webp'
  if (mimeType === 'image/bmp') return 'bmp'
  return 'png'
}

onMounted(() => {
  document.addEventListener('paste', onPaste, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('paste', onPaste, true)
})
</script>

<template>
  <section class="trade-form-section">
    <div class="section-head">
      <p class="trade-section-title">{{ title }}</p>
      <span class="pill">{{ totalImages }} / {{ maxFiles }}</span>
    </div>

    <div
      class="trade-uploader-dropzone"
      :class="{ 'is-hovering': isHovering, 'is-disabled': !canSelectMore || uploading }"
      @click="openPicker"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <input
        ref="inputRef"
        type="file"
        class="hidden"
        accept=".jpg,.jpeg,.png,.webp,.bmp,image/jpeg,image/png,image/webp,image/bmp"
        multiple
        :disabled="!canSelectMore || uploading"
        @change="onInputChange"
      />
      <div class="trade-uploader-copy">
        <UploadCloud class="h-6 w-6" />
        <p class="font-semibold">Drag &amp; drop screenshots here</p>
        <p class="text-sm muted">or click to browse</p>
        <p class="text-xs muted">{{ uploadHint }}</p>
      </div>
      <ImagePlus class="h-5 w-5 trade-uploader-corner-icon" />
    </div>

    <p v-if="error" class="field-error-text">{{ error }}</p>

    <div v-if="existingImages.length > 0 || pendingImages.length > 0" class="trade-image-grid">
      <article
        v-for="image in existingImages"
        :key="`existing-${image.id}`"
        class="trade-image-card"
      >
        <img
          :src="image.thumbnail_url || image.image_url"
          alt="Execution screenshot"
          loading="lazy"
          class="trade-image-thumb"
        />
        <div class="trade-image-card-top">
          <span class="pill">Saved</span>
          <button
            type="button"
            class="btn btn-ghost is-danger p-1.5"
            :disabled="uploading || isDeleting(image.id)"
            @click.stop="emit('remove-existing', image.id)"
          >
            <Loader2 v-if="isDeleting(image.id)" class="h-3.5 w-3.5 animate-spin" />
            <Trash2 v-else class="h-3.5 w-3.5" />
          </button>
        </div>
      </article>

      <article
        v-for="(image, index) in pendingImages"
        :key="`pending-${image.id}`"
        class="trade-image-card"
        draggable="true"
        @dragstart="onPendingDragStart(index)"
        @dragover.prevent
        @drop.prevent="onPendingDrop(index)"
      >
        <img
          :src="image.preview_url"
          alt="Pending execution screenshot"
          loading="lazy"
          class="trade-image-thumb"
        />
        <div class="trade-image-card-top">
          <span class="pill pill-positive inline-flex items-center gap-1">
            <GripVertical class="h-3 w-3" />
            Pending
          </span>
          <button
            type="button"
            class="btn btn-ghost is-danger p-1.5"
            :disabled="uploading"
            @click.stop="emit('remove-pending', image.id)"
          >
            <Trash2 class="h-3.5 w-3.5" />
          </button>
        </div>

        <div v-if="uploading" class="trade-image-progress">
          <div
            class="trade-image-progress-bar"
            :style="{ width: `${Math.max(0, Math.min(100, uploadProgress[image.id] ?? 0))}%` }"
          />
        </div>
      </article>
    </div>
  </section>
</template>
