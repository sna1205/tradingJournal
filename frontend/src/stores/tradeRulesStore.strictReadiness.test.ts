import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTradeRulesStore } from '@/stores/tradeRulesStore'
import type { Checklist, TradeChecklistReadiness } from '@/types/rules'

function readiness(ready: boolean): TradeChecklistReadiness {
  return {
    status: ready ? 'ready' : 'not_ready',
    completed_required: ready ? 2 : 1,
    total_required: 2,
    missing_required: ready
      ? []
      : [{
        checklist_item_id: 99,
        title: 'Risk cap confirmation',
        category: 'Checklist',
        reason: 'Risk cap not acknowledged.',
      }],
    ready,
  }
}

function strictChecklist(): Checklist {
  return {
    id: 55,
    name: 'Strict Checklist',
    scope: 'account',
    enforcement_mode: 'strict',
    is_active: true,
    created_at: '2026-03-02T00:00:00Z',
    updated_at: '2026-03-02T00:00:00Z',
  }
}

describe('trade form strict readiness gate', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('trade_form_strict_mode_blocks_submit_when_server_readiness_false', () => {
    const store = useTradeRulesStore()
    store.checklist = strictChecklist()
    store.readiness = readiness(true)
    store.serverReadiness = readiness(false)

    expect(store.strictSubmitBlocked).toBe(true)
  })

  it('does_not_block_when_server_ready_even_if_local_is_not_ready', () => {
    const store = useTradeRulesStore()
    store.checklist = strictChecklist()
    store.readiness = readiness(false)
    store.serverReadiness = readiness(true)

    expect(store.strictSubmitBlocked).toBe(false)
  })
})
