export interface StorageScope {
  userId: number | null
  accountId: number | null
}

export interface LegacyMigrationEntry {
  legacyKey: string
  namespace: string
  key: string
  allowAnonymous?: boolean
}

const SCOPE_PREFIX = 'tj:v3'
const LEGACY_PREFIX = 'tj_'
const FEATURE_FLAG_PREFIX = 'tj_feature_'

let currentScope: StorageScope = {
  userId: null,
  accountId: null,
}

export function getScope(): StorageScope {
  return {
    userId: currentScope.userId,
    accountId: currentScope.accountId,
  }
}

export function scopedKey(namespace: string, key: string): string {
  return scopedKeyForScope(getScope(), namespace, key)
}

export function scopedKeyForScope(scope: StorageScope, namespace: string, key: string): string {
  const userPart = scope.userId === null ? 'anon' : String(scope.userId)
  const accountPart = scope.accountId === null ? 'all' : String(scope.accountId)
  return `${SCOPE_PREFIX}:u:${userPart}:a:${accountPart}:${namespace}:${key}`
}

export function setScope(scope: Partial<StorageScope>): StorageScope {
  if (scope.userId !== undefined) {
    currentScope.userId = normalizeId(scope.userId)
  }
  if (scope.accountId !== undefined) {
    currentScope.accountId = normalizeId(scope.accountId)
  }
  return getScope()
}

export function setScopeUserId(userId: number | null | undefined): StorageScope {
  return setScope({ userId })
}

export function setScopeAccountId(accountId: number | null | undefined): StorageScope {
  return setScope({ accountId })
}

export function purgeScopedStorageForUser(userId: number | null | undefined): void {
  const normalized = normalizeId(userId)
  if (normalized === null) return

  const userPrefix = `${SCOPE_PREFIX}:u:${normalized}:`
  removeKeysByPrefix(readStorageKeys(safeLocalStorage()), userPrefix, safeLocalStorage())
  removeKeysByPrefix(readStorageKeys(safeSessionStorage()), userPrefix, safeSessionStorage())
}

export function migrateLegacyUnscopedKeys(entries: LegacyMigrationEntry[]): void {
  const scope = getScope()
  const localStorageRef = safeLocalStorage()
  if (!localStorageRef) return

  for (const entry of entries) {
    const legacyValue = safeGet(localStorageRef, entry.legacyKey)
    if (legacyValue === null) continue

    if (scope.userId === null && !entry.allowAnonymous) {
      safeRemove(localStorageRef, entry.legacyKey)
      continue
    }

    const targetKey = scopedKey(entry.namespace, entry.key)
    if (safeGet(localStorageRef, targetKey) === null) {
      safeSet(localStorageRef, targetKey, legacyValue)
    }
    safeRemove(localStorageRef, entry.legacyKey)
  }

  if (scope.userId === null) {
    purgeUnsafeAnonymousLegacyKeys()
  }
}

function purgeUnsafeAnonymousLegacyKeys(): void {
  const localStorageRef = safeLocalStorage()
  if (!localStorageRef) return

  const keys = readStorageKeys(localStorageRef)
  for (const key of keys) {
    if (!key.startsWith(LEGACY_PREFIX)) continue
    if (key.startsWith(FEATURE_FLAG_PREFIX)) continue
    safeRemove(localStorageRef, key)
  }
}

function removeKeysByPrefix(keys: string[], prefix: string, storage: Storage | null): void {
  if (!storage) return
  for (const key of keys) {
    if (!key.startsWith(prefix)) continue
    safeRemove(storage, key)
  }
}

function readStorageKeys(storage: Storage | null): string[] {
  if (!storage) return []
  const keys: string[] = []
  for (let index = 0; index < storage.length; index += 1) {
    const key = storage.key(index)
    if (key) {
      keys.push(key)
    }
  }
  return keys
}

function normalizeId(value: number | null | undefined): number | null {
  if (typeof value !== 'number') return null
  if (!Number.isInteger(value) || value <= 0) return null
  return value
}

function safeLocalStorage(): Storage | null {
  try {
    if (typeof localStorage === 'undefined') return null
    return localStorage
  } catch {
    return null
  }
}

function safeSessionStorage(): Storage | null {
  try {
    if (typeof sessionStorage === 'undefined') return null
    return sessionStorage
  } catch {
    return null
  }
}

function safeGet(storage: Storage, key: string): string | null {
  try {
    return storage.getItem(key)
  } catch {
    return null
  }
}

function safeSet(storage: Storage, key: string, value: string): void {
  try {
    storage.setItem(key, value)
  } catch {
    // Ignore storage write failures.
  }
}

function safeRemove(storage: Storage, key: string): void {
  try {
    storage.removeItem(key)
  } catch {
    // Ignore storage remove failures.
  }
}
