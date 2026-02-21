<script setup lang="ts">
import { computed, ref } from 'vue'
import { GripVertical, ImagePlus, Loader2, Trash2, UploadCloud } from 'lucide-vue-next'
import type { TradeImage } from '@/types/trade'

export interface PendingTradeImage {
  id: string
  file: File
  preview_url: string
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
    uploadHint: 'Max 5 images - jpg, jpeg, png, webp - 5MB each',
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
        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
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
            class="btn btn-danger p-1.5"
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
            class="btn btn-danger p-1.5"
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
