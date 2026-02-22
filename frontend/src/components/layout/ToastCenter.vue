<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { CheckCircle2, Info, XCircle } from 'lucide-vue-next'
import { useUiStore } from '@/stores/uiStore'

const uiStore = useUiStore()
const { toasts } = storeToRefs(uiStore)
</script>

<template>
  <div class="toast-center pointer-events-none fixed right-4 top-4 flex w-full max-w-sm flex-col gap-2">
    <TransitionGroup name="toast">
      <div
        v-for="item in toasts"
        :key="item.id"
        class="panel pointer-events-auto rounded-xl px-4 py-3"
        :class="{
          'toast-success': item.type === 'success',
          'toast-error': item.type === 'error',
          'toast-info': item.type === 'info',
        }"
      >
        <div class="flex items-start gap-2 text-[var(--text)]">
          <CheckCircle2 v-if="item.type === 'success'" class="mt-0.5 h-4 w-4 text-[var(--primary)]" />
          <XCircle v-else-if="item.type === 'error'" class="mt-0.5 h-4 w-4 text-[var(--danger)]" />
          <Info v-else class="mt-0.5 h-4 w-4 text-[var(--muted)]" />
          <div>
            <p class="text-sm font-semibold">{{ item.title }}</p>
            <p v-if="item.message" class="text-xs muted">{{ item.message }}</p>
          </div>
        </div>
      </div>
    </TransitionGroup>
  </div>
</template>
