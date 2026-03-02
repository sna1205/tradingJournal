# Pre-Trade Rules System

## How It Works
- Users build reusable rule sets in `/settings/rules`.
- Each rule set has scope (`global` or `account`) and enforcement mode (`soft` or `strict`).
- Trade form loads the applicable rule set and tracks responses per item.
- Required completion drives readiness:
  - `not_ready` -> none of required done
  - `almost` -> partial required done
  - `ready` -> all required done (or no required items)
- `strict` mode blocks save until readiness is `ready`.
- `soft` mode allows save and writes `trades.checklist_incomplete = true` when incomplete.
- Response values are bulk upserted into `trade_checklist_responses`.

## Edge Cases Handled
- Checklist item deactivated after responses exist:
  - old response kept and surfaced as archived.
- Checklist changes while editing a trade:
  - current edit loads latest active checklist items and recomputes readiness.
- New trade (no `trade_id` yet):
  - responses tracked locally first, persisted immediately after create succeeds.
- Missing rule set:
  - runner falls back to `ready` with zero required items.

## Key Files
- `backend/database/migrations/2026_02_23_000022_create_checklists_table.php`
- `backend/database/migrations/2026_02_23_000023_create_checklist_items_table.php`
- `backend/database/migrations/2026_02_23_000024_create_trade_checklist_responses_table.php`
- `backend/database/migrations/2026_02_23_000025_add_checklist_incomplete_to_trades_table.php`
- `backend/app/Services/ChecklistService.php`
- `backend/app/Services/TradeChecklistService.php`
- `backend/app/Http/Controllers/Api/ChecklistController.php`
- `backend/app/Http/Controllers/Api/ChecklistItemController.php`
- `backend/app/Http/Controllers/Api/TradeChecklistResponseController.php`
- `backend/routes/api.php`
- `frontend/src/stores/rulesStore.ts`
- `frontend/src/stores/tradeRulesStore.ts`
- `frontend/src/components/rules/TradingRulesPage.vue`
- `frontend/src/components/rules/RulesLibraryPanel.vue`
- `frontend/src/components/rules/RulesBoardEditor.vue`
- `frontend/src/components/rules/RuleItemRow.vue`
- `frontend/src/components/rules/AddRuleModal.vue`
- `frontend/src/components/rules/TradeRulesPanel.vue`
- `frontend/src/components/rules/RuleProgressHeader.vue`
- `frontend/src/components/rules/OptionalSection.vue`
- `frontend/src/pages/TradeFormPage.vue`
- `frontend/src/pages/TradingRulesPage.vue`
- `frontend/src/router/index.ts`

## API Examples

All checklist APIs support rules aliases:
- `/api/rules` mirrors `/api/checklists`
- `/api/rules/:id/items` mirrors `/api/checklists/:id/items`
- `/api/rule-items/:itemId` mirrors `/api/checklist-items/:itemId`
- `/api/trade-rules/resolve` mirrors `/api/trade-checklist/resolve`
- `/api/trades/:tradeId/rule-responses` mirrors `/api/trades/:tradeId/checklist-responses`

### 1) GET `/api/checklists?scope=global|account|strategy&accountId=...`
Request:
```http
GET /api/checklists?scope=account&accountId=2
```
Response:
```json
[
  {
    "id": 11,
    "name": "Prop Account Checklist",
    "scope": "account",
    "enforcement_mode": "strict",
    "account_id": 2,
    "is_active": true,
    "active_items_count": 8,
    "created_at": "2026-02-25T10:00:00Z",
    "updated_at": "2026-02-25T10:00:00Z"
  }
]
```

### 2) POST `/api/checklists`
Request:
```json
{
  "name": "Scalp Checklist",
  "scope": "global",
  "enforcement_mode": "soft",
  "is_active": true
}
```
Response:
```json
{
  "id": 12,
  "name": "Scalp Checklist",
  "scope": "global",
  "enforcement_mode": "soft",
  "account_id": null,
  "is_active": true,
  "created_at": "2026-02-25T10:02:00Z",
  "updated_at": "2026-02-25T10:02:00Z"
}
```

### 3) PUT `/api/checklists/:id`
Request:
```json
{
  "enforcement_mode": "strict",
  "is_active": true
}
```
Response:
```json
{
  "id": 12,
  "name": "Scalp Checklist",
  "scope": "global",
  "enforcement_mode": "strict",
  "is_active": true,
  "updated_at": "2026-02-25T10:05:00Z"
}
```

### 4) DELETE `/api/checklists/:id` (soft delete)
Request:
```http
DELETE /api/checklists/12
```
Response:
```http
204 No Content
```

### 5) GET `/api/checklists/:id/items`
Request:
```http
GET /api/checklists/11/items
```
Response:
```json
[
  {
    "id": 81,
    "checklist_id": 11,
    "order_index": 0,
    "title": "Market structure is clear",
    "type": "checkbox",
    "required": true,
    "category": "Structure",
    "help_text": null,
    "config": {},
    "is_active": true
  }
]
```

### 6) POST `/api/checklists/:id/items`
Request:
```json
{
  "title": "Risk % within policy",
  "type": "number",
  "required": true,
  "category": "Risk",
  "config": { "min": 0.1, "max": 1.0, "step": 0.1, "unit": "%" }
}
```
Response:
```json
{
  "id": 82,
  "checklist_id": 11,
  "order_index": 7,
  "title": "Risk % within policy",
  "type": "number",
  "required": true,
  "category": "Risk",
  "config": { "min": 0.1, "max": 1.0, "step": 0.1, "unit": "%" },
  "is_active": true
}
```

### 7) PUT `/api/checklist-items/:itemId`
Request:
```json
{
  "title": "Risk % within policy limit",
  "required": true,
  "help_text": "Must be <= account max risk.",
  "config": { "min": 0.1, "max": 1.0, "step": 0.1, "unit": "%" }
}
```
Response:
```json
{
  "id": 82,
  "title": "Risk % within policy limit",
  "required": true,
  "help_text": "Must be <= account max risk."
}
```

### 8) PUT `/api/checklists/:id/items/reorder`
Request:
```json
{
  "item_ids": [84, 81, 82, 83]
}
```
Response:
```json
{
  "items": [
    { "id": 84, "order_index": 0 },
    { "id": 81, "order_index": 1 },
    { "id": 82, "order_index": 2 },
    { "id": 83, "order_index": 3 }
  ]
}
```

### 9) DELETE `/api/checklist-items/:itemId` (soft delete)
Request:
```http
DELETE /api/checklist-items/82
```
Response:
```http
204 No Content
```

### 10) GET `/api/trades/:tradeId/checklist-responses`
Request:
```http
GET /api/trades/551/checklist-responses
```
Response:
```json
{
  "responses": {
    "checklist": {
      "id": 11,
      "name": "Prop Account Checklist",
      "scope": "account",
      "enforcement_mode": "strict",
      "account_id": 2,
      "is_active": true
    },
    "items": [
      {
        "id": 81,
        "title": "Market structure is clear",
        "type": "checkbox",
        "required": true,
        "response": {
          "checklist_item_id": 81,
          "value": true,
          "is_completed": true,
          "completed_at": "2026-02-25T11:00:00Z",
          "archived": false
        }
      }
    ],
    "archived_responses": []
  },
  "readiness": {
    "status": "almost",
    "completed_required": 4,
    "total_required": 6,
    "missing_required": [
      { "checklist_item_id": 83, "title": "Stop at invalidation", "category": "Risk" }
    ],
    "ready": false
  }
}
```

### 11) PUT `/api/trades/:tradeId/checklist-responses`
Request:
```json
{
  "checklist_id": 11,
  "responses": [
    { "checklist_item_id": 81, "value": true },
    { "checklist_item_id": 82, "value": 0.7 },
    { "checklist_item_id": 83, "value": "A" }
  ]
}
```
Response:
```json
{
  "responses": {
    "checklist": { "id": 11, "name": "Prop Account Checklist", "enforcement_mode": "strict" },
    "items": [],
    "archived_responses": []
  },
  "readiness": {
    "status": "ready",
    "completed_required": 6,
    "total_required": 6,
    "missing_required": [],
    "ready": true
  }
}
```
