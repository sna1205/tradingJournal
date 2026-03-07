import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  upsertLocalAccountSnapshot,
  deleteLocalAccount,
  deleteLocalTrade,
  upsertLocalTradeSnapshot,
  setLocalAccountSyncStatus,
  setLocalTradeSyncStatus,
} from '@/services/localFallback'
import {
  clearSyncedQueueEntries,
  getSyncQueueSummary,
  readSyncQueue,
  replaySyncQueue,
  resolveSyncConflictDiscardLocal,
  resolveSyncConflictKeepLocal,
  resolveSyncConflictPullLatest,
  type SyncQueueItem,
  type SyncQueueSummary,
} from '@/services/offlineSyncQueue'
import type { Account } from '@/types/account'
import type { Trade } from '@/types/trade'

type SyncMode = 'server' | 'offline_draft'

const EMPTY_QUEUE_SUMMARY: SyncQueueSummary = {
  total: 0,
  unsynced: 0,
  draft_local: 0,
  pending_sync: 0,
  synced: 0,
  conflict: 0,
}

let syncListenersBound = false

export const useSyncStatusStore = defineStore('sync-status', () => {
  const mode = ref<SyncMode>('server')
  const lastFallbackAt = ref<string | null>(null)
  const lastFallbackContext = ref<string | null>(null)
  const lastRecoveredAt = ref<string | null>(null)
  const queueSummary = ref<SyncQueueSummary>({ ...EMPTY_QUEUE_SUMMARY })
  const queueItems = ref<SyncQueueItem[]>([])
  const syncing = ref(false)
  const lastSyncAt = ref<string | null>(null)
  const lastSyncError = ref<string | null>(null)

  const isFallbackMode = computed(() => mode.value === 'offline_draft')
  const conflictItems = computed(() => queueItems.value.filter((item) => item.status === 'conflict'))
  const hasConflicts = computed(() => conflictItems.value.length > 0)
  const pendingQueueCount = computed(() => queueSummary.value.unsynced)

  function markServerHealthy() {
    if (mode.value === 'offline_draft') {
      lastRecoveredAt.value = new Date().toISOString()
    }
    mode.value = 'server'
    void syncQueueNow()
  }

  function markLocalFallback(context: string) {
    mode.value = 'offline_draft'
    lastFallbackAt.value = new Date().toISOString()
    lastFallbackContext.value = context
    void refreshQueueState()
  }

  function bindSyncListeners() {
    if (syncListenersBound) return
    if (typeof window === 'undefined') return

    const onOnline = () => {
      void syncQueueNow()
    }
    const onOffline = () => {
      mode.value = 'offline_draft'
    }

    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
    syncListenersBound = true
  }

  async function refreshQueueState() {
    queueItems.value = readSyncQueue()
    applyLocalDraftStatuses(queueItems.value)
    queueSummary.value = getSyncQueueSummary()
    if (queueSummary.value.unsynced > 0) {
      mode.value = 'offline_draft'
    } else if (mode.value === 'offline_draft' && !syncing.value) {
      mode.value = 'server'
    }
  }

  async function syncQueueNow() {
    await refreshQueueState()
    if (queueSummary.value.unsynced <= 0) return
    if (syncing.value) return
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return

    syncing.value = true
    lastSyncError.value = null
    try {
      const result = await replaySyncQueue()
      queueItems.value = result.queue
      applyLocalDraftStatuses(queueItems.value)
      queueSummary.value = getSyncQueueSummary()
      lastSyncAt.value = new Date().toISOString()

      if (result.errors > 0) {
        lastSyncError.value = `${result.errors} action(s) failed to sync.`
      }
      if (result.halted_by_connectivity || queueSummary.value.unsynced > 0) {
        mode.value = 'offline_draft'
      } else {
        mode.value = 'server'
      }

      clearSyncedQueueEntries()
      queueItems.value = readSyncQueue()
      applyLocalDraftStatuses(queueItems.value)
      queueSummary.value = getSyncQueueSummary()
    } catch {
      lastSyncError.value = 'Could not run sync queue.'
      mode.value = 'offline_draft'
    } finally {
      syncing.value = false
    }
  }

  function resolveConflictKeepLocal(queueId: string) {
    resolveSyncConflictKeepLocal(queueId)
    void refreshQueueState()
    void syncQueueNow()
  }

  async function pullLatestServerVersion(queueId: string) {
    try {
      const resolved = await resolveSyncConflictPullLatest(queueId)
      if (resolved.item?.entity === 'trades' && resolved.server_snapshot) {
        upsertLocalTradeSnapshot(resolved.server_snapshot as unknown as Trade)
      }
      if (resolved.item?.entity === 'accounts' && resolved.server_snapshot) {
        upsertLocalAccountSnapshot(resolved.server_snapshot as unknown as Account)
      }
    } catch {
      lastSyncError.value = 'Could not pull latest server version for this conflict.'
    }

    await refreshQueueState()
  }

  function discardLocalChange(queueId: string) {
    resolveSyncConflictDiscardLocal(queueId)
    void refreshQueueState()
  }

  function resolveConflictAcceptServer(queueId: string) {
    discardLocalChange(queueId)
  }

  function applyLocalDraftStatuses(items: SyncQueueItem[]) {
    for (const item of items) {
      if (item.entity === 'trades') {
        if (item.status === 'synced' && item.operation === 'delete') {
          try {
            deleteLocalTrade(item.local_id)
          } catch {
            // Ignore local draft deletion misses.
          }
          continue
        }

        setLocalTradeSyncStatus(
          item.local_id,
          item.status,
          item.risk_unverified ? 'unverified' : 'verified'
        )
        continue
      }

      if (item.entity === 'accounts') {
        if (item.status === 'synced' && item.operation === 'delete') {
          try {
            deleteLocalAccount(item.local_id)
          } catch {
            // Ignore local draft deletion misses.
          }
          continue
        }
        setLocalAccountSyncStatus(item.local_id, item.status)
      }
    }
  }

  bindSyncListeners()
  void refreshQueueState()
  if (typeof navigator === 'undefined' || navigator.onLine) {
    void syncQueueNow()
  }

  return {
    mode,
    isFallbackMode,
    lastFallbackAt,
    lastFallbackContext,
    lastRecoveredAt,
    queueSummary,
    queueItems,
    conflictItems,
    hasConflicts,
    pendingQueueCount,
    syncing,
    lastSyncAt,
    lastSyncError,
    markServerHealthy,
    markLocalFallback,
    refreshQueueState,
    syncQueueNow,
    resolveConflictKeepLocal,
    pullLatestServerVersion,
    discardLocalChange,
    resolveConflictAcceptServer,
  }
})
