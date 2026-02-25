<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Trade;
use App\Models\TradeChecklistResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ChecklistService
{
    public function listForScope(int $userId, array $filters = [])
    {
        $query = Checklist::query()
            ->with('account:id,name')
            ->withCount(['items as active_items_count' => fn (Builder $q) => $q->where('is_active', true)])
            ->orderByDesc('is_active')
            ->orderBy('scope')
            ->orderBy('name');

        $this->applyUserScope($query, $userId);

        if (!empty($filters['scope'])) {
            $query->where('scope', (string) $filters['scope']);
        }

        if (array_key_exists('account_id', $filters) && $filters['account_id'] !== null) {
            $query->where('account_id', (int) $filters['account_id']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where('name', 'like', $search);
        }

        return $query->get();
    }

    public function createChecklist(int $userId, array $payload): Checklist
    {
        return Checklist::query()->create([
            'user_id' => $userId,
            'account_id' => $payload['scope'] === 'account'
                ? (isset($payload['account_id']) ? (int) $payload['account_id'] : null)
                : null,
            'name' => trim((string) $payload['name']),
            'scope' => (string) $payload['scope'],
            'enforcement_mode' => (string) ($payload['enforcement_mode'] ?? 'soft'),
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);
    }

    public function updateChecklist(Checklist $checklist, array $payload): Checklist
    {
        $checklist->fill([
            'name' => array_key_exists('name', $payload) ? trim((string) $payload['name']) : $checklist->name,
            'scope' => $payload['scope'] ?? $checklist->scope,
            'enforcement_mode' => $payload['enforcement_mode'] ?? $checklist->enforcement_mode,
            'is_active' => array_key_exists('is_active', $payload)
                ? (bool) $payload['is_active']
                : $checklist->is_active,
            'account_id' => ($payload['scope'] ?? $checklist->scope) === 'account'
                ? (array_key_exists('account_id', $payload)
                    ? ($payload['account_id'] !== null ? (int) $payload['account_id'] : null)
                    : $checklist->account_id)
                : null,
        ]);
        $checklist->save();

        return $checklist->fresh(['account']);
    }

    public function duplicateChecklist(Checklist $source): Checklist
    {
        return DB::transaction(function () use ($source): Checklist {
            /** @var Checklist $clone */
            $clone = Checklist::query()->create([
                'user_id' => $source->user_id,
                'account_id' => $source->account_id,
                'name' => $source->name . ' (Copy)',
                'scope' => $source->scope,
                'enforcement_mode' => $source->enforcement_mode,
                'is_active' => true,
            ]);

            $items = $source->items()->orderBy('order_index')->get();
            foreach ($items as $item) {
                ChecklistItem::query()->create([
                    'checklist_id' => $clone->id,
                    'order_index' => (int) $item->order_index,
                    'title' => (string) $item->title,
                    'type' => (string) $item->type,
                    'required' => (bool) $item->required,
                    'category' => (string) $item->category,
                    'help_text' => $item->help_text,
                    'config' => $item->config,
                    'is_active' => (bool) $item->is_active,
                ]);
            }

            return $clone->fresh(['items']);
        });
    }

    public function softDeleteChecklist(Checklist $checklist): void
    {
        $checklist->is_active = false;
        $checklist->save();
    }

    public function listItems(Checklist $checklist)
    {
        return $checklist->items()->orderBy('order_index')->orderBy('id')->get();
    }

    public function createItem(Checklist $checklist, array $payload): ChecklistItem
    {
        $orderIndex = $payload['order_index']
            ?? ((int) ($checklist->items()->max('order_index') ?? -1) + 1);

        return ChecklistItem::query()->create([
            'checklist_id' => (int) $checklist->id,
            'order_index' => (int) $orderIndex,
            'title' => trim((string) $payload['title']),
            'type' => (string) $payload['type'],
            'required' => (bool) ($payload['required'] ?? false),
            'category' => trim((string) ($payload['category'] ?? 'General')),
            'help_text' => $payload['help_text'] ?? null,
            'config' => $payload['config'] ?? [],
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);
    }

    public function updateItem(ChecklistItem $item, array $payload): ChecklistItem
    {
        $item->fill([
            'title' => array_key_exists('title', $payload) ? trim((string) $payload['title']) : $item->title,
            'type' => $payload['type'] ?? $item->type,
            'required' => array_key_exists('required', $payload)
                ? (bool) $payload['required']
                : $item->required,
            'category' => array_key_exists('category', $payload)
                ? trim((string) $payload['category'])
                : $item->category,
            'help_text' => array_key_exists('help_text', $payload) ? $payload['help_text'] : $item->help_text,
            'config' => array_key_exists('config', $payload) ? $payload['config'] : $item->config,
            'is_active' => array_key_exists('is_active', $payload)
                ? (bool) $payload['is_active']
                : $item->is_active,
        ]);

        if (array_key_exists('order_index', $payload)) {
            $item->order_index = (int) $payload['order_index'];
        }

        $item->save();

        return $item->fresh();
    }

    public function reorderItems(Checklist $checklist, array $orderedIds): void
    {
        DB::transaction(function () use ($checklist, $orderedIds): void {
            $ids = array_values(array_unique(array_map(fn ($id) => (int) $id, $orderedIds)));
            $items = ChecklistItem::query()
                ->where('checklist_id', (int) $checklist->id)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            foreach ($ids as $index => $itemId) {
                if (!in_array($itemId, $items, true)) {
                    continue;
                }
                ChecklistItem::query()
                    ->where('id', $itemId)
                    ->where('checklist_id', (int) $checklist->id)
                    ->update(['order_index' => $index]);
            }
        });
    }

    public function softDeleteItem(ChecklistItem $item): void
    {
        $item->is_active = false;
        $item->save();
    }

    public function resolveApplicableChecklist(int $userId, ?int $accountId): ?Checklist
    {
        $base = Checklist::query()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN scope = 'account' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $this->applyUserScope($base, $userId);

        $base->where(function (Builder $query) use ($accountId): void {
            if ($accountId !== null && $accountId > 0) {
                $query->where(function (Builder $q) use ($accountId): void {
                    $q->where('scope', 'account')->where('account_id', $accountId);
                })->orWhere('scope', 'global');
                return;
            }

            $query->where('scope', 'global');
        });

        return $base->first();
    }

    public function ensureTradeInUserScope(Trade $trade, int $userId): bool
    {
        return $trade->account()->where('user_id', $userId)->exists();
    }

    public function syncChecklistIncompleteFlag(Trade $trade, bool $incomplete): void
    {
        if ((bool) $trade->checklist_incomplete === $incomplete) {
            return;
        }

        $trade->checklist_incomplete = $incomplete;
        $trade->save();
    }

    /**
     * @param Builder<Checklist> $query
     */
    public function applyUserScope(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    public function scopeResponseQueryToUser($query, int $userId)
    {
        return $query->where('checklists.user_id', $userId);
    }
}
