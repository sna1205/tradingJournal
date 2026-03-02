import type { StorageScope } from '@/services/storageScope'

const DB_NAME = 'trading-journal-secure-cache'
const DB_VERSION = 1
const STORE_NAME = 'scoped_records'

export interface ScopedIndexedDbRecord<T = unknown> {
  id: string
  namespace: string
  key: string
  user_id: number | null
  account_id: number | null
  created_at: string
  updated_at: string
  expire_at: string | null
  lru_at: string
  payload: T
}

const memoryStore = new Map<string, ScopedIndexedDbRecord<unknown>>()
let dbPromise: Promise<IDBDatabase> | null = null

export function scopedRecordId(scope: StorageScope, namespace: string, key: string): string {
  const userPart = scope.userId === null ? 'anon' : String(scope.userId)
  const accountPart = scope.accountId === null ? 'all' : String(scope.accountId)
  return `u:${userPart}:a:${accountPart}:${namespace}:${key}`
}

export async function getScopedRecord<T>(
  scope: StorageScope,
  namespace: string,
  key: string
): Promise<ScopedIndexedDbRecord<T> | null> {
  const id = scopedRecordId(scope, namespace, key)
  if (!supportsIndexedDb()) {
    return (memoryStore.get(id) as ScopedIndexedDbRecord<T> | undefined) ?? null
  }

  const db = await openDatabase()
  return await new Promise<ScopedIndexedDbRecord<T> | null>((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readonly')
    const store = tx.objectStore(STORE_NAME)
    const request = store.get(id)
    request.onsuccess = () => {
      resolve((request.result as ScopedIndexedDbRecord<T> | undefined) ?? null)
    }
    request.onerror = () => {
      reject(request.error ?? new Error('Failed to read scoped record from IndexedDB.'))
    }
  })
}

export async function putScopedRecord<T>(record: ScopedIndexedDbRecord<T>): Promise<void> {
  if (!supportsIndexedDb()) {
    memoryStore.set(record.id, { ...record })
    return
  }

  const db = await openDatabase()
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite')
    const store = tx.objectStore(STORE_NAME)
    const request = store.put(record)
    request.onsuccess = () => resolve()
    request.onerror = () => reject(request.error ?? new Error('Failed to write scoped record to IndexedDB.'))
  })
}

export async function deleteScopedRecord(scope: StorageScope, namespace: string, key: string): Promise<void> {
  const id = scopedRecordId(scope, namespace, key)
  if (!supportsIndexedDb()) {
    memoryStore.delete(id)
    return
  }

  const db = await openDatabase()
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite')
    const store = tx.objectStore(STORE_NAME)
    const request = store.delete(id)
    request.onsuccess = () => resolve()
    request.onerror = () => reject(request.error ?? new Error('Failed to delete scoped record from IndexedDB.'))
  })
}

export async function purgeScopedRecordsForUser(userId: number, namespace?: string): Promise<void> {
  if (!Number.isInteger(userId) || userId <= 0) {
    return
  }

  if (!supportsIndexedDb()) {
    const prefix = `u:${userId}:`
    const entries = [...memoryStore.entries()]
    for (const [id, record] of entries) {
      if (!id.startsWith(prefix)) continue
      if (namespace && record.namespace !== namespace) continue
      memoryStore.delete(id)
    }
    return
  }

  const db = await openDatabase()
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite')
    const store = tx.objectStore(STORE_NAME)
    const request = store.openCursor()

    request.onsuccess = () => {
      const cursor = request.result
      if (!cursor) {
        resolve()
        return
      }

      const row = cursor.value as ScopedIndexedDbRecord<unknown>
      if (row.user_id === userId && (!namespace || row.namespace === namespace)) {
        cursor.delete()
      }
      cursor.continue()
    }

    request.onerror = () => {
      reject(request.error ?? new Error('Failed to purge user scope from IndexedDB.'))
    }
  })
}

export async function clearScopedRecords(namespace?: string): Promise<void> {
  if (!supportsIndexedDb()) {
    if (!namespace) {
      memoryStore.clear()
      return
    }
    const entries = [...memoryStore.entries()]
    for (const [id, record] of entries) {
      if (record.namespace !== namespace) continue
      memoryStore.delete(id)
    }
    return
  }

  const db = await openDatabase()
  if (!namespace) {
    await new Promise<void>((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite')
      const store = tx.objectStore(STORE_NAME)
      const request = store.clear()
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error ?? new Error('Failed to clear IndexedDB store.'))
    })
    return
  }

  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite')
    const store = tx.objectStore(STORE_NAME)
    const request = store.openCursor()
    request.onsuccess = () => {
      const cursor = request.result
      if (!cursor) {
        resolve()
        return
      }
      const row = cursor.value as ScopedIndexedDbRecord<unknown>
      if (row.namespace === namespace) {
        cursor.delete()
      }
      cursor.continue()
    }
    request.onerror = () => reject(request.error ?? new Error('Failed to clear namespace in IndexedDB.'))
  })
}

async function openDatabase(): Promise<IDBDatabase> {
  if (dbPromise) {
    return dbPromise
  }

  dbPromise = new Promise<IDBDatabase>((resolve, reject) => {
    if (!supportsIndexedDb()) {
      reject(new Error('IndexedDB is not available in this environment.'))
      return
    }

    const request = indexedDB.open(DB_NAME, DB_VERSION)
    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        const store = db.createObjectStore(STORE_NAME, { keyPath: 'id' })
        store.createIndex('namespace', 'namespace', { unique: false })
        store.createIndex('user_scope', ['namespace', 'user_id'], { unique: false })
      }
    }

    request.onsuccess = () => {
      resolve(request.result)
    }
    request.onerror = () => {
      reject(request.error ?? new Error('Failed to open IndexedDB.'))
    }
  }).catch((error) => {
    dbPromise = null
    throw error
  })

  return dbPromise
}

function supportsIndexedDb(): boolean {
  try {
    return typeof indexedDB !== 'undefined'
  } catch {
    return false
  }
}

export async function __resetScopedIndexedDbForTests(): Promise<void> {
  await clearScopedRecords()
}
