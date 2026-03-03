<?php

namespace App\Services;

use App\Domain\Instruments\InstrumentMath;
use App\Domain\Instruments\InstrumentSpec;
use App\Exceptions\TradeConcurrencyException;
use App\Models\Checklist;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Models\User;
use App\Support\TradeRevision;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TradeExecutionOrchestrator
{
    private const UNTRUSTED_PRECHECK_METRIC_KEYS = [
        'risk_percent',
        'monetary_risk',
        'risk_amount',
        'lot_size',
        'position_size',
        'pip_value',
        'pip_size',
        'risk_per_unit',
        'reward_per_unit',
        'instrument_tick_value',
        'instrument_tick_size',
        'rr',
        'r_multiple',
        'realized_r_multiple',
    ];

    private const RULE_ENGINE_VERSION = 'orchestrator-v1';

    public function __construct(
        private readonly TradeCalculationEngine $calculationEngine,
        private readonly AccountBalanceService $accountBalanceService,
        private readonly TradeRiskPolicyService $tradeRiskPolicyService,
        private readonly ChecklistService $checklistService,
        private readonly TradeChecklistService $tradeChecklistService,
        private readonly CurrencyConversionService $currencyConversionService,
        private readonly InstrumentMath $instrumentMath
    ) {
    }

    /**
     * @param array<string,mixed> $dto
     * @return array{trade:Trade,checklist_gate:array<string,mixed>}
     */
    public function createTrade(array $dto, User $user): array
    {
        $userId = (int) $user->id;

        return DB::transaction(function () use ($dto, $user, $userId): array {
            $account = $this->resolveAccountForWrite((int) $dto['account_id'], $userId);
            $instrument = $this->resolveInstrumentForRead((int) $dto['instrument_id']);
            $payloadWithMetrics = $this->buildPayloadWithCalculatedFields($dto, $account, $instrument);
            $riskEvaluation = $this->evaluateRiskPolicy($payloadWithMetrics, $account, null, $user);
            $this->throwIfRiskPolicyBlocked($riskEvaluation);

            $checklistGate = $this->evaluateChecklistGateForPayload($userId, $payloadWithMetrics, null, $dto);
            $payloadWithMetrics['checklist_incomplete'] = $checklistGate['checklist_incomplete'];
            $payloadWithMetrics = [
                ...$payloadWithMetrics,
                ...$checklistGate['snapshot_attributes'],
            ];

            $legs = $this->normalizeLegsForWrite($payloadWithMetrics['legs'] ?? [], (string) $payloadWithMetrics['date']);
            $createdTrade = Trade::query()->create($this->extractTradeAttributes($payloadWithMetrics));
            if (count($legs) > 0) {
                $createdTrade->legs()->createMany($legs);
            }

            $this->syncTradeTags($createdTrade, $this->extractTagIds($payloadWithMetrics));
            $this->persistChecklistState(
                $createdTrade,
                $checklistGate['checklist'],
                $checklistGate['responses'],
                $checklistGate['checklist_incomplete'],
                $checklistGate['responses_from_request'],
                $checklistGate['snapshot_frozen'],
                $checklistGate['precheck_metrics']
            );

            $this->accountBalanceService->rebuildAccountState((int) $account->id);
            $this->writeRuleExecutionArtifact($createdTrade, $checklistGate, $riskEvaluation, $payloadWithMetrics, 'create');
            Log::info('Trade create committed via orchestrator.', ['trade_id' => (int) $createdTrade->id]);

            return [
                'trade' => $createdTrade->fresh(['account', 'instrument', 'strategyModel', 'setup', 'killzone', 'tags', 'legs']),
                'checklist_gate' => $checklistGate,
            ];
        });
    }

    /**
     * @param array<string,mixed> $dto
     * @return array{trade:Trade,checklist_gate:array<string,mixed>}
     */
    public function updateTrade(int $tradeId, array $dto, User $user, ?string $ifMatch = null): array
    {
        $userId = (int) $user->id;

        return DB::transaction(function () use ($tradeId, $dto, $user, $userId, $ifMatch): array {
            /** @var Trade $trade */
            $trade = Trade::query()->whereKey($tradeId)->lockForUpdate()->firstOrFail();
            $this->assertTradeOwnership($trade, $userId);
            $this->assertTradeConcurrency($trade, $ifMatch);

            $previousAccountId = (int) $trade->account_id;
            $effectivePayload = $this->normalizePrecheckPayload($dto, $trade);
            $account = $this->resolveAccountForWrite((int) $effectivePayload['account_id'], $userId);
            $instrument = $this->resolveInstrumentForRead((int) $effectivePayload['instrument_id']);
            $payloadWithMetrics = $this->buildPayloadWithCalculatedFields($effectivePayload, $account, $instrument);
            $riskEvaluation = $this->evaluateRiskPolicy($payloadWithMetrics, $account, (int) $trade->id, $user);
            $this->throwIfRiskPolicyBlocked($riskEvaluation);

            $checklistGate = $this->evaluateChecklistGateForPayload($userId, $payloadWithMetrics, $trade, $dto);
            $payloadWithMetrics['checklist_incomplete'] = $checklistGate['checklist_incomplete'];
            $payloadWithMetrics = [
                ...$payloadWithMetrics,
                ...$checklistGate['snapshot_attributes'],
            ];

            $nextRevision = (int) $trade->revision + 1;
            $trade->update([
                ...$this->extractTradeAttributes($payloadWithMetrics),
                'revision' => $nextRevision,
            ]);

            if (array_key_exists('legs', $dto)) {
                $legs = $this->normalizeLegsForWrite($payloadWithMetrics['legs'] ?? [], (string) $payloadWithMetrics['date']);
                $trade->legs()->delete();
                if (count($legs) > 0) {
                    $trade->legs()->createMany($legs);
                }
            }

            $this->syncTradeTags($trade, $this->extractTagIds($payloadWithMetrics));
            $this->persistChecklistState(
                $trade,
                $checklistGate['checklist'],
                $checklistGate['responses'],
                $checklistGate['checklist_incomplete'],
                $checklistGate['responses_from_request'],
                $checklistGate['snapshot_frozen'],
                $checklistGate['precheck_metrics']
            );

            $this->accountBalanceService->rebuildMany([$previousAccountId, (int) $trade->account_id]);
            $this->writeRuleExecutionArtifact($trade, $checklistGate, $riskEvaluation, $payloadWithMetrics, 'update');
            Log::info('Trade update committed via orchestrator.', ['trade_id' => (int) $trade->id]);

            return [
                'trade' => $trade->fresh(['account', 'instrument', 'strategyModel', 'setup', 'killzone', 'tags', 'legs']),
                'checklist_gate' => $checklistGate,
            ];
        });
    }

    /**
     * @param array<string,mixed> $legOpsDto
     * @return array{
     *   trade:Trade,
     *   checklist_gate:array<string,mixed>,
     *   operation_result:array{created:array<int,TradeLeg>,updated:array<int,TradeLeg>,deleted:array<int,int>}
     * }
     */
    public function mutateLegs(int $tradeId, array $legOpsDto, User $user, ?string $ifMatch = null): array
    {
        $userId = (int) $user->id;

        return DB::transaction(function () use ($tradeId, $legOpsDto, $user, $userId, $ifMatch): array {
            /** @var Trade $trade */
            $trade = Trade::query()
                ->whereKey($tradeId)
                ->with(['legs' => fn ($query) => $query->orderBy('executed_at')->orderBy('id'), 'tags'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTradeOwnership($trade, $userId);
            $this->assertTradeConcurrency($trade, $ifMatch);

            $operations = $this->normalizeLegOperations($legOpsDto['operations'] ?? []);
            if (count($operations) === 0) {
                throw ValidationException::withMessages([
                    'operations' => ['At least one leg operation is required.'],
                ]);
            }

            $stagedLegs = $this->stageLegMutationsForEvaluation($trade, $operations);
            $effectivePayload = $this->payloadFromTradeForLegMutation($trade, $stagedLegs);
            $account = $this->resolveAccountForWrite((int) $effectivePayload['account_id'], $userId);
            $instrument = $this->resolveInstrumentForRead((int) $effectivePayload['instrument_id']);
            $payloadWithMetrics = $this->buildPayloadWithCalculatedFields($effectivePayload, $account, $instrument);
            $riskEvaluation = $this->evaluateRiskPolicy($payloadWithMetrics, $account, (int) $trade->id, $user);
            $this->throwIfRiskPolicyBlocked($riskEvaluation);

            $checklistGate = $this->evaluateChecklistGateForPayload($userId, $payloadWithMetrics, $trade, $effectivePayload);
            $payloadWithMetrics['checklist_incomplete'] = $checklistGate['checklist_incomplete'];
            $payloadWithMetrics = [
                ...$payloadWithMetrics,
                ...$checklistGate['snapshot_attributes'],
            ];

            $operationResult = $this->persistLegOperations($trade, $operations);
            $nextRevision = (int) $trade->revision + 1;
            $trade->update([
                ...$this->extractTradeAttributes($payloadWithMetrics),
                'revision' => $nextRevision,
            ]);
            $this->persistChecklistState(
                $trade,
                $checklistGate['checklist'],
                $checklistGate['responses'],
                $checklistGate['checklist_incomplete'],
                $checklistGate['responses_from_request'],
                $checklistGate['snapshot_frozen'],
                $checklistGate['precheck_metrics']
            );

            $this->accountBalanceService->rebuildAccountState((int) $trade->account_id);
            $this->writeRuleExecutionArtifact($trade, $checklistGate, $riskEvaluation, $payloadWithMetrics, 'mutate_legs');
            Log::info('Trade leg mutation committed via orchestrator.', ['trade_id' => (int) $trade->id]);

            return [
                'trade' => $trade->fresh(['account', 'instrument', 'strategyModel', 'setup', 'killzone', 'tags', 'legs']),
                'checklist_gate' => $checklistGate,
                'operation_result' => $operationResult,
            ];
        });
    }

    private function assertTradeOwnership(Trade $trade, int $userId): void
    {
        $owned = Trade::query()
            ->whereKey((int) $trade->id)
            ->whereHas('account', fn ($query) => $query->where('user_id', $userId))
            ->exists();

        if (! $owned) {
            abort(403, 'Unauthorized trade access.');
        }
    }

    /**
     * @throws TradeConcurrencyException
     */
    private function assertTradeConcurrency(Trade $trade, ?string $ifMatch): void
    {
        $currentRevision = (int) $trade->revision;
        $currentUpdatedAt = $trade->updated_at?->toISOString() ?? '';
        $currentEtag = $this->buildTradeEtag($trade);
        $expectedRevision = $this->extractExpectedRevision($ifMatch);

        if ($expectedRevision === null) {
            throw new TradeConcurrencyException(
                $currentRevision,
                $currentUpdatedAt,
                $currentEtag,
                'If-Match header with current trade revision is required.'
            );
        }

        if ($expectedRevision !== $currentRevision) {
            throw new TradeConcurrencyException(
                $currentRevision,
                $currentUpdatedAt,
                $currentEtag
            );
        }
    }

    private function extractExpectedRevision(?string $ifMatch): ?int
    {
        return TradeRevision::extractExpectedRevision($ifMatch);
    }

    public function buildTradeEtag(Trade $trade): string
    {
        return TradeRevision::buildEtag($trade);
    }

    /**
     * @param array<int,mixed> $rawOperations
     * @return array<int,array{action:string,trade_leg_id:int|null,payload:array<string,mixed>}>
     */
    private function normalizeLegOperations(array $rawOperations): array
    {
        $normalized = [];

        foreach ($rawOperations as $row) {
            $op = is_array($row)
                ? $row
                : (is_object($row) ? (array) $row : null);

            if ($op === null) {
                continue;
            }

            $action = strtolower(trim((string) ($op['action'] ?? '')));
            if (! in_array($action, ['add', 'update', 'delete'], true)) {
                throw ValidationException::withMessages([
                    'operations' => ['Unsupported leg mutation action supplied.'],
                ]);
            }

            $tradeLegId = isset($op['trade_leg_id']) && is_numeric($op['trade_leg_id'])
                ? (int) $op['trade_leg_id']
                : null;

            if (in_array($action, ['update', 'delete'], true) && ($tradeLegId === null || $tradeLegId <= 0)) {
                throw ValidationException::withMessages([
                    'trade_leg_id' => ['trade_leg_id is required for update/delete operations.'],
                ]);
            }

            $payload = [];
            if (in_array($action, ['add', 'update'], true)) {
                $rawPayload = $op['payload'] ?? null;
                if (! is_array($rawPayload)) {
                    throw ValidationException::withMessages([
                        'payload' => ['payload is required for add/update operations.'],
                    ]);
                }
                $payload = $this->normalizeLegPayload($rawPayload);
            }

            $normalized[] = [
                'action' => $action,
                'trade_leg_id' => $tradeLegId,
                'payload' => $payload,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{leg_type:string,price:float,quantity_lots:float,executed_at:string,fees:float,notes:string|null}
     */
    private function normalizeLegPayload(array $payload): array
    {
        return [
            'leg_type' => strtolower((string) ($payload['leg_type'] ?? '')),
            'price' => (float) ($payload['price'] ?? 0),
            'quantity_lots' => (float) ($payload['quantity_lots'] ?? 0),
            'executed_at' => (string) ($payload['executed_at'] ?? now()->toIso8601String()),
            'fees' => (float) ($payload['fees'] ?? 0),
            'notes' => isset($payload['notes']) && $payload['notes'] !== null
                ? (string) $payload['notes']
                : null,
        ];
    }

    /**
     * @param array<int,array{action:string,trade_leg_id:int|null,payload:array<string,mixed>}> $operations
     * @return array<int,array{leg_type:string,price:float,quantity_lots:float,executed_at:string,fees:float,notes:string|null}>
     */
    private function stageLegMutationsForEvaluation(Trade $trade, array $operations): array
    {
        $staged = $trade->legs
            ->map(fn (TradeLeg $leg): array => [
                'id' => (int) $leg->id,
                'leg_type' => (string) $leg->leg_type,
                'price' => (float) $leg->price,
                'quantity_lots' => (float) $leg->quantity_lots,
                'executed_at' => (string) $leg->executed_at,
                'fees' => (float) ($leg->fees ?? 0),
                'notes' => $leg->notes,
            ])
            ->keyBy('id')
            ->all();

        $tempId = -1;

        foreach ($operations as $operation) {
            $action = $operation['action'];
            if ($action === 'add') {
                $payload = $operation['payload'];
                $staged[$tempId] = [
                    'id' => $tempId,
                    'leg_type' => (string) $payload['leg_type'],
                    'price' => (float) $payload['price'],
                    'quantity_lots' => (float) $payload['quantity_lots'],
                    'executed_at' => (string) $payload['executed_at'],
                    'fees' => (float) ($payload['fees'] ?? 0),
                    'notes' => $payload['notes'] ?? null,
                ];
                $tempId--;

                continue;
            }

            $tradeLegId = (int) ($operation['trade_leg_id'] ?? 0);
            if (! array_key_exists($tradeLegId, $staged)) {
                throw ValidationException::withMessages([
                    'trade_leg_id' => ['Selected trade leg does not belong to this trade.'],
                ]);
            }

            if ($action === 'delete') {
                unset($staged[$tradeLegId]);

                continue;
            }

            $payload = $operation['payload'];
            $existing = $staged[$tradeLegId];
            $staged[$tradeLegId] = [
                ...$existing,
                'leg_type' => (string) $payload['leg_type'],
                'price' => (float) $payload['price'],
                'quantity_lots' => (float) $payload['quantity_lots'],
                'executed_at' => (string) $payload['executed_at'],
                'fees' => (float) ($payload['fees'] ?? 0),
                'notes' => $payload['notes'] ?? null,
            ];
        }

        $rows = array_values($staged);
        usort($rows, function (array $left, array $right): int {
            $cmp = strcmp((string) ($left['executed_at'] ?? ''), (string) ($right['executed_at'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return array_values(array_map(fn (array $row): array => [
            'leg_type' => (string) $row['leg_type'],
            'price' => (float) $row['price'],
            'quantity_lots' => (float) $row['quantity_lots'],
            'executed_at' => (string) $row['executed_at'],
            'fees' => (float) ($row['fees'] ?? 0),
            'notes' => isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
        ], $rows));
    }

    /**
     * @param array<int,array{action:string,trade_leg_id:int|null,payload:array<string,mixed>}> $operations
     * @return array{created:array<int,TradeLeg>,updated:array<int,TradeLeg>,deleted:array<int,int>}
     */
    private function persistLegOperations(Trade $trade, array $operations): array
    {
        $created = [];
        $updated = [];
        $deleted = [];

        foreach ($operations as $operation) {
            $action = $operation['action'];
            if ($action === 'add') {
                $created[] = $trade->legs()->create($operation['payload'])->fresh();

                continue;
            }

            $tradeLegId = (int) ($operation['trade_leg_id'] ?? 0);
            /** @var TradeLeg|null $leg */
            $leg = $trade->legs()->whereKey($tradeLegId)->first();
            if ($leg === null) {
                throw ValidationException::withMessages([
                    'trade_leg_id' => ['Selected trade leg does not belong to this trade.'],
                ]);
            }

            if ($action === 'delete') {
                $leg->delete();
                $deleted[] = $tradeLegId;

                continue;
            }

            $leg->update($operation['payload']);
            $updated[] = $leg->fresh();
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }

    /**
     * @param array<int,array{leg_type:string,price:float,quantity_lots:float,executed_at:string,fees:float,notes:string|null}> $legs
     * @return array<string,mixed>
     */
    private function payloadFromTradeForLegMutation(Trade $trade, array $legs): array
    {
        $tagIds = $trade->tags
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return [
            'account_id' => (int) $trade->account_id,
            'instrument_id' => (int) $trade->instrument_id,
            'strategy_model_id' => $trade->strategy_model_id !== null ? (int) $trade->strategy_model_id : null,
            'setup_id' => $trade->setup_id !== null ? (int) $trade->setup_id : null,
            'killzone_id' => $trade->killzone_id !== null ? (int) $trade->killzone_id : null,
            'session_enum' => $trade->session_enum !== null ? (string) $trade->session_enum : null,
            'tag_ids' => $tagIds,
            'pair' => (string) $trade->pair,
            'direction' => (string) $trade->direction,
            'entry_price' => (float) $trade->entry_price,
            'stop_loss' => (float) $trade->stop_loss,
            'take_profit' => (float) $trade->take_profit,
            'actual_exit_price' => (float) $trade->actual_exit_price,
            'lot_size' => (float) $trade->lot_size,
            'commission' => (float) ($trade->commission ?? 0),
            'swap' => (float) ($trade->swap ?? 0),
            'spread_cost' => (float) ($trade->spread_cost ?? 0),
            'slippage_cost' => (float) ($trade->slippage_cost ?? 0),
            'followed_rules' => (bool) $trade->followed_rules,
            'checklist_incomplete' => (bool) ($trade->checklist_incomplete ?? false),
            'executed_checklist_id' => $trade->executed_checklist_id !== null ? (int) $trade->executed_checklist_id : null,
            'executed_checklist_version' => $trade->executed_checklist_version !== null ? (int) $trade->executed_checklist_version : null,
            'executed_enforcement_mode' => $trade->executed_enforcement_mode !== null ? (string) $trade->executed_enforcement_mode : null,
            'failed_rule_ids' => is_array($trade->failed_rule_ids) ? $trade->failed_rule_ids : [],
            'failed_rule_titles' => is_array($trade->failed_rule_titles) ? $trade->failed_rule_titles : [],
            'check_evaluated_at' => $trade->check_evaluated_at !== null ? (string) $trade->check_evaluated_at : null,
            'emotion' => (string) $trade->emotion,
            'risk_override_reason' => $trade->risk_override_reason,
            'session' => (string) $trade->session,
            'model' => (string) $trade->model,
            'date' => (string) $trade->date,
            'notes' => $trade->notes,
            'legs' => $legs,
        ];
    }

    /**
     * @param  array<string,mixed>  $payloadWithMetrics
     * @param  object{id:int,starting_balance:numeric-string|int|float,current_balance:numeric-string|int|float}  $account
     * @return array{
     *   allowed:bool,
     *   requires_override_reason:bool,
     *   policy:array<string,mixed>,
     *   violations:array<int,array{code:string,message:string,limit:float,actual:float}>,
     *   stats:array<string,float>
     * }
     */
    private function evaluateRiskPolicy(array $payloadWithMetrics, object $account, ?int $excludeTradeId, User $user): array
    {
        return $this->tradeRiskPolicyService->evaluate([
            'account_id' => (int) $account->id,
            'account_starting_balance' => (float) $account->starting_balance,
            'account_current_balance' => (float) $account->current_balance,
            'risk_percent' => (float) $payloadWithMetrics['risk_percent'],
            'monetary_risk' => (float) $payloadWithMetrics['monetary_risk'],
            'risk_override_reason' => $payloadWithMetrics['risk_override_reason'] ?? null,
            'trade_date' => (string) ($payloadWithMetrics['date'] ?? CarbonImmutable::now()->toIso8601String()),
            'exclude_trade_id' => $excludeTradeId,
            'actor_role' => method_exists($user, 'roleName')
                ? $user->roleName()
                : (string) ($user->role ?? 'trader'),
        ]);
    }

    /**
     * @param array{
     *   allowed:bool,
     *   requires_override_reason:bool,
     *   violations:array<int,array{code:string,message:string,limit:float,actual:float}>
     * } $evaluation
     */
    private function throwIfRiskPolicyBlocked(array $evaluation): void
    {
        if ((bool) $evaluation['allowed']) {
            return;
        }

        Log::warning('Trade rejected by risk policy.', [
            'requires_override_reason' => (bool) ($evaluation['requires_override_reason'] ?? false),
            'violations' => $evaluation['violations'] ?? [],
            'policy' => $evaluation['policy'] ?? [],
            'stats' => $evaluation['stats'] ?? [],
        ]);

        $messages = collect($evaluation['violations'] ?? [])
            ->map(fn (array $violation): string => (string) ($violation['message'] ?? 'Risk policy violation.'))
            ->values()
            ->all();

        if ((bool) ($evaluation['requires_override_reason'] ?? false)) {
            throw ValidationException::withMessages([
                'risk_override_reason' => ['Override reason is required to bypass account risk policy.'],
                'risk_policy' => $messages,
            ]);
        }

        throw ValidationException::withMessages([
            'risk_policy' => $messages,
        ]);
    }

    /**
     * @param  array<string,mixed>  $contextPayload
     * @param  array<string,mixed>  $responseSourcePayload
     * @return array{
     *   checklist:Checklist|null,
     *   responses:array<int,array{checklist_item_id:int,value:mixed}>,
     *   precheck_metrics:array<string,float>,
     *   responses_from_request:bool,
     *   snapshot_frozen:bool,
     *   checklist_incomplete:bool,
     *   snapshot_attributes:array<string,mixed>,
     *   readiness:array<string,mixed>,
     *   failing_rules:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>,
     *   failed_required_rule_ids:array<int,int>,
     *   failed_rule_reasons:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>
     * }
     */
    private function evaluateChecklistGateForPayload(
        int $userId,
        array $contextPayload,
        ?Trade $existingTrade = null,
        array $responseSourcePayload = []
    ): array {
        $responsesFromRequest = array_key_exists('checklist_responses', $responseSourcePayload);
        $frozenExistingSnapshot = $existingTrade !== null && $this->hasFrozenChecklistSnapshot($existingTrade);

        $checklist = null;
        $enforcementMode = 'off';
        $checklistVersion = null;

        if ($frozenExistingSnapshot) {
            $frozenChecklistId = $existingTrade->executed_checklist_id !== null
                ? (int) $existingTrade->executed_checklist_id
                : null;
            $checklist = $frozenChecklistId !== null
                ? Checklist::query()->whereKey($frozenChecklistId)->first()
                : null;
            $enforcementMode = (string) ($existingTrade->executed_enforcement_mode ?? ($checklist?->enforcement_mode ?? 'off'));
            $checklistVersion = $existingTrade->executed_checklist_version !== null
                ? (int) $existingTrade->executed_checklist_version
                : ($checklist !== null ? (int) ($checklist->revision ?? 1) : null);
        } else {
            $accountId = isset($contextPayload['account_id']) && is_numeric($contextPayload['account_id'])
                ? (int) $contextPayload['account_id']
                : null;
            $strategyModelId = isset($contextPayload['strategy_model_id']) && is_numeric($contextPayload['strategy_model_id'])
                ? (int) $contextPayload['strategy_model_id']
                : null;

            $resolved = $this->checklistService->resolveApplicableChecklistWithContext(
                $userId,
                $accountId,
                $strategyModelId
            );

            /** @var Checklist|null $checklist */
            $checklist = $resolved['checklist'];
            $enforcementMode = $checklist !== null
                ? (string) $checklist->enforcement_mode
                : 'off';
            $checklistVersion = $checklist !== null
                ? (int) ($checklist->revision ?? 1)
                : null;
        }

        $checklistIdForResponses = $checklist !== null
            ? (int) $checklist->id
            : ($existingTrade?->executed_checklist_id !== null ? (int) $existingTrade->executed_checklist_id : 0);
        $responses = $this->resolveChecklistResponsesForGate(
            $responseSourcePayload,
            $existingTrade,
            $checklistIdForResponses
        );
        $precheckMetrics = $this->buildChecklistPrecheckMetrics($contextPayload, $responseSourcePayload);

        $evaluation = [
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
        ];

        if ($checklist !== null) {
            $evaluation = $this->tradeChecklistService->evaluateDraftReadiness(
                $checklist,
                $responses,
                true,
                $precheckMetrics
            );
        }

        $failingRules = $evaluation['failing_rules'];
        $failedRequiredRuleIds = $evaluation['failed_required_rule_ids'] ?? [];
        $failedRuleReasons = $evaluation['failed_rule_reasons'] ?? [];
        $ready = $enforcementMode === 'off'
            ? true
            : (bool) ($evaluation['readiness']['ready'] ?? false);

        if ($enforcementMode === 'strict' && $checklist === null) {
            $ready = false;
            $failingRules = [[
                'checklist_item_id' => 0,
                'title' => 'Frozen checklist unavailable',
                'category' => 'Checklist',
                'reason' => 'Checklist snapshot could not be resolved.',
            ]];
            $failedRequiredRuleIds = [0];
            $failedRuleReasons = [[
                'checklist_item_id' => 0,
                'title' => 'Frozen checklist unavailable',
                'category' => 'Checklist',
                'reason' => 'Checklist snapshot could not be resolved.',
            ]];
        }

        if ($enforcementMode === 'strict' && ! $ready) {
            $this->throwChecklistStrictBlocked(
                $checklist,
                $failingRules,
                $enforcementMode,
                $checklistVersion,
                $failedRequiredRuleIds,
                $failedRuleReasons
            );
        }

        $snapshotAttributes = $frozenExistingSnapshot
            ? $this->snapshotAttributesFromExistingTrade($existingTrade)
            : [
                'executed_checklist_id' => $checklist !== null ? (int) $checklist->id : null,
                'executed_checklist_version' => $checklistVersion,
                'executed_enforcement_mode' => $enforcementMode,
                'failed_rule_ids' => array_values(array_map(
                    fn (array $row): int => (int) $row['checklist_item_id'],
                    $failingRules
                )),
                'failed_rule_titles' => array_values(array_map(
                    fn (array $row): string => (string) ($row['title'] ?? 'Required rule'),
                    $failingRules
                )),
                'check_evaluated_at' => now()->toIso8601String(),
            ];

        return [
            'checklist' => $checklist,
            'responses' => $responses,
            'precheck_metrics' => $precheckMetrics,
            'responses_from_request' => $responsesFromRequest,
            'snapshot_frozen' => $frozenExistingSnapshot,
            'checklist_incomplete' => $frozenExistingSnapshot
                ? (bool) ($existingTrade?->checklist_incomplete ?? false)
                : (! $ready),
            'snapshot_attributes' => $snapshotAttributes,
            'readiness' => $evaluation['readiness'],
            'failing_rules' => $failingRules,
            'failed_required_rule_ids' => $failedRequiredRuleIds,
            'failed_rule_reasons' => $failedRuleReasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,array{checklist_item_id:int,value:mixed}>
     */
    private function resolveChecklistResponsesForGate(array $payload, ?Trade $existingTrade, int $checklistId): array
    {
        if (array_key_exists('checklist_responses', $payload)) {
            return $this->normalizeChecklistResponses($payload['checklist_responses'] ?? []);
        }

        if ($existingTrade === null) {
            return [];
        }

        return $existingTrade->checklistResponses()
            ->where('checklist_id', $checklistId)
            ->orderBy('id')
            ->get(['checklist_item_id', 'value'])
            ->map(fn ($response): array => [
                'checklist_item_id' => (int) $response->checklist_item_id,
                'value' => $response->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{checklist_item_id:int,value:mixed}>
     */
    private function normalizeChecklistResponses(mixed $rawResponses): array
    {
        if (! is_array($rawResponses)) {
            return [];
        }

        $rowsByItemId = [];
        foreach ($rawResponses as $row) {
            $entry = is_array($row) ? $row : (is_object($row) ? (array) $row : null);
            if ($entry === null) {
                continue;
            }

            $itemId = (int) ($entry['checklist_item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $rowsByItemId[$itemId] = [
                'checklist_item_id' => $itemId,
                'value' => $entry['value'] ?? null,
            ];
        }

        return array_values($rowsByItemId);
    }

    /**
     * @param  array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>  $failingRules
     * @param  array<int,int>  $failedRequiredRuleIds
     * @param  array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>  $failedRuleReasons
     */
    private function throwChecklistStrictBlocked(
        ?Checklist $checklist,
        array $failingRules,
        ?string $enforcementMode = null,
        ?int $checklistVersion = null,
        array $failedRequiredRuleIds = [],
        array $failedRuleReasons = []
    ): void {
        Log::warning('Trade rejected by strict checklist enforcement.', [
            'checklist_id' => $checklist !== null ? (int) $checklist->id : null,
            'checklist_scope' => $checklist !== null ? (string) $checklist->scope : null,
            'checklist_version' => $checklistVersion ?? ($checklist !== null ? (int) ($checklist->revision ?? 1) : null),
            'enforcement_mode' => $enforcementMode ?? ($checklist !== null ? (string) $checklist->enforcement_mode : 'strict'),
            'failed_required_rule_ids' => $failedRequiredRuleIds,
            'failed_rule_reasons' => $failedRuleReasons,
        ]);

        throw new HttpResponseException(
            response()->json([
                'message' => 'Checklist strict validation failed.',
                'errors' => [
                    'checklist' => ['Complete all required checklist rules before saving this trade.'],
                ],
                'failing_rules' => $failingRules,
                'failed_required_rule_ids' => $failedRequiredRuleIds,
                'failed_rule_reasons' => $failedRuleReasons,
                'failedRequiredRuleIds' => $failedRequiredRuleIds,
                'failedRuleReasons' => $failedRuleReasons,
                'checklist' => [
                    'id' => $checklist !== null ? (int) $checklist->id : null,
                    'scope' => $checklist !== null ? (string) $checklist->scope : null,
                    'revision' => $checklistVersion ?? ($checklist !== null ? (int) ($checklist->revision ?? 1) : null),
                    'enforcement_mode' => $enforcementMode ?? ($checklist !== null ? (string) $checklist->enforcement_mode : 'strict'),
                    'account_id' => $checklist !== null && $checklist->account_id !== null ? (int) $checklist->account_id : null,
                    'strategy_model_id' => $checklist !== null && $checklist->strategy_model_id !== null ? (int) $checklist->strategy_model_id : null,
                ],
            ], 422)
        );
    }

    private function hasFrozenChecklistSnapshot(Trade $trade): bool
    {
        return $trade->executed_checklist_id !== null
            || $trade->executed_checklist_version !== null
            || $trade->executed_enforcement_mode !== null
            || $trade->check_evaluated_at !== null;
    }

    /**
     * @return array{
     *   executed_checklist_id:int|null,
     *   executed_checklist_version:int|null,
     *   executed_enforcement_mode:string|null,
     *   failed_rule_ids:array<int,int>,
     *   failed_rule_titles:array<int,string>,
     *   check_evaluated_at:string|null
     * }
     */
    private function snapshotAttributesFromExistingTrade(?Trade $trade): array
    {
        if ($trade === null) {
            return [
                'executed_checklist_id' => null,
                'executed_checklist_version' => null,
                'executed_enforcement_mode' => null,
                'failed_rule_ids' => [],
                'failed_rule_titles' => [],
                'check_evaluated_at' => null,
            ];
        }

        $failedRuleIds = is_array($trade->failed_rule_ids)
            ? array_values(array_map(fn ($id): int => (int) $id, $trade->failed_rule_ids))
            : [];
        $failedRuleTitles = is_array($trade->failed_rule_titles)
            ? array_values(array_map(fn ($title): string => (string) $title, $trade->failed_rule_titles))
            : [];

        return [
            'executed_checklist_id' => $trade->executed_checklist_id !== null ? (int) $trade->executed_checklist_id : null,
            'executed_checklist_version' => $trade->executed_checklist_version !== null ? (int) $trade->executed_checklist_version : null,
            'executed_enforcement_mode' => $trade->executed_enforcement_mode !== null ? (string) $trade->executed_enforcement_mode : null,
            'failed_rule_ids' => $failedRuleIds,
            'failed_rule_titles' => $failedRuleTitles,
            'check_evaluated_at' => $trade->check_evaluated_at !== null ? (string) $trade->check_evaluated_at : null,
        ];
    }

    /**
     * @param  array<int,array{checklist_item_id:int,value:mixed}>  $responses
     * @param  array<string,float>  $precheckMetrics
     */
    private function persistChecklistState(
        Trade $trade,
        ?Checklist $checklist,
        array $responses,
        bool $checklistIncomplete,
        bool $responsesFromRequest,
        bool $snapshotFrozen,
        array $precheckMetrics = []
    ): void {
        if ($snapshotFrozen) {
            return;
        }

        if ($checklist === null) {
            $this->checklistService->syncChecklistIncompleteFlag($trade, $checklistIncomplete);

            return;
        }

        if ($responsesFromRequest && count($responses) > 0) {
            $result = $this->tradeChecklistService->upsertResponses($trade, $checklist, $responses, $precheckMetrics);
            $this->checklistService->syncChecklistIncompleteFlag($trade, ! (bool) ($result['readiness']['ready'] ?? true));

            return;
        }

        $this->checklistService->syncChecklistIncompleteFlag($trade, $checklistIncomplete);
    }

    /**
     * @param  array<string,mixed>  $contextPayload
     * @param  array<string,mixed>  $responseSourcePayload
     * @return array<string,float>
     */
    private function buildChecklistPrecheckMetrics(array $contextPayload, array $responseSourcePayload): array
    {
        $metrics = [];

        foreach ($contextPayload as $key => $value) {
            if (! is_string($key) || ! is_numeric($value)) {
                continue;
            }
            $metrics[$key] = (float) $value;
            $metrics[strtolower($key)] = (float) $value;
        }

        $this->logIgnoredPrecheckSnapshotMetrics($responseSourcePayload, $contextPayload);

        if (! array_key_exists('risk_amount', $metrics) && array_key_exists('monetary_risk', $metrics)) {
            $metrics['risk_amount'] = (float) $metrics['monetary_risk'];
        }
        if (! array_key_exists('monetary_risk', $metrics) && array_key_exists('risk_amount', $metrics)) {
            $metrics['monetary_risk'] = (float) $metrics['risk_amount'];
        }

        if (! array_key_exists('realized_r_multiple', $metrics) && array_key_exists('r_multiple', $metrics)) {
            $metrics['realized_r_multiple'] = (float) $metrics['r_multiple'];
        }
        if (! array_key_exists('r_multiple', $metrics) && array_key_exists('realized_r_multiple', $metrics)) {
            $metrics['r_multiple'] = (float) $metrics['realized_r_multiple'];
        }

        return $metrics;
    }

    /**
     * @param  array<string,mixed>  $responseSourcePayload
     * @param  array<string,mixed>  $contextPayload
     */
    private function logIgnoredPrecheckSnapshotMetrics(array $responseSourcePayload, array $contextPayload): void
    {
        $snapshot = $responseSourcePayload['precheck_snapshot'] ?? null;
        if (! is_array($snapshot)) {
            return;
        }

        $ignored = [];
        foreach (array_keys($snapshot) as $key) {
            if (! is_string($key)) {
                continue;
            }

            $normalized = strtolower(trim($key));
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, self::UNTRUSTED_PRECHECK_METRIC_KEYS, true)) {
                $ignored[] = $normalized;
            }
        }

        if (count($ignored) === 0) {
            return;
        }

        Log::warning('Ignored client-supplied precheck_snapshot metric fields during checklist enforcement.', [
            'ignored_fields' => array_values(array_unique($ignored)),
            'account_id' => isset($contextPayload['account_id']) && is_numeric($contextPayload['account_id'])
                ? (int) $contextPayload['account_id']
                : null,
            'trade_date' => isset($contextPayload['date']) ? (string) $contextPayload['date'] : null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  object{id:int,starting_balance:numeric-string|int|float,current_balance:numeric-string|int|float,currency:string}  $account
     * @param  object{id:int,tick_size:numeric-string|int|float,tick_value:numeric-string|int|float,contract_size:numeric-string|int|float,symbol:string,quote_currency:string,base_currency:string}  $instrument
     * @return array<string,mixed>
     */
    private function buildPayloadWithCalculatedFields(array $payload, object $account, object $instrument): array
    {
        $tradeDate = $this->parsePayloadDate((string) ($payload['date'] ?? CarbonImmutable::now()->toIso8601String()));
        $valuationContext = $this->resolveInstrumentValuationContext($instrument, $account, $tradeDate);
        $accountCurrency = strtoupper(trim((string) ($account->currency ?? 'USD')));

        $normalizedLegs = $this->normalizeLegsForCalculation(
            $payload['legs'] ?? null,
            (string) ($payload['date'] ?? CarbonImmutable::now()->toIso8601String())
        );
        $legSummary = $this->summarizeLegs($normalizedLegs);

        $prepared = [
            ...$payload,
            'pair' => strtoupper((string) $instrument->symbol),
            'entry_price' => (float) ($payload['entry_price'] ?? 0),
            'actual_exit_price' => (float) ($payload['actual_exit_price'] ?? ($payload['entry_price'] ?? 0)),
            'lot_size' => (float) ($payload['lot_size'] ?? 0),
            'account_balance_before_trade' => (float) $account->current_balance,
            'account_currency' => $accountCurrency,
            'instrument_tick_size' => (float) $instrument->tick_size,
            'instrument_tick_value' => $valuationContext['tick_value_in_account_currency'],
            'instrument_contract_size' => (float) $instrument->contract_size,
            'instrument_quote_to_account_rate' => $valuationContext['quote_to_account_rate'],
            'instrument_quote_currency' => strtoupper(trim((string) $instrument->quote_currency)),
            'instrument_base_currency' => strtoupper(trim((string) ($instrument->base_currency ?? ''))),
            'instrument_rounding_policy' => 'half_up_6',
            'commission' => (float) ($payload['commission'] ?? 0),
            'swap' => (float) ($payload['swap'] ?? 0),
            'spread_cost' => (float) ($payload['spread_cost'] ?? 0),
            'slippage_cost' => (float) ($payload['slippage_cost'] ?? 0),
            'fx_rate_quote_to_usd' => $valuationContext['fx_rate_quote_to_usd'],
            'fx_symbol_used' => $valuationContext['fx_symbol_used'],
            'fx_rate_timestamp' => $valuationContext['fx_rate_provenance_at'] ?? $tradeDate->toIso8601String(),
            'fx_rate_used' => $valuationContext['fx_rate_used'],
            'fx_pair_used' => $valuationContext['fx_pair_used'],
            'fx_rate_provenance_at' => $valuationContext['fx_rate_provenance_at'],
            'legs' => $normalizedLegs,
            'tag_ids' => $this->extractTagIds($payload),
        ];

        $prepared = $this->applyTaxonomyLabels($prepared);

        $calculated = $this->calculationEngine->calculate($prepared);
        $lotSizeFromLegs = $legSummary['entry_quantity'] > 0
            ? $legSummary['entry_quantity']
            : (float) ($payload['lot_size'] ?? 0);

        return [
            ...$prepared,
            ...$calculated,
            'entry_price' => (float) $calculated['avg_entry_price'],
            'actual_exit_price' => (float) $calculated['avg_exit_price'],
            'lot_size' => $lotSizeFromLegs,
            'avg_entry_price' => (float) $calculated['avg_entry_price'],
            'avg_exit_price' => (float) $calculated['avg_exit_price'],
            'r_multiple' => (float) $calculated['realized_r_multiple'],
            'realized_r_multiple' => (float) $calculated['realized_r_multiple'],
            'risk_amount_account_currency' => (float) $calculated['monetary_risk'],
            'risk_currency' => $accountCurrency,
        ];
    }

    private function parsePayloadDate(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }

    /**
     * @param  object{tick_size:numeric-string|int|float,tick_value:numeric-string|int|float,contract_size:numeric-string|int|float,quote_currency:string,base_currency:string,symbol:string}  $instrument
     * @param  object{currency:string}  $account
     * @return array{
     *   tick_value_in_account_currency:float,
     *   quote_to_account_rate:float,
     *   fx_rate_quote_to_usd:float|null,
     *   fx_symbol_used:string|null,
     *   fx_rate_used:float,
     *   fx_pair_used:string,
     *   fx_rate_provenance_at:string|null
     * }
     */
    private function resolveInstrumentValuationContext(
        object $instrument,
        object $account,
        CarbonImmutable $tradeDate
    ): array {
        $spec = InstrumentSpec::fromArray([
            'contract_size' => (float) ($instrument->contract_size ?? 0),
            'tick_size' => (float) ($instrument->tick_size ?? 0),
            'tick_value' => (float) ($instrument->tick_value ?? 0),
            'quote_currency' => (string) ($instrument->quote_currency ?? ''),
            'base_currency' => (string) ($instrument->base_currency ?? ''),
            'rounding_policy' => 'half_up_6',
        ]);
        if (! $spec->isValid()) {
            throw ValidationException::withMessages([
                'instrument_id' => ['Instrument contract and tick specification is invalid.'],
            ]);
        }

        $quoteCurrency = $spec->quoteCurrency();
        $accountCurrency = strtoupper(trim((string) ($account->currency ?? 'USD')));

        $quoteToAccountRate = 1.0;
        $fxRateUsed = 1.0;
        $fxPairUsed = $quoteCurrency.$accountCurrency;
        $fxProvenanceAt = $tradeDate->toIso8601String();

        if ($quoteCurrency !== '' && $accountCurrency !== '' && $quoteCurrency !== $accountCurrency) {
            if ($quoteCurrency !== 'USD' && $accountCurrency !== 'USD') {
                $quoteToUsd = $this->currencyConversionService->resolveRateWithProvenance($quoteCurrency, 'USD', $tradeDate);
                $usdToAccount = $this->currencyConversionService->resolveRateWithProvenance('USD', $accountCurrency, $tradeDate);
                if (! is_array($quoteToUsd) || ! is_array($usdToAccount)) {
                    throw ValidationException::withMessages([
                        'instrument_id' => [sprintf(
                            'Missing FX conversion path for %s to %s via USD.',
                            $quoteCurrency,
                            $accountCurrency
                        )],
                    ]);
                }

                $quoteToAccountRate = (float) $quoteToUsd['rate'] * (float) $usdToAccount['rate'];
                $fxRateUsed = $quoteToAccountRate;
                $fxPairUsed = (string) $quoteToUsd['pair'].'>'.(string) $usdToAccount['pair'];
                $fxProvenanceAt = $this->oldestIsoTimestamp(
                    $quoteToUsd['rate_updated_at'] ?? null,
                    $usdToAccount['rate_updated_at'] ?? null
                ) ?? $tradeDate->toIso8601String();
            } else {
                $resolved = $this->currencyConversionService->resolveRateWithProvenance($quoteCurrency, $accountCurrency, $tradeDate);
                if (! is_array($resolved) || (float) ($resolved['rate'] ?? 0) <= 0) {
                    throw ValidationException::withMessages([
                        'instrument_id' => [sprintf(
                            'Missing FX conversion rate for %s to %s.',
                            $quoteCurrency,
                            $accountCurrency
                        )],
                    ]);
                }

                $quoteToAccountRate = (float) $resolved['rate'];
                $fxRateUsed = $quoteToAccountRate;
                $fxPairUsed = (string) $resolved['pair'];
                $fxProvenanceAt = (string) ($resolved['rate_updated_at'] ?? $tradeDate->toIso8601String());
            }

            if ($quoteToAccountRate <= 0) {
                throw ValidationException::withMessages([
                    'instrument_id' => [sprintf(
                        'Invalid FX conversion rate for %s to %s.',
                        $quoteCurrency,
                        $accountCurrency
                    )],
                ]);
            }
        }

        $quoteToUsd = $this->currencyConversionService->resolveRateWithProvenance($quoteCurrency, 'USD', $tradeDate);
        $quoteToUsdRate = is_array($quoteToUsd) && (float) ($quoteToUsd['rate'] ?? 0) > 0
            ? (float) $quoteToUsd['rate']
            : null;
        $quoteToUsdPair = $quoteToUsdRate !== null
            ? (string) ($quoteToUsd['pair'] ?? $quoteCurrency.'USD')
            : null;

        $tickValueInAccount = $this->instrumentMath->tickValueInAccountCurrency($spec, $quoteToAccountRate);

        return [
            'tick_value_in_account_currency' => $tickValueInAccount,
            'quote_to_account_rate' => $quoteToAccountRate,
            'fx_rate_quote_to_usd' => $quoteToUsdRate,
            'fx_symbol_used' => $quoteToUsdPair,
            'fx_rate_used' => $fxRateUsed,
            'fx_pair_used' => $fxPairUsed,
            'fx_rate_provenance_at' => $fxProvenanceAt,
        ];
    }

    private function oldestIsoTimestamp(?string $first, ?string $second): ?string
    {
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }

        try {
            $a = CarbonImmutable::parse($first);
            $b = CarbonImmutable::parse($second);

            return $a->lessThanOrEqualTo($b)
                ? $a->toIso8601String()
                : $b->toIso8601String();
        } catch (\Throwable) {
            return $first;
        }
    }

    /**
     * @return array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }>
     */
    private function normalizeLegsForCalculation(mixed $legs, string $fallbackExecutedAt): array
    {
        if (! is_array($legs)) {
            return [];
        }

        $normalized = [];
        foreach ($legs as $index => $leg) {
            $row = is_array($leg)
                ? $leg
                : (is_object($leg) ? (array) $leg : null);
            if ($row === null) {
                continue;
            }

            $legType = strtolower((string) ($row['leg_type'] ?? ''));
            if (! in_array($legType, ['entry', 'exit'], true)) {
                continue;
            }

            $price = (float) ($row['price'] ?? 0);
            $quantity = (float) ($row['quantity_lots'] ?? 0);
            if ($price <= 0 || $quantity <= 0) {
                continue;
            }

            $executedAt = (string) ($row['executed_at'] ?? '');
            $timestamp = strtotime($executedAt);
            if ($timestamp === false) {
                $fallbackTimestamp = strtotime($fallbackExecutedAt);
                $timestamp = $fallbackTimestamp !== false
                    ? ($fallbackTimestamp + (int) $index)
                    : (time() + (int) $index);
            }

            $normalized[] = [
                'leg_type' => $legType,
                'price' => $price,
                'quantity_lots' => $quantity,
                'executed_at' => date('c', $timestamp),
                'fees' => (float) ($row['fees'] ?? 0),
                'notes' => isset($row['notes']) && $row['notes'] !== null
                    ? (string) $row['notes']
                    : null,
            ];
        }

        usort(
            $normalized,
            fn (array $left, array $right): int => strcmp($left['executed_at'], $right['executed_at'])
        );

        return $normalized;
    }

    /**
     * @param array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }> $legs
     * @return array{
     *   entry_count:int,
     *   exit_count:int,
     *   entry_quantity:float,
     *   exit_quantity:float,
     *   avg_entry_price:float,
     *   avg_exit_price:float
     * }
     */
    private function summarizeLegs(array $legs): array
    {
        $entryLegs = array_values(array_filter($legs, fn (array $leg): bool => $leg['leg_type'] === 'entry'));
        $exitLegs = array_values(array_filter($legs, fn (array $leg): bool => $leg['leg_type'] === 'exit'));

        $entryQuantity = array_sum(array_column($entryLegs, 'quantity_lots'));
        $exitQuantity = array_sum(array_column($exitLegs, 'quantity_lots'));

        return [
            'entry_count' => count($entryLegs),
            'exit_count' => count($exitLegs),
            'entry_quantity' => $entryQuantity,
            'exit_quantity' => $exitQuantity,
            'avg_entry_price' => $this->weightedAveragePrice($entryLegs),
            'avg_exit_price' => $this->weightedAveragePrice($exitLegs),
        ];
    }

    /**
     * @param  array<int,array{price:float,quantity_lots:float}>  $legs
     */
    private function weightedAveragePrice(array $legs): float
    {
        $totalQty = array_sum(array_column($legs, 'quantity_lots'));
        if ($totalQty <= 0) {
            return 0.0;
        }

        $weightedSum = array_reduce(
            $legs,
            fn (float $sum, array $leg): float => $sum + ($leg['price'] * $leg['quantity_lots']),
            0.0
        );

        return $weightedSum / $totalQty;
    }

    /**
     * @param array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }> $legs
     * @return array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }>
     */
    private function normalizeLegsForWrite(array $legs, string $fallbackExecutedAt): array
    {
        return $this->normalizeLegsForCalculation($legs, $fallbackExecutedAt);
    }

    /**
     * @param  array<string,mixed>  $payloadWithMetrics
     * @return array<string,mixed>
     */
    private function extractTradeAttributes(array $payloadWithMetrics): array
    {
        return collect($payloadWithMetrics)
            ->except([
                'legs',
                'tag_ids',
                'checklist_responses',
                'checklist_evaluation',
                'precheck_snapshot',
                'instrument_tick_size',
                'instrument_tick_value',
                'instrument_contract_size',
                'instrument_quote_to_account_rate',
                'instrument_quote_currency',
                'instrument_base_currency',
                'instrument_rounding_policy',
                'account_currency',
            ])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function applyTaxonomyLabels(array $payload): array
    {
        $strategyModelId = isset($payload['strategy_model_id']) && is_numeric($payload['strategy_model_id'])
            ? (int) $payload['strategy_model_id']
            : null;
        $setupId = isset($payload['setup_id']) && is_numeric($payload['setup_id'])
            ? (int) $payload['setup_id']
            : null;
        $killzoneId = isset($payload['killzone_id']) && is_numeric($payload['killzone_id'])
            ? (int) $payload['killzone_id']
            : null;

        $strategyModelName = $strategyModelId !== null
            ? (string) (DB::table('strategy_models')->where('id', $strategyModelId)->value('name') ?? '')
            : '';
        $setupName = $setupId !== null
            ? (string) (DB::table('setups')->where('id', $setupId)->value('name') ?? '')
            : '';
        $killzoneSession = $killzoneId !== null
            ? (string) (DB::table('killzones')->where('id', $killzoneId)->value('session_enum') ?? '')
            : '';

        $sessionEnum = (string) ($payload['session_enum'] ?? '');
        if ($sessionEnum === '' && $killzoneSession !== '') {
            $sessionEnum = $killzoneSession;
        }

        $sessionLabel = $this->sessionLabelFromEnum($sessionEnum);
        if ($sessionLabel === '') {
            $sessionLabel = (string) ($payload['session'] ?? 'N/A');
        }

        $modelLabel = $strategyModelName !== ''
            ? $strategyModelName
            : ((string) ($payload['model'] ?? 'General'));
        if ($setupName !== '' && ! str_contains(strtolower($modelLabel), strtolower($setupName))) {
            $modelLabel = trim($modelLabel.' - '.$setupName);
        }

        return [
            ...$payload,
            'session_enum' => $sessionEnum !== '' ? $sessionEnum : null,
            'session' => $sessionLabel,
            'model' => $modelLabel,
        ];
    }

    private function sessionLabelFromEnum(string $sessionEnum): string
    {
        return match ($sessionEnum) {
            'asia' => 'Asia',
            'london' => 'London',
            'new_york' => 'New York',
            'overlap' => 'London/NY Overlap',
            'off_session' => 'Off Session',
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,int>
     */
    private function extractTagIds(array $payload): array
    {
        $raw = $payload['tag_ids'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,int>  $tagIds
     */
    private function syncTradeTags(Trade $trade, array $tagIds): void
    {
        $trade->tags()->sync($tagIds);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizePrecheckPayload(array $payload, ?Trade $existingTrade = null): array
    {
        if ($existingTrade === null) {
            return $payload;
        }

        $existingLegs = $existingTrade->legs()
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($leg): array => [
                'leg_type' => (string) $leg->leg_type,
                'price' => (float) $leg->price,
                'quantity_lots' => (float) $leg->quantity_lots,
                'executed_at' => (string) $leg->executed_at,
                'fees' => (float) ($leg->fees ?? 0),
                'notes' => $leg->notes,
            ])
            ->all();
        $existingTagIds = $existingTrade->tags()
            ->pluck('trade_tags.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return [
            'account_id' => $payload['account_id'] ?? (int) $existingTrade->account_id,
            'instrument_id' => $payload['instrument_id'] ?? (int) $existingTrade->instrument_id,
            'strategy_model_id' => $payload['strategy_model_id'] ?? ($existingTrade->strategy_model_id !== null ? (int) $existingTrade->strategy_model_id : null),
            'setup_id' => $payload['setup_id'] ?? ($existingTrade->setup_id !== null ? (int) $existingTrade->setup_id : null),
            'killzone_id' => $payload['killzone_id'] ?? ($existingTrade->killzone_id !== null ? (int) $existingTrade->killzone_id : null),
            'session_enum' => $payload['session_enum'] ?? ($existingTrade->session_enum !== null ? (string) $existingTrade->session_enum : null),
            'tag_ids' => $payload['tag_ids'] ?? $existingTagIds,
            'pair' => $payload['pair'] ?? (string) $existingTrade->pair,
            'direction' => $payload['direction'] ?? (string) $existingTrade->direction,
            'entry_price' => $payload['entry_price'] ?? (float) $existingTrade->entry_price,
            'stop_loss' => $payload['stop_loss'] ?? (float) $existingTrade->stop_loss,
            'take_profit' => $payload['take_profit'] ?? (float) $existingTrade->take_profit,
            'actual_exit_price' => $payload['actual_exit_price'] ?? (float) $existingTrade->actual_exit_price,
            'lot_size' => $payload['lot_size'] ?? (float) $existingTrade->lot_size,
            'commission' => $payload['commission'] ?? (float) ($existingTrade->commission ?? 0),
            'swap' => $payload['swap'] ?? (float) ($existingTrade->swap ?? 0),
            'spread_cost' => $payload['spread_cost'] ?? (float) ($existingTrade->spread_cost ?? 0),
            'slippage_cost' => $payload['slippage_cost'] ?? (float) ($existingTrade->slippage_cost ?? 0),
            'followed_rules' => $payload['followed_rules'] ?? (bool) $existingTrade->followed_rules,
            'checklist_incomplete' => $payload['checklist_incomplete'] ?? (bool) ($existingTrade->checklist_incomplete ?? false),
            'executed_checklist_id' => $payload['executed_checklist_id'] ?? ($existingTrade->executed_checklist_id !== null ? (int) $existingTrade->executed_checklist_id : null),
            'executed_checklist_version' => $payload['executed_checklist_version'] ?? ($existingTrade->executed_checklist_version !== null ? (int) $existingTrade->executed_checklist_version : null),
            'executed_enforcement_mode' => $payload['executed_enforcement_mode'] ?? ($existingTrade->executed_enforcement_mode !== null ? (string) $existingTrade->executed_enforcement_mode : null),
            'failed_rule_ids' => $payload['failed_rule_ids'] ?? ($existingTrade->failed_rule_ids ?? []),
            'failed_rule_titles' => $payload['failed_rule_titles'] ?? ($existingTrade->failed_rule_titles ?? []),
            'check_evaluated_at' => $payload['check_evaluated_at'] ?? ($existingTrade->check_evaluated_at !== null ? (string) $existingTrade->check_evaluated_at : null),
            'emotion' => $payload['emotion'] ?? (string) $existingTrade->emotion,
            'risk_override_reason' => $payload['risk_override_reason'] ?? $existingTrade->risk_override_reason,
            'session' => $payload['session'] ?? (string) $existingTrade->session,
            'model' => $payload['model'] ?? (string) $existingTrade->model,
            'date' => $payload['date'] ?? (string) $existingTrade->date,
            'notes' => $payload['notes'] ?? $existingTrade->notes,
            'legs' => $payload['legs'] ?? $existingLegs,
        ];
    }

    private function resolveAccountForWrite(int $accountId, int $userId): object
    {
        return DB::table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function resolveInstrumentForRead(int $instrumentId): object
    {
        return DB::table('instruments')
            ->where('id', $instrumentId)
            ->firstOrFail();
    }

    /**
     * @param array<string,mixed> $checklistGate
     * @param array<string,mixed> $riskEvaluation
     * @param array<string,mixed> $payloadWithMetrics
     */
    private function writeRuleExecutionArtifact(
        Trade $trade,
        array $checklistGate,
        array $riskEvaluation,
        array $payloadWithMetrics,
        string $operation
    ): void {
        $snapshot = $checklistGate['snapshot_attributes'] ?? [];
        $failedRules = $this->serializeFailedRules($riskEvaluation, $checklistGate);

        DB::table('trade_rule_executions')->insert([
            'trade_id' => (int) $trade->id,
            'checklist_id' => isset($snapshot['executed_checklist_id']) && $snapshot['executed_checklist_id'] !== null
                ? (int) $snapshot['executed_checklist_id']
                : null,
            'checklist_revision' => isset($snapshot['executed_checklist_version']) && $snapshot['executed_checklist_version'] !== null
                ? (int) $snapshot['executed_checklist_version']
                : null,
            'evaluated_inputs_json' => json_encode([
                'operation' => $operation,
                'trade_id' => (int) $trade->id,
                'risk_percent' => (float) ($payloadWithMetrics['risk_percent'] ?? 0),
                'monetary_risk' => (float) ($payloadWithMetrics['monetary_risk'] ?? 0),
                'risk_policy' => $riskEvaluation['policy'] ?? [],
                'risk_stats' => $riskEvaluation['stats'] ?? [],
                'checklist_readiness' => $checklistGate['readiness'] ?? [],
                'checklist_enforcement_mode' => $snapshot['executed_enforcement_mode'] ?? null,
                'trade_metrics' => [
                    'entry_price' => (float) ($payloadWithMetrics['entry_price'] ?? 0),
                    'stop_loss' => (float) ($payloadWithMetrics['stop_loss'] ?? 0),
                    'take_profit' => (float) ($payloadWithMetrics['take_profit'] ?? 0),
                    'lot_size' => (float) ($payloadWithMetrics['lot_size'] ?? 0),
                    'profit_loss' => (float) ($payloadWithMetrics['profit_loss'] ?? 0),
                    'r_multiple' => (float) ($payloadWithMetrics['realized_r_multiple'] ?? 0),
                ],
            ], JSON_UNESCAPED_SLASHES),
            'failed_rules_json' => json_encode($failedRules, JSON_UNESCAPED_SLASHES),
            'decision' => count($failedRules) > 0 ? 'fail' : 'pass',
            'evaluated_at' => now(),
            'engine_version' => self::RULE_ENGINE_VERSION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string,mixed> $riskEvaluation
     * @param array<string,mixed> $checklistGate
     * @return array<int,array<string,mixed>>
     */
    private function serializeFailedRules(array $riskEvaluation, array $checklistGate): array
    {
        $failures = [];

        foreach (($riskEvaluation['violations'] ?? []) as $violation) {
            if (! is_array($violation)) {
                continue;
            }

            $failures[] = [
                'gate' => 'risk',
                'code' => (string) ($violation['code'] ?? 'risk_violation'),
                'message' => (string) ($violation['message'] ?? 'Risk policy violation.'),
                'limit' => isset($violation['limit']) ? (float) $violation['limit'] : null,
                'actual' => isset($violation['actual']) ? (float) $violation['actual'] : null,
            ];
        }

        foreach (($checklistGate['failed_rule_reasons'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $failures[] = [
                'gate' => 'checklist',
                'checklist_item_id' => isset($rule['checklist_item_id'])
                    ? (int) $rule['checklist_item_id']
                    : null,
                'title' => (string) ($rule['title'] ?? 'Required rule'),
                'category' => (string) ($rule['category'] ?? 'Checklist'),
                'reason' => (string) ($rule['reason'] ?? 'Checklist rule failed.'),
            ];
        }

        return $failures;
    }
}
