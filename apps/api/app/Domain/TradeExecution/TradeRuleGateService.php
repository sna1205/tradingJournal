<?php

namespace App\Domain\TradeExecution;

use App\Models\Checklist;
use App\Models\Trade;
use App\Services\ChecklistService;
use App\Services\TradeChecklistService;

class TradeRuleGateService
{
    public function __construct(
        private readonly ChecklistService $checklistService,
        private readonly TradeChecklistService $tradeChecklistService
    ) {
    }

    /**
     * @param array<string,mixed> $contextPayload
     * @param array<string,mixed> $responseSourcePayload
     * @param callable(?Checklist,array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>,?string,?int,array<int,int>,array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>):void|null $strictFailureHandler
     * @return array{
     *   checklist:Checklist|null,
     *   responses:array<int,array{checklist_item_id:int,value:mixed}>,
     *   precheck_metrics:array<string,float>,
     *   responses_from_request:bool,
     *   snapshot_frozen:bool,
     *   checklist_incomplete:bool,
     *   snapshot_attributes:array{
     *     executed_checklist_id:int|null,
     *     executed_checklist_version:int|null,
     *     executed_enforcement_mode:string|null,
     *     failed_rule_ids:array<int,int>,
     *     failed_rule_titles:array<int,string>,
     *     check_evaluated_at:string|null
     *   },
     *   readiness:array<string,mixed>,
     *   failing_rules:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>,
     *   failed_required_rule_ids:array<int,int>,
     *   failed_rule_reasons:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>
     * }
     */
    public function evaluateChecklistGateForPayload(
        int $userId,
        array $contextPayload,
        ?Trade $existingTrade = null,
        array $responseSourcePayload = [],
        bool $enforceStrict = true,
        ?callable $strictFailureHandler = null
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
        $precheckMetrics = $this->buildChecklistPrecheckMetrics($contextPayload);

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

        if ($enforcementMode === 'strict' && ! $ready && $enforceStrict) {
            if ($strictFailureHandler !== null) {
                $strictFailureHandler(
                    $checklist,
                    $failingRules,
                    $enforcementMode,
                    $checklistVersion,
                    $failedRequiredRuleIds,
                    $failedRuleReasons
                );
            }

            throw new \LogicException('Strict checklist gate blocked the write flow.');
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
     * @param array<string,mixed> $payload
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
     * @param array<string,mixed> $contextPayload
     * @return array<string,float>
     */
    private function buildChecklistPrecheckMetrics(array $contextPayload): array
    {
        $metrics = [];

        foreach ($contextPayload as $key => $value) {
            if (! is_string($key) || ! is_numeric($value)) {
                continue;
            }
            $metrics[$key] = (float) $value;
            $metrics[strtolower($key)] = (float) $value;
        }

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
}
