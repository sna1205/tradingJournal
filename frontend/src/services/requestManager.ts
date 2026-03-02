export interface RequestExecutionContext {
  key: string
  requestId: number
  signal: AbortSignal
  isLatest: () => boolean
}

export interface ManagedRequestOptions<T> {
  key: string
  execute: (context: RequestExecutionContext) => Promise<T>
  fingerprint?: string
  externalSignal?: AbortSignal
  abortStale?: boolean
  latestWins?: boolean
  dedupe?: boolean
  cacheKey?: string
  cacheTtlMs?: number
}

export interface ManagedRequestResult<T> {
  key: string
  requestId: number
  value: T
  stale: boolean
  fromCache: boolean
}

interface InflightEntry<T> {
  requestId: number
  fingerprint: string
  controller: AbortController
  promise: Promise<ManagedRequestResult<T>>
}

interface CacheEntry<T> {
  value: T
  expiresAtMs: number
}

interface KeyState {
  latestRequestId: number
}

const ABORT_ERROR_CODES = new Set<string>([
  'ERR_CANCELED',
  'ABORT_ERR',
])

export interface RequestManager {
  run: <T>(options: ManagedRequestOptions<T>) => Promise<ManagedRequestResult<T>>
  isLatest: (key: string, requestId: number) => boolean
  invalidateCache: (cacheKey?: string) => void
  invalidateCacheByPrefix: (prefix: string) => void
  clear: () => void
}

export function createRequestManager(): RequestManager {
  const keyStates = new Map<string, KeyState>()
  const inflightByKey = new Map<string, InflightEntry<unknown>>()
  const cacheByKey = new Map<string, CacheEntry<unknown>>()

  function getKeyState(key: string): KeyState {
    const existing = keyStates.get(key)
    if (existing) return existing
    const next: KeyState = {
      latestRequestId: 0,
    }
    keyStates.set(key, next)
    return next
  }

  function isLatest(key: string, requestId: number): boolean {
    return getKeyState(key).latestRequestId === requestId
  }

  async function run<T>(options: ManagedRequestOptions<T>): Promise<ManagedRequestResult<T>> {
    const key = options.key
    const state = getKeyState(key)
    const fingerprint = options.fingerprint ?? ''
    const cacheTtlMs = normalizeCacheTtlMs(options.cacheTtlMs)
    const cacheKey = options.cacheKey ?? `${key}:${fingerprint}`
    const latestWins = options.latestWins !== false
    const abortStale = options.abortStale !== false
    const dedupe = options.dedupe !== false

    if (cacheTtlMs > 0) {
      const cached = cacheByKey.get(cacheKey)
      if (cached && cached.expiresAtMs > Date.now()) {
        return {
          key,
          requestId: state.latestRequestId,
          value: cached.value as T,
          stale: false,
          fromCache: true,
        }
      }
      if (cached) {
        cacheByKey.delete(cacheKey)
      }
    }

    const currentInflight = inflightByKey.get(key) as InflightEntry<T> | undefined
    if (
      dedupe
      && currentInflight
      && !currentInflight.controller.signal.aborted
      && currentInflight.fingerprint === fingerprint
    ) {
      return await currentInflight.promise
    }

    if (abortStale && currentInflight) {
      currentInflight.controller.abort()
    }

    const requestId = state.latestRequestId + 1
    state.latestRequestId = requestId

    const controller = new AbortController()
    const merged = mergeAbortSignals(controller.signal, options.externalSignal)
    const signal = merged.signal

    const promise = (async () => {
      try {
        const value = await options.execute({
          key,
          requestId,
          signal,
          isLatest: () => isLatest(key, requestId),
        })

        const stale = latestWins && !isLatest(key, requestId)
        if (!stale && cacheTtlMs > 0) {
          cacheByKey.set(cacheKey, {
            value,
            expiresAtMs: Date.now() + cacheTtlMs,
          })
        }

        return {
          key,
          requestId,
          value,
          stale,
          fromCache: false,
        }
      } finally {
        merged.dispose()
        const inflight = inflightByKey.get(key) as InflightEntry<T> | undefined
        if (inflight?.requestId === requestId) {
          inflightByKey.delete(key)
        }
      }
    })()

    inflightByKey.set(key, {
      requestId,
      fingerprint,
      controller,
      promise: promise as Promise<ManagedRequestResult<unknown>>,
    })

    return await promise
  }

  function invalidateCache(cacheKey?: string): void {
    if (!cacheKey) {
      cacheByKey.clear()
      return
    }
    cacheByKey.delete(cacheKey)
  }

  function invalidateCacheByPrefix(prefix: string): void {
    for (const key of cacheByKey.keys()) {
      if (!key.startsWith(prefix)) continue
      cacheByKey.delete(key)
    }
  }

  function clear(): void {
    for (const entry of inflightByKey.values()) {
      entry.controller.abort()
    }
    inflightByKey.clear()
    cacheByKey.clear()
    keyStates.clear()
  }

  return {
    run,
    isLatest,
    invalidateCache,
    invalidateCacheByPrefix,
    clear,
  }
}

export function isAbortError(error: unknown): boolean {
  if (!isRecord(error)) return false
  const name = String(error.name ?? '').toUpperCase()
  const code = String(error.code ?? '').toUpperCase()
  if (name === 'ABORTERROR') return true
  if (ABORT_ERROR_CODES.has(code)) return true
  return false
}

export function stableSerialize(value: unknown): string {
  return JSON.stringify(sortRecursively(value))
}

function sortRecursively(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => sortRecursively(item))
  }
  if (!isRecord(value)) {
    return value
  }

  const sortedEntries = Object.entries(value)
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([key, nested]) => [key, sortRecursively(nested)])

  return Object.fromEntries(sortedEntries)
}

function normalizeCacheTtlMs(value: number | undefined): number {
  const parsed = Number(value)
  if (!Number.isFinite(parsed)) return 0
  if (parsed <= 0) return 0
  return Math.trunc(parsed)
}

function mergeAbortSignals(primary: AbortSignal, external?: AbortSignal): {
  signal: AbortSignal
  dispose: () => void
} {
  if (!external) {
    return {
      signal: primary,
      dispose: () => undefined,
    }
  }

  if (primary.aborted || external.aborted) {
    const controller = new AbortController()
    controller.abort()
    return {
      signal: controller.signal,
      dispose: () => undefined,
    }
  }

  const controller = new AbortController()
  const abort = () => {
    if (!controller.signal.aborted) {
      controller.abort()
    }
  }

  primary.addEventListener('abort', abort, { once: true })
  external.addEventListener('abort', abort, { once: true })

  return {
    signal: controller.signal,
    dispose: () => {
      primary.removeEventListener('abort', abort)
      external.removeEventListener('abort', abort)
    },
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
