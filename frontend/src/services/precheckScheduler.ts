export interface RequestContext {
  requestId: number
  hash: string
  signal: AbortSignal
}

interface ScheduleOptions {
  force?: boolean
}

interface DebouncedLatestRunnerOptions<TPayload, TResult> {
  debounceMs: number
  getHash: (payload: TPayload) => string
  execute: (payload: TPayload, context: RequestContext) => Promise<TResult>
  onRequestStart?: (context: RequestContext) => void
  onRequestSuccess?: (result: TResult, context: RequestContext) => void
  onRequestError?: (error: unknown, context: RequestContext) => void
  onRequestSettled?: (context: RequestContext) => void
}

export interface DebouncedLatestRunner<TPayload> {
  schedule: (payload: TPayload, options?: ScheduleOptions) => boolean
  cancel: () => void
  latestRequestId: () => number
}

export function createDebouncedLatestRunner<TPayload, TResult>(
  options: DebouncedLatestRunnerOptions<TPayload, TResult>
): DebouncedLatestRunner<TPayload> {
  let timer: ReturnType<typeof setTimeout> | null = null
  let pendingPayload: TPayload | null = null
  let pendingHash: string | null = null
  let currentController: AbortController | null = null
  let lastRequestedHash: string | null = null
  let latestRequestId = 0

  const runPending = async () => {
    timer = null
    if (pendingPayload === null || pendingHash === null) {
      return
    }

    if (pendingHash === lastRequestedHash) {
      pendingPayload = null
      pendingHash = null
      return
    }

    currentController?.abort()
    const controller = new AbortController()
    currentController = controller

    const requestId = ++latestRequestId
    const hash = pendingHash
    const payload = pendingPayload
    pendingPayload = null
    pendingHash = null
    lastRequestedHash = hash

    const context: RequestContext = {
      requestId,
      hash,
      signal: controller.signal,
    }

    options.onRequestStart?.(context)

    try {
      const result = await options.execute(payload, context)
      if (requestId !== latestRequestId) {
        return
      }
      options.onRequestSuccess?.(result, context)
    } catch (error) {
      if (isAbortError(error)) {
        return
      }
      if (requestId !== latestRequestId) {
        return
      }
      options.onRequestError?.(error, context)
    } finally {
      if (requestId === latestRequestId) {
        options.onRequestSettled?.(context)
      }
    }
  }

  const schedule = (payload: TPayload, scheduleOptions?: ScheduleOptions): boolean => {
    const hash = options.getHash(payload)
    const force = scheduleOptions?.force === true
    if (!force && hash === lastRequestedHash && pendingHash === null) {
      return false
    }

    pendingPayload = payload
    pendingHash = hash

    if (timer) {
      clearTimeout(timer)
    }

    timer = setTimeout(() => {
      void runPending()
    }, options.debounceMs)

    return true
  }

  const cancel = () => {
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
    pendingPayload = null
    pendingHash = null
    currentController?.abort()
    currentController = null
  }

  return {
    schedule,
    cancel,
    latestRequestId: () => latestRequestId,
  }
}

export function stableStringify(value: unknown): string {
  return JSON.stringify(normalizeForStableStringify(value))
}

function normalizeForStableStringify(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeForStableStringify(item))
  }

  if (!isPlainRecord(value)) {
    return value
  }

  const result: Record<string, unknown> = {}
  const keys = Object.keys(value).sort((left, right) => left.localeCompare(right))
  for (const key of keys) {
    result[key] = normalizeForStableStringify(value[key])
  }
  return result
}

function isPlainRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function isAbortError(error: unknown): boolean {
  const maybe = error as { code?: string; name?: string; message?: string }
  if (maybe?.code === 'ERR_CANCELED') return true
  if (maybe?.name === 'AbortError' || maybe?.name === 'CanceledError') return true
  return `${maybe?.message ?? ''}`.toLowerCase().includes('aborted')
}
