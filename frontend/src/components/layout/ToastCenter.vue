<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { CheckCircle2, Info, XCircle } from 'lucide-vue-next'
import { useUiStore } from '@/stores/uiStore'

const uiStore = useUiStore()
const { toasts } = storeToRefs(uiStore)
</script>

<template>
  <div class="pointer-events-none fixed right-4 top-4 z-[70] flex w-full max-w-sm flex-col gap-2">
    <TransitionGroup name="toast">
      <div
        v-for="item in toasts"
        :key="item.id"
        class="glass-card pointer-events-auto rounded-2xl border px-4 py-3"
        :class="{
          'border-emerald-400/50 bg-emerald-950/35 text-emerald-100': item.type === 'success',
          'border-rose-400/50 bg-rose-950/30 text-rose-100': item.type === 'error',
          'border-slate-600 bg-slate-900/75 text-slate-100': item.type === 'info',
        }"
      >
        <div class="flex items-start gap-2">
          <CheckCircle2 v-if="item.type === 'success'" class="mt-0.5 h-4 w-4" />
          <XCircle v-else-if="item.type === 'error'" class="mt-0.5 h-4 w-4" />
          <Info v-else class="mt-0.5 h-4 w-4" />
          <div>
            <p class="text-sm font-semibold">{{ item.title }}</p>
            <p v-if="item.message" class="text-xs opacity-90">{{ item.message }}</p>
          </div>
        </div>
      </div>
    </TransitionGroup>
  </div>
</template>
