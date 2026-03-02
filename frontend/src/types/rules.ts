export type ChecklistScope = 'global' | 'account' | 'strategy'
export type ChecklistEnforcementMode = 'soft' | 'strict'
export type ChecklistItemType = 'checkbox' | 'dropdown' | 'number' | 'text' | 'scale'
export type ChecklistRuleWeight = 'hard' | 'soft'
export type ChecklistNumberComparator = '>' | '>=' | '<' | '<=' | '=' | 'equals' | 'between'

export interface Checklist {
  id: number
  user_id?: number | null
  account_id?: number | null
  strategy_model_id?: number | null
  name: string
  revision?: number
  scope: ChecklistScope
  enforcement_mode: ChecklistEnforcementMode
  is_active: boolean
  created_at: string
  updated_at: string
  account?: {
    id: number
    name: string
  } | null
  strategy_model?: {
    id: number
    name: string
  } | null
  active_items_count?: number
}

export interface ChecklistItemConfigScale {
  min: number
  max: number
  labels?: Record<number, string>
  auto?: string
  auto_metric?: string
  threshold?: number
  comparator?: ChecklistNumberComparator
  weight?: ChecklistRuleWeight
  value?: number
}

export interface ChecklistItemConfigNumber {
  min?: number
  max?: number
  step?: number
  unit?: string
  auto?: string
  auto_metric?: string
  comparator?: ChecklistNumberComparator
  threshold?: number
  risk_linked?: boolean
  weight?: ChecklistRuleWeight
  value?: number
}

export interface ChecklistItemConfigDropdown {
  options: string[]
  auto?: string
  weight?: ChecklistRuleWeight
  value?: number
}

export interface ChecklistItemConfigText {
  maxLength?: number
  auto?: string
  weight?: ChecklistRuleWeight
  value?: number
}

export type ChecklistItemConfig =
  | Record<string, never>
  | ChecklistItemConfigScale
  | ChecklistItemConfigNumber
  | ChecklistItemConfigDropdown
  | ChecklistItemConfigText

export interface ChecklistItem {
  id: number
  checklist_id: number
  order_index: number
  title: string
  type: ChecklistItemType
  required: boolean
  category: string
  help_text?: string | null
  config: ChecklistItemConfig
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface TradeChecklistResponseRecord {
  checklist_item_id: number
  value: unknown
  is_completed: boolean
  completed_at: string | null
  archived?: boolean
  title?: string
}

export interface TradeChecklistItemWithResponse extends ChecklistItem {
  response: TradeChecklistResponseRecord
}

export interface TradeChecklistReadiness {
  status: 'not_ready' | 'almost' | 'ready'
  completed_required: number
  total_required: number
  missing_required: Array<{
    checklist_item_id: number
    title: string
    category: string
  }>
  ready: boolean
}

export interface TradeChecklistResponsePayload {
  responses: {
    checklist: Checklist | null
    items: TradeChecklistItemWithResponse[]
    archived_responses: TradeChecklistResponseRecord[]
  }
  readiness: TradeChecklistReadiness
  failing_rules?: Array<{
    checklist_item_id: number
    title: string
    category: string
  }>
  context?: TradeChecklistResolverContext
  execution_snapshot?: TradeChecklistExecutionSnapshot
}

export interface TradeChecklistResolverContext {
  requested_account_id: number | null
  requested_strategy_model_id: number | null
  resolved_scope: ChecklistScope | null
  resolved_checklist_id: number | null
  resolved_account_id: number | null
  resolved_strategy_model_id: number | null
  trade_id: number | null
}

export interface TradeChecklistExecutionSnapshot {
  frozen: boolean
  legacy_unfrozen: boolean
  executed_checklist_id: number | null
  executed_checklist_version: number | null
  executed_enforcement_mode: 'strict' | 'soft' | 'off' | null
  failed_rule_ids: number[]
  failed_rule_titles: string[]
  check_evaluated_at: string | null
}
