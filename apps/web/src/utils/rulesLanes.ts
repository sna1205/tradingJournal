import { normalizeChecklistCategory } from '@/utils/rulesSchema'

export type ChecklistLaneKey = 'before' | 'during' | 'after'

interface ChecklistLaneInput {
  title?: string | null
  category?: string | null
  type?: string | null
  required?: boolean | null
  config?: unknown
}

const BEFORE_LANE_PATTERN =
  /\b(before|pre|pre[-\s]?trade|prep|planning|plan|context|setup|entry)\b/
const DURING_LANE_PATTERN =
  /\b(during|in[-\s]?trade|live|execution|manage|monitor|risk|compliance)\b/
const AFTER_LANE_PATTERN =
  /\b(after|post|post[-\s]?trade|debrief|journal|review|retrospective|wrap[-\s]?up)\b/

export function resolveChecklistLane(input: ChecklistLaneInput): ChecklistLaneKey {
  const config = (input.config ?? {}) as Record<string, unknown>
  const explicitLane = typeof config.lane === 'string' ? config.lane : null
  if (explicitLane === 'before' || explicitLane === 'during' || explicitLane === 'after') {
    return explicitLane
  }

  const categoryRaw = String(input.category ?? '')
  const category = categoryRaw.trim().toLowerCase()
  const text = `${String(input.title ?? '')} ${categoryRaw}`.toLowerCase()

  if (AFTER_LANE_PATTERN.test(text)) return 'after'
  if (DURING_LANE_PATTERN.test(text)) return 'during'
  if (BEFORE_LANE_PATTERN.test(text)) return 'before'

  if (AFTER_LANE_PATTERN.test(category)) return 'after'
  if (DURING_LANE_PATTERN.test(category)) return 'during'
  if (BEFORE_LANE_PATTERN.test(category)) return 'before'

  if (input.type === 'text' && !Boolean(input.required)) return 'after'

  const normalizedCategory = normalizeChecklistCategory(categoryRaw)
  if (normalizedCategory === 'market_context' || normalizedCategory === 'setup_validation') return 'before'
  if (normalizedCategory === 'psychology') return 'after'
  return 'during'
}
