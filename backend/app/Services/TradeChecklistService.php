<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Trade;
use App\Models\TradeChecklistResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TradeChecklistService
{
    /**
     * @param array<int,array{checklist_item_id:int,value:mixed}> $responses
     * @return array{responses:array<int,array<string,mixed>>, readiness:array<string,mixed>}
     */
    public function upsertResponses(Trade $trade, Checklist $checklist, array $responses): array
    {
        return DB::transaction(function () use ($trade, $checklist, $responses): array {
            $activeItems = $checklist->items()
                ->where('is_active', true)
                ->orderBy('order_index')
                ->orderBy('id')
                ->get();
            $itemById = $activeItems->keyBy('id');

            foreach ($responses as $row) {
                $itemId = (int) ($row['checklist_item_id'] ?? 0);
                if ($itemId <= 0 || !$itemById->has($itemId)) {
                    continue;
                }

                /** @var ChecklistItem $item */
                $item = $itemById[$itemId];
                $value = $this->normalizeStoredValue($item->type, $row['value'] ?? null);
                $completed = $this->isCompleted($item, $value);

                TradeChecklistResponse::query()->updateOrCreate(
                    [
                        'trade_id' => (int) $trade->id,
                        'checklist_item_id' => $itemId,
                    ],
                    [
                        'checklist_id' => (int) $checklist->id,
                        'value' => $value,
                        'is_completed' => $completed,
                        'completed_at' => $completed ? CarbonImmutable::now() : null,
                    ]
                );
            }

            return $this->buildTradeChecklistState($trade, $checklist, true);
        });
    }

    /**
     * @return array{responses:array<int,array<string,mixed>>, readiness:array<string,mixed>}
     */
    public function buildTradeChecklistState(Trade $trade, Checklist $checklist, bool $editingMode = true): array
    {
        $items = $checklist->items()
            ->where('is_active', true)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();
        $responses = TradeChecklistResponse::query()
            ->where('trade_id', (int) $trade->id)
            ->where('checklist_id', (int) $checklist->id)
            ->orderBy('id')
            ->get();

        $responseByItem = $responses->keyBy('checklist_item_id');

        $serialized = [];
        $requiredActiveCount = 0;
        $requiredCompleted = 0;
        $missingRequired = [];

        foreach ($items as $item) {
            /** @var TradeChecklistResponse|null $response */
            $response = $responseByItem->get((int) $item->id);
            $value = $response?->value;
            $completed = $response?->is_completed ?? $this->isCompleted($item, $value);

            $isRequiredForReadiness = (bool) $item->required && (bool) $item->is_active && $editingMode;
            if ($isRequiredForReadiness) {
                $requiredActiveCount += 1;
                if ($completed) {
                    $requiredCompleted += 1;
                } else {
                    $missingRequired[] = [
                        'checklist_item_id' => (int) $item->id,
                        'title' => (string) $item->title,
                        'category' => (string) $item->category,
                    ];
                }
            }

            $serialized[] = [
                'id' => (int) $item->id,
                'checklist_id' => (int) $item->checklist_id,
                'order_index' => (int) $item->order_index,
                'title' => (string) $item->title,
                'type' => (string) $item->type,
                'required' => (bool) $item->required,
                'category' => (string) $item->category,
                'help_text' => $item->help_text,
                'config' => $item->config ?? [],
                'is_active' => (bool) $item->is_active,
                'response' => [
                    'checklist_item_id' => (int) $item->id,
                    'value' => $value,
                    'is_completed' => (bool) $completed,
                    'completed_at' => $response?->completed_at,
                    'archived' => false,
                ],
            ];
        }

        $archivedTitleByItemId = ChecklistItem::query()
            ->where('checklist_id', (int) $checklist->id)
            ->where('is_active', false)
            ->pluck('title', 'id');

        $archivedOrphans = TradeChecklistResponse::query()
            ->where('trade_id', (int) $trade->id)
            ->where('checklist_id', (int) $checklist->id)
            ->whereNotIn('checklist_item_id', $items->pluck('id')->all())
            ->get()
            ->map(fn (TradeChecklistResponse $row): array => [
                'checklist_item_id' => (int) $row->checklist_item_id,
                'value' => $row->value,
                'is_completed' => (bool) $row->is_completed,
                'completed_at' => $row->completed_at,
                'archived' => true,
                'title' => (string) ($archivedTitleByItemId[(int) $row->checklist_item_id] ?? 'Item archived'),
            ])
            ->values()
            ->all();

        $status = 'ready';
        if ($requiredActiveCount === 0) {
            $status = 'ready';
        } elseif ($requiredCompleted === 0) {
            $status = 'not_ready';
        } elseif ($requiredCompleted < $requiredActiveCount) {
            $status = 'almost';
        }

        return [
            'responses' => [
                'checklist' => [
                    'id' => (int) $checklist->id,
                    'name' => (string) $checklist->name,
                    'scope' => (string) $checklist->scope,
                    'enforcement_mode' => (string) $checklist->enforcement_mode,
                    'account_id' => $checklist->account_id !== null ? (int) $checklist->account_id : null,
                    'is_active' => (bool) $checklist->is_active,
                ],
                'items' => $serialized,
                'archived_responses' => $archivedOrphans,
            ],
            'readiness' => [
                'status' => $status,
                'completed_required' => $requiredCompleted,
                'total_required' => $requiredActiveCount,
                'missing_required' => $missingRequired,
                'ready' => $requiredActiveCount === 0 || $requiredCompleted >= $requiredActiveCount,
            ],
        ];
    }

    public function isCompleted(ChecklistItem $item, mixed $value): bool
    {
        $type = (string) $item->type;
        if ($type === 'checkbox') {
            return (bool) $value;
        }

        if ($type === 'dropdown') {
            return is_string($value) && trim($value) !== '';
        }

        if ($type === 'number') {
            return is_numeric($value);
        }

        if ($type === 'scale') {
            return is_numeric($value);
        }

        if ($type === 'text') {
            return is_string($value) && trim($value) !== '';
        }

        return !empty($value);
    }

    public function normalizeStoredValue(string $type, mixed $raw): mixed
    {
        return match ($type) {
            'checkbox' => (bool) $raw,
            'number', 'scale' => is_numeric($raw) ? (float) $raw : null,
            'dropdown', 'text' => is_string($raw) ? trim($raw) : (is_scalar($raw) ? (string) $raw : null),
            default => $raw,
        };
    }
}
