<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { AlertTriangle } from 'lucide-vue-next'
import { useUiStore } from '@/stores/uiStore'

const uiStore = useUiStore()
const { confirm } = storeToRefs(uiStore)
</script>

<template>
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition duration-150 ease-in"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="confirm.open"
      class="confirm-overlay fixed inset-0 flex items-center justify-center bg-black/40 px-4 backdrop-blur-sm"
    >
      <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 scale-[0.98] opacity-0"
        enter-to-class="translate-y-0 scale-100 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 scale-100 opacity-100"
        leave-to-class="translate-y-2 scale-[0.98] opacity-0"
      >
        <div class="panel w-full max-w-md p-6">
          <div class="mb-3 flex items-center gap-2">
            <AlertTriangle class="h-4 w-4 text-[var(--warning)]" />
            <h3 class="text-base font-bold">{{ confirm.title }}</h3>
          </div>
          <p class="text-sm muted">{{ confirm.message }}</p>
          <div class="mt-6 flex justify-end gap-2">
            <button class="btn btn-ghost px-4 py-2 text-sm" @click="uiStore.closeConfirmation(false)">
              {{ confirm.cancelText }}
            </button>
            <button
              class="btn px-4 py-2 text-sm"
              :class="confirm.danger ? 'btn-secondary' : 'btn-primary'"
              @click="uiStore.closeConfirmation(true)"
            >
              {{ confirm.confirmText }}
            </button>
          </div>
        </div>
      </Transition>
    </div>
  </Transition>
</template>
