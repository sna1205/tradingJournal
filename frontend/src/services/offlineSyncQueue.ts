import { isAxiosError } from 'axios'
import api from '@/services/api'

const AUTH_USER_ID_KEY = 'tj_auth_user_id'
const SYNC_QUEUE_KEY_PREFIX = 'tj_sync_queue_v2'

export type SyncQueueStatus = 'draft_local' | 'pending_sync' | 'synced' | 'conflict'
export type SyncQueueEntity = 'trades' | 'accounts'
export type SyncQueueOperation = 'create' | 'update' | 'delete'

export interface SyncConflictPayload {
  reason: 'server_changed' | 'server_missing' | 'sync_rejected'
  message: string
  server_updated_at: string | null
  server_snapshot: Record<string, unknown> | null
  local_snapshot: Record<string, unknown> | null
  detected_at: string
}

export interface SyncQueueItem {
  id: string
  entity: SyncQueueEntity
  operation: SyncQueueOperation
  status: SyncQueueStatus
  local_id: number
  server_id: number | null
  payload: Record<string, unknown> | null
  expected_updated_at: string | null
  context: string
  risk_unverified: boolean
  attempts: number
  last_error: string | null
  conflict: SyncConflictPayload | null
  created_at: string
  updated_at: string
}

export interface SyncQueueSummary {
  total: number
  unsynced: number
  draft_local: number
  pending_sync: number
  synced: number
  conflict: number
}

export interface ReplaySyncQueueResult {
  processed: number
  synced: number
  conflicts: number
  errors: number
  halted_by_connectivity: boolean
  queue: SyncQueueItem[]
}

interface BaseEnqueueArgs {
  entity: SyncQueueEntity
  local_id: number
  server_id?: number | null
  expected_updated_at?: string | null
  context: string
  risk_unverified?: boolean
}

interface EnqueueCreateArgs extends BaseEnqueueArgs {
  payload: Record<string, unknown>
}

interface EnqueueUpdateArgs extends BaseEnqueueArgs {
  payload: Record<string, unknown>
}

interface EnqueueDeleteArgs extends BaseEnqueueArgs {}

export function isConnectivityFailure(error: unknown): boolean {
  if (!isAxiosError(error)) return false
  if (error.response) return false

  const code = String(error.code ?? '').toUpperCase()
  if (
    code === 'ERR_NETWORK'
    || code === 'ECONNABORTED'
    || code === 'ETIMEDOUT'
    || code === 'ERR_INTERNET_DISCONNECTED'
  ) {
    return true
  }

  const message = String(error.message ?? '').toLowerCase()
  if (message.includes('network error') || message.includes('timeout') || message.includes('offline')) {
    return true
  }

  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return true
  }

  return false
}

export function readSyncQueue(): SyncQueueItem[] {
  const raw = readJson<unknown>(syncQueueKey(), [])
  if (!Array.isArray(raw)) return []
  return raw
    .map((row) => normalizeQueueItem(row))
    .filter((row): row is SyncQueueItem => row !== null)
    .sort((left, right) => left.created_at.localeCompare(right.created_at) || left.id.localeCompare(right.id))
}

export function getSyncQueueSummary(): SyncQueueSummary {
  const queue = readSyncQueue()
  const summary: SyncQueueSummary = {
    total: queue.length,
    unsynced: 0,
    draft_local: 0,
    pending_sync: 0,
    synced: 0,
    conflict: 0,
  }

  for (const item of queue) {
    summary[item.status] += 1
    if (item.status !== 'synced') {
      summary.unsynced += 1
    }
  }

  return summary
}

export function enqueueSyncCreate(args: EnqueueCreateArgs): SyncQueueItem {
  const queue = readSyncQueue()
  const now = nowIso()
  const item: SyncQueueItem = {
    id: makeQueueId(args.entity, 'create'),
    entity: args.entity,
    operation: 'create',
    status: 'draft_local',
    local_id: args.local_id,
    server_id: normalizeId(args.server_id),
    payload: cleanPayload(args.payload),
    expected_updated_at: normalizeUpdatedAt(args.expected_updated_at),
    context: args.context,
    risk_unverified: Boolean(args.risk_unverified),
    attempts: 0,
    last_error: null,
    conflict: null,
    created_at: now,
    updated_at: now,
  }

  queue.push(item)
  writeSyncQueue(queue)
  return item
}

export function enqueueSyncUpdate(args: EnqueueUpdateArgs): SyncQueueItem {
  const queue = readSyncQueue()
  const latest = findLatestUnsynced(queue, args.entity, args.local_id)
  const payload = cleanPayload(args.payload)

  if (latest && latest.status !== 'conflict') {
    if (latest.operation === 'create' || latest.operation === 'update') {
      latest.payload = {
        ...(latest.payload ?? {}),
        ...payload,
      }
      latest.updated_at = nowIso()
      latest.last_error = null
      writeSyncQueue(queue)
      return latest
    }
  }

  const item: SyncQueueItem = {
    id: makeQueueId(args.entity, 'update'),
    entity: args.entity,
    operation: 'update',
    status: 'draft_local',
    local_id: args.local_id,
    server_id: normalizeId(args.server_id) ?? args.local_id,
    payload,
    expected_updated_at: normalizeUpdatedAt(args.expected_updated_at),
    context: args.context,
    risk_unverified: Boolean(args.risk_unverified),
    attempts: 0,
    last_error: null,
    conflict: null,
    created_at: nowIso(),
    updated_at: nowIso(),
  }

  queue.push(item)
  writeSyncQueue(queue)
  return item
}

export function enqueueSyncDelete(args: EnqueueDeleteArgs): SyncQueueItem | null {
  const queue = readSyncQueue()
  const pendingForEntity = queue.filter((item) =>
    item.entity === args.entity
    && item.local_id === args.local_id
    && item.status !== 'synced'
  )

  const hasConflict = pendingForEntity.some((item) => item.status === 'conflict')
  if (hasConflict) {
    return pendingForEntity[pendingForEntity.length - 1] ?? null
  }

  const hasPendingCreate = pendingForEntity.some((item) => item.operation === 'create')
  if (hasPendingCreate) {
    const pruned = queue.filter((item) =>
      !(item.entity === args.entity && item.local_id === args.local_id && item.status !== 'synced')
    )
    writeSyncQueue(pruned)
    return null
  }

  const withoutOldMutations = queue.filter((item) =>
    !(
      item.entity === args.entity
      && item.local_id === args.local_id
      && item.status !== 'synced'
      && (item.operation === 'update' || item.operation === 'delete')
    )
  )

  const item: SyncQueueItem = {
    id: makeQueueId(args.entity, 'delete'),
    entity: args.entity,
    operation: 'delete',
    status: 'draft_local',
    local_id: args.local_id,
    server_id: normalizeId(args.server_id) ?? args.local_id,
    payload: null,
    expected_updated_at: normalizeUpdatedAt(args.expected_updated_at),
    context: args.context,
    risk_unverified: false,
    attempts: 0,
    last_error: null,
    conflict: null,
    created_at: nowIso(),
    updated_at: nowIso(),
  }

  withoutOldMutations.push(item)
  writeSyncQueue(withoutOldMutations)
  return item
}

export function resolveSyncConflictKeepLocal(queueId: string): SyncQueueItem | null {
  const queue = readSyncQueue()
  const item = queue.find((row) => row.id === queueId)
  if (!item || item.status !== 'conflict') return null

  const serverSnapshot = item.conflict?.server_snapshot ?? null
  const serverUpdatedAt = item.conflict?.server_updated_at ?? null
  const serverId = normalizeId(serverSnapshot?.id)

  item.status = 'draft_local'
  item.conflict = null
  item.last_error = null
  item.updated_at = nowIso()
  item.server_id = serverId ?? item.server_id
  item.expected_updated_at = serverUpdatedAt ?? item.expected_updated_at
  writeSyncQueue(queue)
  return item
}

export function resolveSyncConflictAcceptServer(queueId: string): SyncQueueItem | null {
  const queue = readSyncQueue()
  const item = queue.find((row) => row.id === queueId)
  if (!item || item.status !== 'conflict') return null

  item.status = 'synced'
  item.conflict = null
  item.last_error = 'Accepted server version.'
  item.updated_at = nowIso()
  item.risk_unverified = false
  writeSyncQueue(queue)
  return item
}

export async function replaySyncQueue(): Promise<ReplaySyncQueueResult> {
  const queue = readSyncQueue()
  let processed = 0
  let synced = 0
  let conflicts = 0
  let errors = 0
  let haltedByConnectivity = false

  for (const item of queue) {
    if (item.status === 'synced' || item.status === 'conflict') continue
    processed += 1
    markPending(item)

    try {
      if (item.operation === 'create') {
        const createdSnapshot = await syncCreate(item)
        markSynced(item, createdSnapshot)
        const createdId = normalizeId(createdSnapshot?.id)
        if (createdId) {
          propagateServerId(queue, item.entity, item.local_id, createdId)
        }
        synced += 1
        continue
      }

      const targetId = normalizeId(item.server_id) ?? item.local_id
      const remoteSnapshot = await fetchRemoteSnapshot(item.entity, targetId)
      const remoteUpdatedAt = readUpdatedAt(remoteSnapshot)
      const expectedUpdatedAt = item.expected_updated_at

      if (expectedUpdatedAt && remoteUpdatedAt && expectedUpdatedAt !== remoteUpdatedAt) {
        markConflict(item, {
          reason: 'server_changed',
          message: 'Server record changed after local draft was created.',
          server_updated_at: remoteUpdatedAt,
          server_snapshot: remoteSnapshot,
          local_snapshot: item.payload,
          detected_at: nowIso(),
        })
        conflicts += 1
        continue
      }

      if (item.operation === 'update') {
        const updatedSnapshot = await syncUpdate(item, targetId)
        markSynced(item, updatedSnapshot)
        synced += 1
        continue
      }

      await syncDelete(item, targetId)
      markSynced(item, remoteSnapshot)
      synced += 1
    } catch (error) {
      if (isConnectivityFailure(error)) {
        item.status = 'draft_local'
        item.last_error = 'Sync paused. Connectivity unavailable.'
        item.updated_at = nowIso()
        haltedByConnectivity = true
        break
      }

      if (isAxiosError(error)) {
        const status = Number(error.response?.status ?? 0)
        if (status === 404 && item.operation !== 'create') {
          markConflict(item, {
            reason: 'server_missing',
            message: 'Server record no longer exists.',
            server_updated_at: null,
            server_snapshot: null,
            local_snapshot: item.payload,
            detected_at: nowIso(),
          })
          conflicts += 1
          continue
        }

        if (status === 409 || status === 412) {
          markConflict(item, {
            reason: 'sync_rejected',
            message: errorMessage(error, 'Server rejected sync due to a version conflict.'),
            server_updated_at: null,
            server_snapshot: null,
            local_snapshot: item.payload,
            detected_at: nowIso(),
          })
          conflicts += 1
          continue
        }

        item.status = 'draft_local'
        item.last_error = errorMessage(error, 'Sync failed.')
        item.updated_at = nowIso()
        errors += 1
        continue
      }

      item.status = 'draft_local'
      item.last_error = 'Unexpected sync failure.'
      item.updated_at = nowIso()
      errors += 1
    }
  }

  writeSyncQueue(queue)

  return {
    processed,
    synced,
    conflicts,
    errors,
    halted_by_connectivity: haltedByConnectivity,
    queue,
  }
}

export function clearSyncedQueueEntries(limit = 200): void {
  const queue = readSyncQueue()
  const syncedItems = queue.filter((item) => item.status === 'synced')
  if (syncedItems.length <= limit) return

  const toRemove = syncedItems
    .sort((left, right) => left.updated_at.localeCompare(right.updated_at))
    .slice(0, syncedItems.length - limit)
    .map((item) => item.id)
  const removeSet = new Set(toRemove)

  writeSyncQueue(queue.filter((item) => !removeSet.has(item.id)))
}

function markPending(item: SyncQueueItem) {
  item.status = 'pending_sync'
  item.attempts += 1
  item.last_error = null
  item.updated_at = nowIso()
}

function markSynced(item: SyncQueueItem, snapshot: Record<string, unknown> | null) {
  item.status = 'synced'
  item.last_error = null
  item.conflict = null
  item.risk_unverified = false
  item.updated_at = nowIso()

  const updatedAt = readUpdatedAt(snapshot)
  if (updatedAt) {
    item.expected_updated_at = updatedAt
  }

  const serverId = normalizeId(snapshot?.id)
  if (serverId) {
    item.server_id = serverId
  }
}

function markConflict(item: SyncQueueItem, payload: SyncConflictPayload) {
  item.status = 'conflict'
  item.conflict = payload
  item.last_error = payload.message
  item.updated_at = nowIso()
}

async function syncCreate(item: SyncQueueItem): Promise<Record<string, unknown> | null> {
  const endpoint = endpointForEntity(item.entity)
  const { data } = await api.post(endpoint, item.payload ?? {})
  return extractSnapshot(item.entity, data)
}

async function syncUpdate(item: SyncQueueItem, targetId: number): Promise<Record<string, unknown> | null> {
  const endpoint = `${endpointForEntity(item.entity)}/${targetId}`
  const { data } = await api.put(endpoint, item.payload ?? {})
  return extractSnapshot(item.entity, data)
}

async function syncDelete(item: SyncQueueItem, targetId: number): Promise<void> {
  const endpoint = `${endpointForEntity(item.entity)}/${targetId}`
  await api.delete(endpoint)
}

async function fetchRemoteSnapshot(entity: SyncQueueEntity, targetId: number): Promise<Record<string, unknown> | null> {
  const endpoint = `${endpointForEntity(entity)}/${targetId}`
  const { data } = await api.get(endpoint)
  return extractSnapshot(entity, data)
}

function extractSnapshot(entity: SyncQueueEntity, data: unknown): Record<string, unknown> | null {
  if (!isRecord(data)) return null
  if (entity === 'trades') {
    const nested = data.trade
    if (isRecord(nested)) return nested
  }
  return data
}

function endpointForEntity(entity: SyncQueueEntity): string {
  if (entity === 'accounts') return '/accounts'
  return '/trades'
}

function findLatestUnsynced(queue: SyncQueueItem[], entity: SyncQueueEntity, localId: number): SyncQueueItem | null {
  const rows = queue
    .filter((item) => item.entity === entity && item.local_id === localId && item.status !== 'synced')
    .sort((left, right) => left.created_at.localeCompare(right.created_at) || left.id.localeCompare(right.id))
  return rows.length > 0 ? rows[rows.length - 1]! : null
}

function propagateServerId(queue: SyncQueueItem[], entity: SyncQueueEntity, localId: number, serverId: number) {
  for (const item of queue) {
    if (item.entity !== entity) continue
    if (item.local_id !== localId) continue
    if (item.server_id) continue
    item.server_id = serverId
  }
}

function normalizeQueueItem(input: unknown): SyncQueueItem | null {
  if (!isRecord(input)) return null

  const entity = normalizeEntity(input.entity)
  const operation = normalizeOperation(input.operation)
  const status = normalizeStatus(input.status)
  const localId = normalizeId(input.local_id)
  if (!entity || !operation || !status || !localId) return null

  return {
    id: String(input.id ?? makeQueueId(entity, operation)),
    entity,
    operation,
    status,
    local_id: localId,
    server_id: normalizeId(input.server_id),
    payload: isRecord(input.payload) ? { ...input.payload } : null,
    expected_updated_at: normalizeUpdatedAt(input.expected_updated_at),
    context: String(input.context ?? entity),
    risk_unverified: Boolean(input.risk_unverified),
    attempts: Number.isFinite(Number(input.attempts)) ? Math.max(0, Math.trunc(Number(input.attempts))) : 0,
    last_error: input.last_error ? String(input.last_error) : null,
    conflict: normalizeConflictPayload(input.conflict),
    created_at: String(input.created_at ?? nowIso()),
    updated_at: String(input.updated_at ?? nowIso()),
  }
}

function normalizeConflictPayload(input: unknown): SyncConflictPayload | null {
  if (!isRecord(input)) return null
  const reason = normalizeConflictReason(input.reason)
  if (!reason) return null

  return {
    reason,
    message: String(input.message ?? ''),
    server_updated_at: normalizeUpdatedAt(input.server_updated_at),
    server_snapshot: isRecord(input.server_snapshot) ? { ...input.server_snapshot } : null,
    local_snapshot: isRecord(input.local_snapshot) ? { ...input.local_snapshot } : null,
    detected_at: String(input.detected_at ?? nowIso()),
  }
}

function normalizeEntity(value: unknown): SyncQueueEntity | null {
  const text = String(value ?? '').toLowerCase()
  if (text === 'trades' || text === 'accounts') return text
  return null
}

function normalizeOperation(value: unknown): SyncQueueOperation | null {
  const text = String(value ?? '').toLowerCase()
  if (text === 'create' || text === 'update' || text === 'delete') return text
  return null
}

function normalizeStatus(value: unknown): SyncQueueStatus | null {
  const text = String(value ?? '').toLowerCase()
  if (text === 'draft_local' || text === 'pending_sync' || text === 'synced' || text === 'conflict') return text
  return null
}

function normalizeConflictReason(value: unknown): SyncConflictPayload['reason'] | null {
  const text = String(value ?? '').toLowerCase()
  if (text === 'server_changed' || text === 'server_missing' || text === 'sync_rejected') return text
  return null
}

function syncQueueKey(): string {
  const userScope = String(localStorage.getItem(AUTH_USER_ID_KEY) ?? '').trim() || 'anon'
  return `${SYNC_QUEUE_KEY_PREFIX}:${userScope}`
}

function nowIso(): string {
  return new Date().toISOString()
}

function makeQueueId(entity: SyncQueueEntity, operation: SyncQueueOperation): string {
  const random = Math.random().toString(16).slice(2, 10)
  return `${entity}-${operation}-${Date.now()}-${random}`
}

function normalizeId(value: unknown): number | null {
  const parsed = Number(value)
  if (!Number.isInteger(parsed)) return null
  if (parsed <= 0) return null
  return parsed
}

function normalizeUpdatedAt(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed ? trimmed : null
}

function cleanPayload(payload: Record<string, unknown> | null): Record<string, unknown> | null {
  if (!payload) return null
  return { ...payload }
}

function readUpdatedAt(snapshot: Record<string, unknown> | null): string | null {
  if (!snapshot) return null
  return normalizeUpdatedAt(snapshot.updated_at)
}

function errorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError(error)) return fallback
  const responseData = error.response?.data
  if (isRecord(responseData)) {
    const message = responseData.message
    if (typeof message === 'string' && message.trim()) {
      return message.trim()
    }
  }
  if (typeof error.message === 'string' && error.message.trim()) {
    return error.message.trim()
  }
  return fallback
}

function readJson<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) return fallback
    return JSON.parse(raw) as T
  } catch {
    return fallback
  }
}

function writeSyncQueue(queue: SyncQueueItem[]): void {
  try {
    localStorage.setItem(syncQueueKey(), JSON.stringify(queue))
  } catch {
    // Ignore local storage write failures.
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
