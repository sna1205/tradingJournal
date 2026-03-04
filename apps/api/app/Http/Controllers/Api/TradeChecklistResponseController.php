<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithTradeRevision;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Checklist;
use App\Models\Trade;
use App\Services\ChecklistService;
use App\Services\TradeChecklistService;
use App\Support\ApiErrorResponder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TradeChecklistResponseController extends Controller
{
    use InteractsWithTradeRevision;

    public function __construct(
        private readonly ChecklistService $checklistService,
        private readonly TradeChecklistService $tradeChecklistService
    ) {}

    public function resolve(Request $request)
    {
        $userId = (int) $request->user()->id;

        $validator = Validator::make($request->query(), [
            'trade_id' => ['sometimes', 'nullable', 'integer', 'exists:trades,id'],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'strategy_model_id' => ['sometimes', 'nullable', 'integer', 'exists:strategy_models,id'],
        ]);
        $payload = $validator->validate();

        $trade = null;
        if (array_key_exists('trade_id', $payload) && $payload['trade_id'] !== null) {
            $trade = Trade::query()->findOrFail((int) $payload['trade_id']);
            $this->authorize('view', $trade);
        }

        $requestedContext = $this->resolveRequestedContext(
            $request,
            $userId,
            $trade,
            false
        );

        return response()->json(
            $this->buildChecklistResponseForContext(
                $userId,
                $trade,
                $requestedContext['account_id'],
                $requestedContext['strategy_model_id']
            )
        );
    }

    public function show(Request $request, Trade $trade)
    {
        $this->authorize('view', $trade);
        $userId = (int) $request->user()->id;

        $requestedContext = $this->resolveRequestedContext(
            $request,
            $userId,
            $trade,
            false
        );

        return response()
            ->json(
                $this->buildChecklistResponseForContext(
                    $userId,
                    $trade,
                    $requestedContext['account_id'],
                    $requestedContext['strategy_model_id']
                )
            )
            ->header('ETag', $this->buildTradeEtag($trade));
    }

    /**
     * @throws ValidationException
     */
    public function upsert(Request $request, Trade $trade)
    {
        $this->authorize('update', $trade);
        $userId = (int) $request->user()->id;

        $validator = Validator::make($request->all(), [
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'strategy_model_id' => ['sometimes', 'nullable', 'integer', 'exists:strategy_models,id'],
            'responses' => ['required', 'array'],
            'responses.*.checklist_item_id' => ['required', 'integer', 'exists:checklist_items,id'],
            'responses.*.value' => ['nullable'],
        ]);

        $payload = $validator->validate();
        $responsePayload = [];
        $updatedTrade = null;

        DB::transaction(function () use (
            $request,
            $trade,
            $userId,
            $payload,
            &$responsePayload,
            &$updatedTrade
        ): void {
            $lockedTrade = Trade::query()
                ->whereKey((int) $trade->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTradeWritePrecondition($request, $lockedTrade);

            if ($this->hasFrozenExecutionSnapshot($lockedTrade)) {
                throw new HttpResponseException(
                    ApiErrorResponder::errorV2(
                        request: $request,
                        status: 422,
                        code: 'trade_checklist_snapshot_frozen',
                        message: 'Rule responses are frozen for this trade execution snapshot.',
                        details: [[
                            'field' => 'checklist',
                            'message' => 'Historical rule responses are immutable for frozen trades.',
                        ]],
                        legacyErrors: [
                            'checklist' => ['Historical rule responses are immutable for frozen trades.'],
                        ]
                    )
                );
            }

            $requestedContext = $this->resolveRequestedContext(
                $request,
                $userId,
                $lockedTrade,
                true
            );

            $resolved = $this->checklistService->resolveApplicableChecklistWithContext(
                $userId,
                $requestedContext['account_id'],
                $requestedContext['strategy_model_id']
            );
            $checklist = $resolved['checklist'];

            if ($checklist === null) {
                $this->checklistService->syncChecklistIncompleteFlag($lockedTrade, false);
                $responsePayload = $this->blankResponsePayload(
                    $requestedContext['account_id'],
                    $requestedContext['strategy_model_id'],
                    (int) $lockedTrade->id
                );
                $updatedTrade = $this->bumpTradeRevision($lockedTrade);
                return;
            }

            $result = $this->tradeChecklistService->upsertResponses($lockedTrade, $checklist, $payload['responses']);
            $this->checklistService->syncChecklistIncompleteFlag($lockedTrade, ! $result['readiness']['ready']);

            $responsePayload = [
                ...$result,
                'context' => $this->buildContextPayload(
                    $requestedContext['account_id'],
                    $requestedContext['strategy_model_id'],
                    $resolved['resolved_scope'],
                    (int) $checklist->id,
                    $resolved['resolved_account_id'],
                    $resolved['resolved_strategy_model_id'],
                    (int) $lockedTrade->id
                ),
            ];
            $updatedTrade = $this->bumpTradeRevision($lockedTrade);
        });

        abort_if(! $updatedTrade instanceof Trade, 500, 'Checklist write completed without refreshed trade revision.');

        return response()
            ->json($responsePayload)
            ->header('ETag', $this->buildTradeEtag($updatedTrade));
    }

    private function buildChecklistResponseForContext(
        int $userId,
        ?Trade $trade,
        ?int $accountId,
        ?int $strategyModelId
    ): array {
        if ($trade !== null && $this->hasFrozenExecutionSnapshot($trade)) {
            return $this->buildFrozenTradeChecklistResponse($trade, $accountId, $strategyModelId);
        }

        $resolved = $this->checklistService->resolveApplicableChecklistWithContext(
            $userId,
            $accountId,
            $strategyModelId
        );
        $checklist = $resolved['checklist'];
        $tradeId = $trade !== null ? (int) $trade->id : null;

        if ($checklist === null) {
            return $this->blankResponsePayload($accountId, $strategyModelId, $tradeId, $trade !== null);
        }

        $state = $trade !== null
            ? $this->tradeChecklistService->buildTradeChecklistState($trade, $checklist, true)
            : $this->tradeChecklistService->buildDraftChecklistState($checklist, [], true);

        return [
            ...$state,
            'context' => $this->buildContextPayload(
                $accountId,
                $strategyModelId,
                $resolved['resolved_scope'],
                (int) $checklist->id,
                $resolved['resolved_account_id'],
                $resolved['resolved_strategy_model_id'],
                $tradeId
            ),
            'execution_snapshot' => $this->buildExecutionSnapshotPayload(
                frozen: false,
                legacyUnfrozen: $trade !== null,
                trade: $trade
            ),
        ];
    }

    private function blankResponsePayload(
        ?int $requestedAccountId,
        ?int $requestedStrategyModelId,
        ?int $tradeId,
        bool $legacyUnfrozen = false
    ): array {
        return [
            'responses' => [
                'checklist' => null,
                'items' => [],
                'archived_responses' => [],
            ],
            'readiness' => [
                'status' => 'ready',
                'completed_required' => 0,
                'total_required' => 0,
                'missing_required' => [],
                'ready' => true,
            ],
            'failing_rules' => [],
            'failed_required_rule_ids' => [],
            'failed_rule_reasons' => [],
            'failedRequiredRuleIds' => [],
            'failedRuleReasons' => [],
            'context' => $this->buildContextPayload(
                $requestedAccountId,
                $requestedStrategyModelId,
                null,
                null,
                null,
                null,
                $tradeId
            ),
            'execution_snapshot' => $this->buildExecutionSnapshotPayload(
                frozen: false,
                legacyUnfrozen: $legacyUnfrozen,
                trade: null
            ),
        ];
    }

    private function buildFrozenTradeChecklistResponse(
        Trade $trade,
        ?int $requestedAccountId,
        ?int $requestedStrategyModelId
    ): array {
        $executedChecklistId = $trade->executed_checklist_id !== null ? (int) $trade->executed_checklist_id : null;
        $checklist = $executedChecklistId !== null
            ? Checklist::query()->whereKey($executedChecklistId)->first()
            : null;

        $base = $checklist !== null
            ? $this->tradeChecklistService->buildTradeChecklistState($trade, $checklist, true)
            : [
                'responses' => [
                    'checklist' => null,
                    'items' => [],
                    'archived_responses' => [],
                ],
                'readiness' => [
                    'status' => 'ready',
                    'completed_required' => 0,
                    'total_required' => 0,
                    'missing_required' => [],
                    'ready' => true,
                ],
            ];

        $failedRuleIds = collect(is_array($trade->failed_rule_ids) ? $trade->failed_rule_ids : [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
        $failedRuleTitles = collect(is_array($trade->failed_rule_titles) ? $trade->failed_rule_titles : [])
            ->map(fn ($title): string => (string) $title)
            ->values()
            ->all();

        $missingRequired = [];
        foreach ($failedRuleIds as $index => $ruleId) {
            $missingRequired[] = [
                'checklist_item_id' => $ruleId,
                'title' => $failedRuleTitles[$index] ?? 'Required rule',
                'category' => 'Checklist',
            ];
        }

        $executedMode = $trade->executed_enforcement_mode !== null
            ? (string) $trade->executed_enforcement_mode
            : 'off';
        $ready = $executedMode === 'off' || count($missingRequired) === 0;
        $status = $ready ? 'ready' : 'not_ready';

        $base['responses']['checklist'] = [
            'id' => $executedChecklistId,
            'name' => $checklist?->name ?? 'Executed Checklist',
            'revision' => $trade->executed_checklist_version !== null ? (int) $trade->executed_checklist_version : ($checklist !== null ? (int) ($checklist->revision ?? 1) : null),
            'scope' => $checklist?->scope ?? 'global',
            'enforcement_mode' => $executedMode,
            'account_id' => $checklist?->account_id !== null ? (int) $checklist->account_id : null,
            'strategy_model_id' => $checklist?->strategy_model_id !== null ? (int) $checklist->strategy_model_id : null,
            'is_active' => $checklist !== null ? (bool) $checklist->is_active : false,
        ];
        $base['readiness'] = [
            'status' => $status,
            'completed_required' => $ready
                ? (int) ($base['readiness']['total_required'] ?? 0)
                : (int) max(0, (int) ($base['readiness']['total_required'] ?? 0) - count($missingRequired)),
            'total_required' => max(
                (int) ($base['readiness']['total_required'] ?? 0),
                count($missingRequired)
            ),
            'missing_required' => $missingRequired,
            'ready' => $ready,
        ];
        $base['failing_rules'] = $missingRequired;
        $base['failed_required_rule_ids'] = array_values(array_map(
            fn (array $row): int => (int) $row['checklist_item_id'],
            $missingRequired
        ));
        $base['failed_rule_reasons'] = array_values(array_map(
            fn (array $row): array => [
                'checklist_item_id' => (int) $row['checklist_item_id'],
                'title' => (string) ($row['title'] ?? 'Required rule'),
                'category' => (string) ($row['category'] ?? 'Checklist'),
                'reason' => (string) ($row['reason'] ?? 'Rule requirement not met.'),
            ],
            $missingRequired
        ));
        $base['failedRequiredRuleIds'] = $base['failed_required_rule_ids'];
        $base['failedRuleReasons'] = $base['failed_rule_reasons'];
        $base['context'] = $this->buildContextPayload(
            $requestedAccountId,
            $requestedStrategyModelId,
            null,
            $executedChecklistId,
            $trade->account_id !== null ? (int) $trade->account_id : null,
            $trade->strategy_model_id !== null ? (int) $trade->strategy_model_id : null,
            (int) $trade->id
        );
        $base['execution_snapshot'] = $this->buildExecutionSnapshotPayload(
            frozen: true,
            legacyUnfrozen: false,
            trade: $trade
        );

        return $base;
    }

    private function hasFrozenExecutionSnapshot(Trade $trade): bool
    {
        return $trade->executed_checklist_id !== null
            || $trade->executed_checklist_version !== null
            || $trade->executed_enforcement_mode !== null
            || $trade->check_evaluated_at !== null;
    }

    private function buildExecutionSnapshotPayload(bool $frozen, bool $legacyUnfrozen, ?Trade $trade): array
    {
        return [
            'frozen' => $frozen,
            'legacy_unfrozen' => $legacyUnfrozen,
            'executed_checklist_id' => $trade?->executed_checklist_id !== null ? (int) $trade->executed_checklist_id : null,
            'executed_checklist_version' => $trade?->executed_checklist_version !== null ? (int) $trade->executed_checklist_version : null,
            'executed_enforcement_mode' => $trade?->executed_enforcement_mode !== null ? (string) $trade->executed_enforcement_mode : null,
            'failed_rule_ids' => is_array($trade?->failed_rule_ids) ? array_values($trade->failed_rule_ids) : [],
            'failed_rule_titles' => is_array($trade?->failed_rule_titles) ? array_values($trade->failed_rule_titles) : [],
            'check_evaluated_at' => $trade?->check_evaluated_at !== null ? (string) $trade->check_evaluated_at : null,
        ];
    }

    /**
     * @return array{account_id:int|null,strategy_model_id:int|null}
     *
     * @throws ValidationException
     */
    private function resolveRequestedContext(Request $request, int $userId, ?Trade $trade, bool $fromBody): array
    {
        $input = $fromBody ? $request->all() : $request->query();
        $accountIdRaw = $input['account_id'] ?? $input['accountId'] ?? null;
        $strategyModelIdRaw = $input['strategy_model_id'] ?? $input['strategyModelId'] ?? null;

        $accountId = is_numeric($accountIdRaw) && (int) $accountIdRaw > 0
            ? (int) $accountIdRaw
            : null;
        $strategyModelId = is_numeric($strategyModelIdRaw) && (int) $strategyModelIdRaw > 0
            ? (int) $strategyModelIdRaw
            : null;

        if ($trade !== null) {
            if (
                $accountId !== null
                && $trade->account_id !== null
                && $accountId !== (int) $trade->account_id
            ) {
                throw ValidationException::withMessages([
                    'account_id' => ['account_id must match the trade account.'],
                ]);
            }
            if ($accountId === null && $trade->account_id !== null) {
                $accountId = (int) $trade->account_id;
            }

            if (
                $strategyModelId !== null
                && $trade->strategy_model_id !== null
                && $strategyModelId !== (int) $trade->strategy_model_id
            ) {
                throw ValidationException::withMessages([
                    'strategy_model_id' => ['strategy_model_id must match the trade strategy model.'],
                ]);
            }
            if ($strategyModelId === null && $trade->strategy_model_id !== null) {
                $strategyModelId = (int) $trade->strategy_model_id;
            }
        }

        if ($accountId !== null) {
            $owned = Account::query()
                ->whereKey($accountId)
                ->where('user_id', $userId)
                ->exists();
            if (! $owned) {
                throw ValidationException::withMessages([
                    'account_id' => ['account_id is outside your scope.'],
                ]);
            }
        }

        return [
            'account_id' => $accountId,
            'strategy_model_id' => $strategyModelId,
        ];
    }

    private function buildContextPayload(
        ?int $requestedAccountId,
        ?int $requestedStrategyModelId,
        ?string $resolvedScope,
        ?int $resolvedChecklistId,
        ?int $resolvedAccountId,
        ?int $resolvedStrategyModelId,
        ?int $tradeId
    ): array {
        return [
            'requested_account_id' => $requestedAccountId,
            'requested_strategy_model_id' => $requestedStrategyModelId,
            'resolved_scope' => $resolvedScope,
            'resolved_checklist_id' => $resolvedChecklistId,
            'resolved_account_id' => $resolvedAccountId,
            'resolved_strategy_model_id' => $resolvedStrategyModelId,
            'trade_id' => $tradeId,
        ];
    }
}
