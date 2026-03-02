<?php

namespace App\Services;

use App\Exceptions\ChecklistConcurrencyException;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Trade;
use App\Models\TradeChecklistResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChecklistService
{
    public function listForScope(int $userId, array $filters = [])
    {
        $query = Checklist::query()
            ->with('account:id,name')
            ->with('strategyModel:id,name')
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

        if (array_key_exists('strategy_model_id', $filters) && $filters['strategy_model_id'] !== null) {
            $query->where('strategy_model_id', (int) $filters['strategy_model_id']);
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
        $scope = (string) $payload['scope'];
        $accountId = $scope === 'account'
            ? (isset($payload['account_id']) ? (int) $payload['account_id'] : null)
            : null;
        $strategyModelId = $scope === 'strategy'
            ? (isset($payload['strategy_model_id']) ? (int) $payload['strategy_model_id'] : null)
            : null;
        $isActive = (bool) ($payload['is_active'] ?? true);

        return DB::transaction(function () use ($userId, $payload, $scope, $accountId, $strategyModelId, $isActive): Checklist {
            if ($isActive) {
                $this->deactivateActiveScopeConflicts($userId, $scope, $accountId, $strategyModelId);
            }

            /** @var Checklist $checklist */
            $checklist = Checklist::query()->create([
                'user_id' => $userId,
                'account_id' => $accountId,
                'strategy_model_id' => $strategyModelId,
                'name' => trim((string) $payload['name']),
                'revision' => 1,
                'scope' => $scope,
                'enforcement_mode' => (string) ($payload['enforcement_mode'] ?? 'soft'),
                'is_active' => $isActive,
            ]);

            return $checklist;
        });
    }

    /**
     * @param array{if_match?:string|null,expected_revision?:int|null,expected_updated_at?:string|null,actor_user_id?:int|null,ip?:string|null,user_agent?:string|null} $context
     */
    public function updateChecklist(Checklist $checklist, array $payload, array $context = []): Checklist
    {
        return DB::transaction(function () use ($checklist, $payload, $context): Checklist {
            /** @var Checklist $lockedChecklist */
            $lockedChecklist = Checklist::query()->whereKey((int) $checklist->id)->lockForUpdate()->firstOrFail();

            $this->assertChecklistConcurrency($lockedChecklist, $context);

            $resolvedScope = (string) ($payload['scope'] ?? $lockedChecklist->scope);
            $previousEnforcementMode = (string) $lockedChecklist->enforcement_mode;
            $nextIsActive = array_key_exists('is_active', $payload)
                ? (bool) $payload['is_active']
                : (bool) $lockedChecklist->is_active;
            $nextAccountId = $resolvedScope === 'account'
                ? (array_key_exists('account_id', $payload)
                    ? ($payload['account_id'] !== null ? (int) $payload['account_id'] : null)
                    : ($lockedChecklist->account_id !== null ? (int) $lockedChecklist->account_id : null))
                : null;
            $nextStrategyModelId = $resolvedScope === 'strategy'
                ? (array_key_exists('strategy_model_id', $payload)
                    ? ($payload['strategy_model_id'] !== null ? (int) $payload['strategy_model_id'] : null)
                    : ($lockedChecklist->strategy_model_id !== null ? (int) $lockedChecklist->strategy_model_id : null))
                : null;

            if ($nextIsActive && $lockedChecklist->user_id !== null) {
                $this->deactivateActiveScopeConflicts(
                    (int) $lockedChecklist->user_id,
                    $resolvedScope,
                    $nextAccountId,
                    $nextStrategyModelId,
                    (int) $lockedChecklist->id
                );
            }

            $lockedChecklist->fill([
                'name' => array_key_exists('name', $payload) ? trim((string) $payload['name']) : $lockedChecklist->name,
                'scope' => $resolvedScope,
                'enforcement_mode' => $payload['enforcement_mode'] ?? $lockedChecklist->enforcement_mode,
                'is_active' => $nextIsActive,
                'account_id' => $nextAccountId,
                'strategy_model_id' => $nextStrategyModelId,
                'revision' => (int) $lockedChecklist->revision + 1,
            ]);
            $lockedChecklist->save();

            if ($previousEnforcementMode !== (string) $lockedChecklist->enforcement_mode) {
                $this->recordEnforcementModeAudit(
                    $lockedChecklist,
                    $previousEnforcementMode,
                    (string) $lockedChecklist->enforcement_mode,
                    $context
                );
            }

            /** @var Checklist $fresh */
            $fresh = $lockedChecklist->fresh(['account', 'strategyModel']);
            return $fresh;
        });
    }

    public function duplicateChecklist(Checklist $source): Checklist
    {
        return DB::transaction(function () use ($source): Checklist {
            if ($source->user_id !== null) {
                $this->deactivateActiveScopeConflicts(
                    (int) $source->user_id,
                    (string) $source->scope,
                    $source->account_id !== null ? (int) $source->account_id : null,
                    $source->strategy_model_id !== null ? (int) $source->strategy_model_id : null
                );
            }

            /** @var Checklist $clone */
            $clone = Checklist::query()->create([
                'user_id' => $source->user_id,
                'account_id' => $source->account_id,
                'strategy_model_id' => $source->strategy_model_id,
                'name' => $source->name . ' (Copy)',
                'revision' => 1,
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

            return $clone->fresh(['items', 'account', 'strategyModel']);
        });
    }

    public function buildChecklistEtag(Checklist $checklist): string
    {
        $updatedAt = $checklist->updated_at?->toISOString() ?? '';
        return sprintf('"%d:%s"', (int) $checklist->revision, $updatedAt);
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

        $item = ChecklistItem::query()->create([
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

        $this->bumpRevision((int) $checklist->id);

        return $item;
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
        $this->bumpRevision((int) $item->checklist_id);

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
        $this->bumpRevision((int) $checklist->id);
    }

    public function softDeleteItem(ChecklistItem $item): void
    {
        $item->is_active = false;
        $item->save();
        $this->bumpRevision((int) $item->checklist_id);
    }

    public function resolveApplicableChecklist(int $userId, ?int $accountId, ?int $strategyModelId = null): ?Checklist
    {
        return $this->resolveApplicableChecklistWithContext($userId, $accountId, $strategyModelId)['checklist'];
    }

    /**
     * @return array{
     *   checklist:Checklist|null,
     *   resolved_scope:'strategy'|'account'|'global'|null,
     *   resolved_account_id:int|null,
     *   resolved_strategy_model_id:int|null
     * }
     */
    public function resolveApplicableChecklistWithContext(int $userId, ?int $accountId, ?int $strategyModelId = null): array
    {
        $normalizedAccountId = $accountId !== null && $accountId > 0 ? (int) $accountId : null;
        $normalizedStrategyModelId = $strategyModelId !== null && $strategyModelId > 0
            ? (int) $strategyModelId
            : null;

        $strategyChecklist = $this->resolveScopeChecklist($userId, 'strategy', $normalizedAccountId, $normalizedStrategyModelId);
        if ($strategyChecklist !== null) {
            return [
                'checklist' => $strategyChecklist,
                'resolved_scope' => 'strategy',
                'resolved_account_id' => null,
                'resolved_strategy_model_id' => $normalizedStrategyModelId,
            ];
        }

        $accountChecklist = $this->resolveScopeChecklist($userId, 'account', $normalizedAccountId, $normalizedStrategyModelId);
        if ($accountChecklist !== null) {
            return [
                'checklist' => $accountChecklist,
                'resolved_scope' => 'account',
                'resolved_account_id' => $normalizedAccountId,
                'resolved_strategy_model_id' => null,
            ];
        }

        $globalChecklist = $this->resolveScopeChecklist($userId, 'global', $normalizedAccountId, $normalizedStrategyModelId);
        if ($globalChecklist !== null) {
            return [
                'checklist' => $globalChecklist,
                'resolved_scope' => 'global',
                'resolved_account_id' => null,
                'resolved_strategy_model_id' => null,
            ];
        }

        return [
            'checklist' => null,
            'resolved_scope' => null,
            'resolved_account_id' => null,
            'resolved_strategy_model_id' => null,
        ];
    }

    private function resolveScopeChecklist(
        int $userId,
        string $scope,
        ?int $accountId,
        ?int $strategyModelId
    ): ?Checklist {
        if ($scope === 'account' && $accountId === null) {
            return null;
        }
        if ($scope === 'strategy' && $strategyModelId === null) {
            return null;
        }

        $query = Checklist::query()
            ->where('is_active', true)
            ->where('scope', $scope);
        $this->applyUserScope($query, $userId);

        if ($scope === 'account') {
            $query->where('account_id', $accountId);
        } elseif ($scope === 'strategy') {
            $query->where('strategy_model_id', $strategyModelId);
        }

        $matches = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        if ($matches->count() > 1) {
            Log::warning('Multiple active checklists in same scope; deterministic winner applied.', [
                'user_id' => $userId,
                'scope' => $scope,
                'account_id' => $accountId,
                'strategy_model_id' => $strategyModelId,
                'winner_id' => (int) $matches->first()->id,
                'candidate_ids' => $matches->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ]);
        }

        return $matches->first();
    }

    private function bumpRevision(int $checklistId): void
    {
        Checklist::query()
            ->whereKey($checklistId)
            ->update([
                'revision' => DB::raw('revision + 1'),
                'updated_at' => now(),
            ]);
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

    /**
     * @param array{if_match?:string|null,expected_revision?:int|null,expected_updated_at?:string|null} $context
     */
    private function assertChecklistConcurrency(Checklist $checklist, array $context): void
    {
        $expectedRevision = array_key_exists('expected_revision', $context) && $context['expected_revision'] !== null
            ? (int) $context['expected_revision']
            : null;
        $expectedUpdatedAt = array_key_exists('expected_updated_at', $context)
            ? $context['expected_updated_at']
            : null;
        $ifMatch = array_key_exists('if_match', $context) ? $context['if_match'] : null;
        $hasExpectation = $expectedRevision !== null
            || (is_string($expectedUpdatedAt) && trim($expectedUpdatedAt) !== '')
            || (is_string($ifMatch) && trim($ifMatch) !== '');

        if (!$hasExpectation) {
            return;
        }

        $currentEtag = $this->buildChecklistEtag($checklist);
        $currentUpdatedAt = $checklist->updated_at?->toISOString() ?? now()->toISOString();
        if (is_string($ifMatch) && trim($ifMatch) !== '') {
            if (!$this->ifMatchAllowsCurrentEtag($ifMatch, $currentEtag)) {
                throw new ChecklistConcurrencyException((int) $checklist->revision, $currentUpdatedAt, $currentEtag);
            }
        }

        if ($expectedRevision !== null && $expectedRevision !== (int) $checklist->revision) {
            throw new ChecklistConcurrencyException((int) $checklist->revision, $currentUpdatedAt, $currentEtag);
        }

        if (is_string($expectedUpdatedAt) && trim($expectedUpdatedAt) !== '') {
            if (!$this->timestampsMatch($expectedUpdatedAt, $currentUpdatedAt)) {
                throw new ChecklistConcurrencyException((int) $checklist->revision, $currentUpdatedAt, $currentEtag);
            }
        }
    }

    private function ifMatchAllowsCurrentEtag(string $ifMatchHeader, string $currentEtag): bool
    {
        $rawTokens = array_filter(array_map('trim', explode(',', $ifMatchHeader)));
        if ($rawTokens === []) {
            return false;
        }
        if (in_array('*', $rawTokens, true)) {
            return true;
        }

        $allowedTokens = [
            $currentEtag,
            'W/' . $currentEtag,
        ];

        foreach ($rawTokens as $token) {
            if (in_array($token, $allowedTokens, true)) {
                return true;
            }
        }

        return false;
    }

    private function timestampsMatch(string $expected, string $current): bool
    {
        try {
            $expectedAt = CarbonImmutable::parse($expected)->setTimezone('UTC');
            $currentAt = CarbonImmutable::parse($current)->setTimezone('UTC');
        } catch (\Throwable) {
            return false;
        }

        return $expectedAt->equalTo($currentAt);
    }

    private function deactivateActiveScopeConflicts(
        int $userId,
        string $scope,
        ?int $accountId,
        ?int $strategyModelId,
        ?int $exceptChecklistId = null
    ): void
    {
        $query = Checklist::query()
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->where('is_active', true);

        if ($exceptChecklistId !== null) {
            $query->where('id', '<>', $exceptChecklistId);
        }

        if ($scope === 'account') {
            $query->where('account_id', $accountId);
        } elseif ($scope === 'strategy') {
            $query->where('strategy_model_id', $strategyModelId);
        }

        $query->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array{actor_user_id?:int|null,ip?:string|null,user_agent?:string|null} $context
     */
    private function recordEnforcementModeAudit(
        Checklist $checklist,
        string $fromMode,
        string $toMode,
        array $context
    ): void {
        DB::table('checklist_enforcement_mode_audits')->insert([
            'checklist_id' => (int) $checklist->id,
            'checklist_user_id' => $checklist->user_id !== null ? (int) $checklist->user_id : null,
            'changed_by_user_id' => array_key_exists('actor_user_id', $context) && $context['actor_user_id'] !== null
                ? (int) $context['actor_user_id']
                : ($checklist->user_id !== null ? (int) $checklist->user_id : null),
            'from_enforcement_mode' => $fromMode,
            'to_enforcement_mode' => $toMode,
            'ip' => array_key_exists('ip', $context) && is_string($context['ip']) && trim($context['ip']) !== ''
                ? trim($context['ip'])
                : null,
            'user_agent' => array_key_exists('user_agent', $context) && is_string($context['user_agent']) && trim($context['user_agent']) !== ''
                ? trim($context['user_agent'])
                : null,
            'created_at' => now(),
        ]);
    }
}
