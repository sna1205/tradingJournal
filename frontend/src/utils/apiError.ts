import { isAxiosError } from 'axios'

export interface NormalizedErrorDetail {
  field: string
  message: string
}

export interface NormalizedError {
  version: string | null
  status: number | null
  code: string
  message: string
  details: NormalizedErrorDetail[]
  fieldErrors: Record<string, string[]>
  meta: Record<string, unknown>
  requestId: string | null
  isValidation: boolean
  isConflict: boolean
  isNetwork: boolean
  failingRuleIds: number[]
}

export function normalizeApiError(error: unknown): NormalizedError {
  if (isNormalizedError(error)) {
    return error
  }

  if (!isAxiosError(error)) {
    return {
      version: null,
      status: null,
      code: 'unknown_error',
      message: (error instanceof Error && error.message.trim() !== '')
        ? error.message
        : 'An unexpected error occurred.',
      details: [],
      fieldErrors: {},
      meta: {},
      requestId: null,
      isValidation: false,
      isConflict: false,
      isNetwork: false,
      failingRuleIds: [],
    }
  }

  if (!error.response) {
    return {
      version: null,
      status: null,
      code: 'network_error',
      message: 'Network error. Please check your connection and try again.',
      details: [],
      fieldErrors: {},
      meta: {},
      requestId: null,
      isValidation: false,
      isConflict: false,
      isNetwork: true,
      failingRuleIds: [],
    }
  }

  const data = toRecord(error.response.data)
  const errorEnvelope = toRecord(data?.error)
  const status = toInt(errorEnvelope?.status) ?? error.response.status
  const code = toText(errorEnvelope?.code)
    ?? toText(data?.code)
    ?? defaultCodeByStatus(status)
  const message = firstText(
    toText(errorEnvelope?.message),
    toText(data?.message),
    firstFieldMessage(data),
    defaultMessageByStatus(status)
  )
  const details = normalizeDetails(errorEnvelope?.details ?? data?.details)
  const legacyErrors = normalizeLegacyErrors(data)
  const detailsAsFieldErrors = detailsToFieldErrors(details)
  const fieldErrors = mergeFieldErrors(legacyErrors, detailsAsFieldErrors)
  const meta = {
    ...toRecord(errorEnvelope?.meta),
    ...(toRecord(data?.current) ? { current: toRecord(data?.current) as Record<string, unknown> } : {}),
  }

  return {
    version: toText(errorEnvelope?.version),
    status,
    code,
    message,
    details,
    fieldErrors,
    meta,
    requestId: toText(errorEnvelope?.requestId),
    isValidation: status === 422,
    isConflict: status === 409 || status === 412,
    isNetwork: false,
    failingRuleIds: normalizeFailingRuleIds(data),
  }
}

function isNormalizedError(value: unknown): value is NormalizedError {
  const row = toRecord(value)
  if (!row) return false
  return typeof row.message === 'string'
    && typeof row.code === 'string'
    && ('status' in row)
    && ('fieldErrors' in row)
}

function normalizeDetails(value: unknown): NormalizedErrorDetail[] {
  if (!Array.isArray(value)) return []
  const rows: NormalizedErrorDetail[] = []
  for (const entry of value) {
    const row = toRecord(entry)
    if (!row) continue
    const message = toText(row.message)
    if (!message) continue
    rows.push({
      field: toText(row.field) ?? 'general',
      message,
    })
  }
  return rows
}

function normalizeLegacyErrors(data: Record<string, unknown> | null): Record<string, string[]> {
  const primary = toRecord(data?.errors)
  if (!primary) return {}

  const normalized: Record<string, string[]> = {}
  for (const [field, value] of Object.entries(primary)) {
    if (Array.isArray(value)) {
      const messages = value.map((item) => toText(item)).filter((item): item is string => Boolean(item))
      if (messages.length > 0) {
        normalized[field] = messages
      }
      continue
    }

    const single = toText(value)
    if (single) {
      normalized[field] = [single]
    }
  }

  return normalized
}

function detailsToFieldErrors(details: NormalizedErrorDetail[]): Record<string, string[]> {
  const fieldErrors: Record<string, string[]> = {}
  for (const detail of details) {
    const field = detail.field || 'general'
    fieldErrors[field] = [...(fieldErrors[field] ?? []), detail.message]
  }
  return fieldErrors
}

function mergeFieldErrors(
  first: Record<string, string[]>,
  second: Record<string, string[]>
): Record<string, string[]> {
  const merged: Record<string, string[]> = { ...first }
  for (const [field, messages] of Object.entries(second)) {
    merged[field] = [...(merged[field] ?? []), ...messages]
  }
  return merged
}

function firstFieldMessage(data: Record<string, unknown> | null): string | null {
  const errors = normalizeLegacyErrors(data)
  for (const messages of Object.values(errors)) {
    if (messages.length > 0) return messages[0] ?? null
  }
  return null
}

function normalizeFailingRuleIds(data: Record<string, unknown> | null): number[] {
  const direct = toIntArray(data?.failed_required_rule_ids ?? data?.failedRequiredRuleIds)
  if (direct.length > 0) return direct

  const failingRules = data?.failing_rules ?? data?.failingRules
  if (!Array.isArray(failingRules)) return []

  const ids: number[] = []
  for (const row of failingRules) {
    const record = toRecord(row)
    const id = toInt(record?.checklist_item_id ?? record?.checklistItemId)
    if (id && id > 0) ids.push(id)
  }
  return ids
}

function toIntArray(value: unknown): number[] {
  if (!Array.isArray(value)) return []
  const ids: number[] = []
  for (const entry of value) {
    const parsed = toInt(entry)
    if (parsed !== null && parsed > 0) {
      ids.push(parsed)
    }
  }
  return ids
}

function toRecord(value: unknown): Record<string, unknown> | null {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return null
  }
  return value as Record<string, unknown>
}

function toInt(value: unknown): number | null {
  const numeric = Number(value)
  if (!Number.isInteger(numeric)) return null
  return numeric
}

function toText(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

function firstText(...values: Array<string | null | undefined>): string {
  for (const value of values) {
    if (value && value.trim() !== '') {
      return value
    }
  }
  return 'Request failed.'
}

function defaultCodeByStatus(status: number | null): string {
  if (status === 422) return 'validation_failed'
  if (status === 409) return 'conflict'
  if (status === 412) return 'precondition_failed'
  if (status === 401) return 'unauthorized'
  return 'request_failed'
}

function defaultMessageByStatus(status: number | null): string {
  if (status === 422) return 'Validation failed.'
  if (status === 409) return 'Request conflict.'
  if (status === 412) return 'Precondition failed.'
  if (status === 401) return 'Unauthorized.'
  return 'Request failed.'
}
