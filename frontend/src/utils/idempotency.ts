export function createIdempotencyKey(): string {
  const randomUuid = globalThis.crypto?.randomUUID
  if (typeof randomUuid === 'function') {
    try {
      return randomUuid.call(globalThis.crypto)
    } catch {
      // Fall back to a deterministic format when crypto UUID generation is unavailable.
    }
  }

  const timestamp = Date.now().toString(36)
  const random = Math.random().toString(36).slice(2, 12)
  return `idem-${timestamp}-${random}`
}
