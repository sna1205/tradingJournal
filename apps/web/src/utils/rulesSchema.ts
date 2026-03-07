import type {
  ChecklistItem,
  ChecklistItemConfig,
  ChecklistNumberComparator,
  ChecklistRuleWeight,
} from '@/types/rules'

export type ChecklistCategoryKey =
  | 'risk_compliance'
  | 'market_context'
  | 'setup_validation'
  | 'psychology'

interface ChecklistCategoryDefinition {
  key: ChecklistCategoryKey
  label: string
  icon: string
}

export const CHECKLIST_CATEGORIES: ChecklistCategoryDefinition[] = [
  { key: 'risk_compliance', label: 'Risk & Compliance', icon: '🛡' },
  { key: 'market_context', label: 'Market Context', icon: '📊' },
  { key: 'setup_validation', label: 'Setup Validation', icon: '🎯' },
  { key: 'psychology', label: 'Psychology', icon: '🧠' },
]

const CATEGORY_NORMALIZATION_RULES: Array<{
  key: ChecklistCategoryKey
  patterns: RegExp[]
}> = [
  { key: 'risk_compliance', patterns: [/risk/i, /compliance/i, /policy/i] },
  { key: 'market_context', patterns: [/context/i, /market/i, /news/i, /session/i, /structure/i] },
  { key: 'setup_validation', patterns: [/setup/i, /trigger/i, /entry/i, /execution/i] },
  { key: 'psychology', patterns: [/psych/i, /emotion/i, /mind/i, /discipline/i, /tilt/i] },
]

export function normalizeChecklistCategory(raw: string | null | undefined): ChecklistCategoryKey {
  const value = String(raw ?? '').trim()
  if (!value) return 'risk_compliance'

  const directMatch = CHECKLIST_CATEGORIES.find((entry) => entry.label.toLowerCase() === value.toLowerCase())
  if (directMatch) return directMatch.key

  const rule = CATEGORY_NORMALIZATION_RULES.find((entry) =>
    entry.patterns.some((pattern) => pattern.test(value))
  )
  return rule?.key ?? 'setup_validation'
}

export function checklistCategoryLabel(category: ChecklistCategoryKey): string {
  return CHECKLIST_CATEGORIES.find((entry) => entry.key === category)?.label ?? 'Setup Validation'
}

export function checklistCategoryIcon(category: ChecklistCategoryKey): string {
  return CHECKLIST_CATEGORIES.find((entry) => entry.key === category)?.icon ?? '🎯'
}

export function checklistRuleWeight(item: ChecklistItem): ChecklistRuleWeight {
  const config = (item.config ?? {}) as ChecklistItemConfig & { weight?: ChecklistRuleWeight }
  if (config.weight === 'hard' || config.weight === 'soft') return config.weight
  return item.required ? 'hard' : 'soft'
}

export function isAutoValidatedRule(item: ChecklistItem): boolean {
  const config = (item.config ?? {}) as ChecklistItemConfig & {
    auto?: string
    auto_metric?: string
    rule?: { type?: string; metric_key?: string | null }
  }
  return Boolean(
    config.auto
    || config.auto_metric
    || config.rule?.type === 'auto_metric'
    || config.rule?.metric_key
  )
}

export function checklistRuleTypeLabel(item: ChecklistItem): string {
  const config = (item.config ?? {}) as ChecklistItemConfig & { rule?: { type?: string } }
  const ruleType = config.rule?.type
  if (ruleType === 'auto_metric') return 'Auto-validated'
  if (ruleType === 'numeric') return 'Numeric'
  if (ruleType === 'select') return 'Select'
  if (ruleType === 'boolean') return 'Toggle'
  if (isAutoValidatedRule(item)) return 'Auto-validated'
  if (item.type === 'checkbox') return 'Toggle'
  if (item.type === 'number') return 'Numeric'
  if (item.type === 'scale') return 'Scale'
  if (item.type === 'dropdown') return 'Select'
  return 'Text'
}

export const COMPARATOR_OPTIONS: Array<{ label: string; value: ChecklistNumberComparator }> = [
  { label: '>', value: '>' },
  { label: '<=', value: '<=' },
  { label: '>=', value: '>=' },
  { label: '<', value: '<' },
  { label: '=', value: '=' },
  { label: 'Between', value: 'between' },
]

export function ruleComparatorSymbol(comparator: unknown): ChecklistNumberComparator {
  if (
    comparator === '>'
    || comparator === '>='
    || comparator === '<'
    || comparator === '<='
    || comparator === '='
    || comparator === 'between'
  ) {
    return comparator
  }
  if (comparator === 'equals') return '='
  return '<='
}
