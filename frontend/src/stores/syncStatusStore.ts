import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

type SyncMode = 'server' | 'fallback'

export const useSyncStatusStore = defineStore('sync-status', () => {
  const mode = ref<SyncMode>('server')
  const lastFallbackAt = ref<string | null>(null)
  const lastFallbackContext = ref<string | null>(null)
  const lastRecoveredAt = ref<string | null>(null)

  const isFallbackMode = computed(() => mode.value === 'fallback')

  function markServerHealthy() {
    if (mode.value === 'fallback') {
      lastRecoveredAt.value = new Date().toISOString()
    }
    mode.value = 'server'
  }

  function markLocalFallback(context: string) {
    mode.value = 'fallback'
    lastFallbackAt.value = new Date().toISOString()
    lastFallbackContext.value = context
  }

  return {
    mode,
    isFallbackMode,
    lastFallbackAt,
    lastFallbackContext,
    lastRecoveredAt,
    markServerHealthy,
    markLocalFallback,
  }
})
