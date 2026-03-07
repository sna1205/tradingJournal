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
    private const DEFAULT_POSITIVE_MIN = 0.000000001;
    private const EXPLICIT_RULE_TYPES = ['boolean', 'numeric', 'select', 'auto_metric'];
    private const EXPLICIT_RULE_OPERATORS = ['>', '>=', '<', '<=', '==', '!=', 'in', 'not_in'];

    /**
     * @param array<int,array{checklist_item_id:int,value:mixed}> $responses
     * @param array<string,mixed> $precheckMetrics
     * @return array{responses:array<int,array<string,mixed>>, readiness:array<string,mixed>}
     */
    public function upsertResponses(Trade $trade, Checklist $checklist, array $responses, array $precheckMetrics = []): array
    {
        return DB::transaction(function () use ($trade, $checklist, $responses, $precheckMetrics): array {
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
                $completed = $this->isCompleted($item, $value, $precheckMetrics);

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
                    $reason = (string) ($this->evaluateItem($item, $value)['reason'] ?? 'Rule requirement not met.');
                    $missingRequired[] = [
                        'checklist_item_id' => (int) $item->id,
                        'title' => (string) $item->title,
                        'category' => (string) $item->category,
                        'reason' => $reason,
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
                    'revision' => (int) ($checklist->revision ?? 1),
                    'scope' => (string) $checklist->scope,
                    'enforcement_mode' => (string) $checklist->enforcement_mode,
                    'account_id' => $checklist->account_id !== null ? (int) $checklist->account_id : null,
                    'strategy_model_id' => $checklist->strategy_model_id !== null ? (int) $checklist->strategy_model_id : null,
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
            'failing_rules' => $missingRequired,
            'failed_required_rule_ids' => array_values(array_map(
                fn (array $row): int => (int) $row['checklist_item_id'],
                $missingRequired
            )),
            'failed_rule_reasons' => array_values(array_map(
                fn (array $row): array => [
                    'checklist_item_id' => (int) $row['checklist_item_id'],
                    'title' => (string) ($row['title'] ?? 'Required rule'),
                    'category' => (string) ($row['category'] ?? 'Checklist'),
                    'reason' => (string) ($row['reason'] ?? 'Rule requirement not met.'),
                ],
                $missingRequired
            )),
            'failedRequiredRuleIds' => array_values(array_map(
                fn (array $row): int => (int) $row['checklist_item_id'],
                $missingRequired
            )),
            'failedRuleReasons' => array_values(array_map(
                fn (array $row): array => [
                    'checklist_item_id' => (int) $row['checklist_item_id'],
                    'title' => (string) ($row['title'] ?? 'Required rule'),
                    'category' => (string) ($row['category'] ?? 'Checklist'),
                    'reason' => (string) ($row['reason'] ?? 'Rule requirement not met.'),
                ],
                $missingRequired
            )),
        ];
    }

    /**
     * @param array<int,array{checklist_item_id:int,value:mixed}> $responses
     * @param array<string,mixed> $precheckMetrics
     * @return array{
     *   responses:array{checklist:array<string,mixed>,items:array<int,array<string,mixed>>,archived_responses:array<int,mixed>},
     *   readiness:array<string,mixed>,
     *   failing_rules:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>,
     *   failed_required_rule_ids:array<int,int>,
     *   failed_rule_reasons:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>,
     *   failedRequiredRuleIds:array<int,int>,
     *   failedRuleReasons:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>
     * }
     */
    public function buildDraftChecklistState(
        Checklist $checklist,
        array $responses = [],
        bool $editingMode = true,
        array $precheckMetrics = []
    ): array {
        $items = $checklist->items()
            ->where('is_active', true)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $providedResponses = collect($responses)
            ->map(fn (array $row): array => [
                'checklist_item_id' => (int) ($row['checklist_item_id'] ?? 0),
                'value' => $row['value'] ?? null,
            ])
            ->filter(fn (array $row): bool => $row['checklist_item_id'] > 0)
            ->keyBy('checklist_item_id');

        $serialized = [];
        $requiredActiveCount = 0;
        $requiredCompleted = 0;
        $missingRequired = [];
        $failedRequiredRuleIds = [];
        $failedRuleReasons = [];

        foreach ($items as $item) {
            $provided = $providedResponses->get((int) $item->id);
            $rawValue = $provided['value'] ?? $this->defaultValueForType((string) $item->type);
            $value = $this->normalizeStoredValue((string) $item->type, $rawValue);
            $evaluation = $this->evaluateItem($item, $value, $precheckMetrics);
            $completed = (bool) $evaluation['passed'];
            $reason = (string) ($evaluation['reason'] ?? 'Rule requirement not met.');

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
                        'reason' => $reason,
                    ];
                    $failedRequiredRuleIds[] = (int) $item->id;
                    $failedRuleReasons[] = [
                        'checklist_item_id' => (int) $item->id,
                        'title' => (string) $item->title,
                        'category' => (string) $item->category,
                        'reason' => $reason,
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
                    'completed_at' => $completed ? CarbonImmutable::now() : null,
                    'reason' => !$completed ? $reason : null,
                    'archived' => false,
                ],
            ];
        }

        $status = 'ready';
        if ($requiredActiveCount === 0) {
            $status = 'ready';
        } elseif ($requiredCompleted === 0) {
            $status = 'not_ready';
        } elseif ($requiredCompleted < $requiredActiveCount) {
            $status = 'almost';
        }

        $readiness = [
            'status' => $status,
            'completed_required' => $requiredCompleted,
            'total_required' => $requiredActiveCount,
            'missing_required' => $missingRequired,
            'ready' => $requiredActiveCount === 0 || $requiredCompleted >= $requiredActiveCount,
        ];

        return [
            'responses' => [
                'checklist' => [
                    'id' => (int) $checklist->id,
                    'name' => (string) $checklist->name,
                    'revision' => (int) ($checklist->revision ?? 1),
                    'scope' => (string) $checklist->scope,
                    'enforcement_mode' => (string) $checklist->enforcement_mode,
                    'account_id' => $checklist->account_id !== null ? (int) $checklist->account_id : null,
                    'strategy_model_id' => $checklist->strategy_model_id !== null ? (int) $checklist->strategy_model_id : null,
                    'is_active' => (bool) $checklist->is_active,
                ],
                'items' => $serialized,
                'archived_responses' => [],
            ],
            'readiness' => $readiness,
            'failing_rules' => $missingRequired,
            'failed_required_rule_ids' => array_values($failedRequiredRuleIds),
            'failed_rule_reasons' => $failedRuleReasons,
            'failedRequiredRuleIds' => array_values($failedRequiredRuleIds),
            'failedRuleReasons' => $failedRuleReasons,
        ];
    }

    /**
     * @param array<int,array{checklist_item_id:int,value:mixed}> $responses
     * @param array<string,mixed> $precheckMetrics
     * @return array{
     *   readiness:array<string,mixed>,
     *   failing_rules:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>,
     *   failed_required_rule_ids:array<int,int>,
     *   failed_rule_reasons:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>,
     *   failedRequiredRuleIds:array<int,int>,
     *   failedRuleReasons:array<int,array{checklist_item_id:int,title:string,category:string,reason:string}>
     * }
     */
    public function evaluateDraftReadiness(
        Checklist $checklist,
        array $responses = [],
        bool $editingMode = true,
        array $precheckMetrics = []
    ): array {
        $state = $this->buildDraftChecklistState($checklist, $responses, $editingMode, $precheckMetrics);

        return [
            'readiness' => $state['readiness'],
            'failing_rules' => $state['failing_rules'],
            'failed_required_rule_ids' => $state['failed_required_rule_ids'] ?? [],
            'failed_rule_reasons' => $state['failed_rule_reasons'] ?? [],
            'failedRequiredRuleIds' => $state['failedRequiredRuleIds'] ?? [],
            'failedRuleReasons' => $state['failedRuleReasons'] ?? [],
        ];
    }

    /**
     * @param array<string,mixed> $precheckMetrics
     */
    public function isCompleted(ChecklistItem $item, mixed $value, array $precheckMetrics = []): bool
    {
        $evaluation = $this->evaluateItem($item, $value, $precheckMetrics);

        return (bool) ($evaluation['passed'] ?? false);
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

    private function defaultValueForType(string $type): mixed
    {
        return match ($type) {
            'checkbox' => false,
            'number', 'scale' => null,
            default => '',
        };
    }

    /**
     * @param array<string,mixed> $precheckMetrics
     * @return array{passed:bool,reason:?string}
     */
    private function evaluateItem(ChecklistItem $item, mixed $value, array $precheckMetrics = []): array
    {
        $type = (string) $item->type;
        $config = is_array($item->config) ? $item->config : [];

        $explicitRule = $this->resolveExplicitRuleDefinition($config, (bool) $item->required);
        if ($explicitRule !== null) {
            return $this->evaluateExplicitRule($explicitRule, $value, $precheckMetrics);
        }

        if ($type === 'number' || $this->usesAutoMetric($config)) {
            return $this->evaluateNumericRule($item, $value, $precheckMetrics);
        }

        if ($type === 'checkbox') {
            return (bool) $value
                ? ['passed' => true, 'reason' => null]
                : ['passed' => false, 'reason' => 'Toggle must be checked.'];
        }

        if ($type === 'dropdown') {
            $selected = is_string($value) ? trim($value) : (is_scalar($value) ? trim((string) $value) : '');
            $allowedKeys = $this->resolveSelectOptionKeys($config);
            if (count($allowedKeys) === 0) {
                return ['passed' => false, 'reason' => 'Rule misconfigured: allowed select options are missing.'];
            }
            if ($selected === '') {
                return ['passed' => false, 'reason' => 'A valid option is required.'];
            }
            if (!in_array($selected, $allowedKeys, true)) {
                return ['passed' => false, 'reason' => 'Selected option is not allowed by this rule.'];
            }

            return ['passed' => true, 'reason' => null];
        }

        if ($type === 'scale') {
            $numericValue = $this->asFiniteFloat($value);
            if ($numericValue === null) {
                return ['passed' => false, 'reason' => 'Numeric value is required.'];
            }

            $min = $this->asFiniteFloat($config['min'] ?? null);
            $max = $this->asFiniteFloat($config['max'] ?? null);
            if ($min !== null && $numericValue < $min) {
                return ['passed' => false, 'reason' => sprintf('Value must be >= %s.', $this->formatNumber($min))];
            }
            if ($max !== null && $numericValue > $max) {
                return ['passed' => false, 'reason' => sprintf('Value must be <= %s.', $this->formatNumber($max))];
            }

            return ['passed' => true, 'reason' => null];
        }

        if ($type === 'text') {
            return is_string($value) && trim($value) !== ''
                ? ['passed' => true, 'reason' => null]
                : ['passed' => false, 'reason' => 'Text input is required.'];
        }

        return !empty($value)
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'Rule requirement not met.'];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{type:string,operator:string,threshold:mixed,required:bool,metric_key:?string}|null
     */
    private function resolveExplicitRuleDefinition(array $config, bool $defaultRequired): ?array
    {
        $rawRule = $config['rule'] ?? null;
        if (!is_array($rawRule)) {
            return null;
        }

        $type = $this->asTrimmedLowerString($rawRule['type'] ?? null);
        $operator = $this->asTrimmedLowerString($rawRule['operator'] ?? null);
        if (
            $type === null
            || $operator === null
            || !in_array($type, self::EXPLICIT_RULE_TYPES, true)
            || !in_array($operator, self::EXPLICIT_RULE_OPERATORS, true)
            || !array_key_exists('threshold', $rawRule)
        ) {
            return null;
        }

        $metricKey = $this->asTrimmedString($rawRule['metric_key'] ?? null);
        if ($type === 'auto_metric' && $metricKey === null) {
            return null;
        }

        return [
            'type' => $type,
            'operator' => $operator,
            'threshold' => $rawRule['threshold'],
            'required' => array_key_exists('required', $rawRule) ? (bool) $rawRule['required'] : $defaultRequired,
            'metric_key' => $metricKey,
        ];
    }

    /**
     * @param array{type:string,operator:string,threshold:mixed,required:bool,metric_key:?string} $rule
     * @param array<string,mixed> $precheckMetrics
     * @return array{passed:bool,reason:?string}
     */
    private function evaluateExplicitRule(array $rule, mixed $value, array $precheckMetrics = []): array
    {
        $type = $rule['type'];
        $operator = $rule['operator'];
        $threshold = $rule['threshold'];
        $metricKey = $rule['metric_key'];

        $evaluatedValue = $value;
        if ($type === 'auto_metric' || ($type === 'numeric' && $metricKey !== null)) {
            if ($metricKey === null) {
                return ['passed' => false, 'reason' => 'Rule misconfigured: metric_key is required for auto_metric rules.'];
            }

            $metricValue = $this->readMetricValue($precheckMetrics, $metricKey);
            if ($metricValue === null) {
                return [
                    'passed' => false,
                    'reason' => sprintf('Missing auto metric "%s" for evaluation.', $metricKey),
                ];
            }
            $evaluatedValue = $metricValue;
        }

        if ($type === 'numeric' || $type === 'auto_metric') {
            $numericValue = $this->asFiniteFloat($evaluatedValue);
            if ($numericValue === null) {
                return ['passed' => false, 'reason' => 'Numeric value is required.'];
            }

            if (in_array($operator, ['>', '>=', '<', '<='], true)) {
                $numericThreshold = $this->asFiniteFloat($threshold);
                if ($numericThreshold === null) {
                    return ['passed' => false, 'reason' => 'Rule misconfigured: threshold must be numeric.'];
                }

                $passed = match ($operator) {
                    '>' => $numericValue > $numericThreshold,
                    '>=' => $numericValue >= $numericThreshold,
                    '<' => $numericValue < $numericThreshold,
                    '<=' => $numericValue <= $numericThreshold,
                    default => false,
                };

                return $passed
                    ? ['passed' => true, 'reason' => null]
                    : [
                        'passed' => false,
                        'reason' => sprintf(
                            'Value must be %s %s.',
                            $this->explicitOperatorLabel($operator),
                            $this->formatNumber($numericThreshold)
                        ),
                    ];
            }

            if ($operator === '==' || $operator === '!=') {
                $numericThreshold = $this->asFiniteFloat($threshold);
                if ($numericThreshold === null) {
                    return ['passed' => false, 'reason' => 'Rule misconfigured: threshold must be numeric.'];
                }

                $equal = abs($numericValue - $numericThreshold) < self::DEFAULT_POSITIVE_MIN;
                $passed = $operator === '==' ? $equal : !$equal;
                if ($passed) {
                    return ['passed' => true, 'reason' => null];
                }

                return [
                    'passed' => false,
                    'reason' => sprintf(
                        'Value must be %s %s.',
                        $operator === '==' ? 'equal to' : 'not equal to',
                        $this->formatNumber($numericThreshold)
                    ),
                ];
            }

            return ['passed' => false, 'reason' => 'Rule misconfigured: unsupported operator for numeric rule.'];
        }

        if ($type === 'boolean') {
            $boolValue = $this->asBool($evaluatedValue);
            $boolThreshold = $this->asBool($threshold);
            if ($boolValue === null || $boolThreshold === null) {
                return ['passed' => false, 'reason' => 'Rule misconfigured: boolean rule requires true/false values.'];
            }

            $equal = $boolValue === $boolThreshold;
            $passed = $operator === '==' ? $equal : ($operator === '!=' ? !$equal : false);
            if ($passed) {
                return ['passed' => true, 'reason' => null];
            }

            return [
                'passed' => false,
                'reason' => sprintf(
                    'Value must be %s %s.',
                    $operator === '==' ? 'equal to' : 'not equal to',
                    $boolThreshold ? 'true' : 'false'
                ),
            ];
        }

        if ($type === 'select') {
            $selected = is_scalar($evaluatedValue) ? trim((string) $evaluatedValue) : '';
            if ($selected === '') {
                return ['passed' => false, 'reason' => 'A valid option is required.'];
            }

            if ($operator === 'in' || $operator === 'not_in') {
                if (!is_array($threshold) || count($threshold) === 0) {
                    return ['passed' => false, 'reason' => 'Rule misconfigured: in/not_in requires threshold array.'];
                }

                $allowed = array_values(array_unique(array_map(
                    fn (mixed $entry): string => trim((string) $entry),
                    array_filter($threshold, fn (mixed $entry): bool => is_scalar($entry) && trim((string) $entry) !== '')
                )));
                if ($allowed === []) {
                    return ['passed' => false, 'reason' => 'Rule misconfigured: select options are missing.'];
                }

                $inSet = in_array($selected, $allowed, true);
                $passed = $operator === 'in' ? $inSet : !$inSet;
                if ($passed) {
                    return ['passed' => true, 'reason' => null];
                }

                return [
                    'passed' => false,
                    'reason' => $operator === 'in'
                        ? 'Selected option is not allowed by this rule.'
                        : 'Selected option is blocked by this rule.',
                ];
            }

            if ($operator === '==' || $operator === '!=') {
                $target = is_scalar($threshold) ? trim((string) $threshold) : '';
                if ($target === '') {
                    return ['passed' => false, 'reason' => 'Rule misconfigured: threshold is required.'];
                }
                $equal = $selected === $target;
                $passed = $operator === '==' ? $equal : !$equal;
                return $passed
                    ? ['passed' => true, 'reason' => null]
                    : [
                        'passed' => false,
                        'reason' => sprintf(
                            'Selected option must be %s %s.',
                            $operator === '==' ? 'equal to' : 'different from',
                            $target
                        ),
                    ];
            }

            return ['passed' => false, 'reason' => 'Rule misconfigured: unsupported operator for select rule.'];
        }

        return ['passed' => false, 'reason' => 'Rule misconfigured: unsupported rule type.'];
    }

    /**
     * @param array<string,mixed> $precheckMetrics
     * @return array{passed:bool,reason:?string}
     */
    private function evaluateNumericRule(ChecklistItem $item, mixed $value, array $precheckMetrics = []): array
    {
        $config = is_array($item->config) ? $item->config : [];
        $metricKey = $this->resolveAutoMetricKey($config);

        $numericValue = $metricKey !== null
            ? $this->readMetricValue($precheckMetrics, $metricKey)
            : $this->asFiniteFloat($value);
        if ($numericValue === null) {
            if ($metricKey !== null) {
                return [
                    'passed' => false,
                    'reason' => sprintf('Missing auto metric "%s" for evaluation.', $metricKey),
                ];
            }

            return ['passed' => false, 'reason' => 'Numeric value is required.'];
        }

        $min = $this->asFiniteFloat($config['min'] ?? null);
        $max = $this->asFiniteFloat($config['max'] ?? null);
        $allowZero = filter_var($config['allow_zero'] ?? false, FILTER_VALIDATE_BOOL);
        $allowNegative = filter_var($config['allow_negative'] ?? false, FILTER_VALIDATE_BOOL);

        if ($min === null && !$allowNegative) {
            $min = $allowZero ? 0.0 : self::DEFAULT_POSITIVE_MIN;
        }

        if ($min !== null && $numericValue < $min) {
            return [
                'passed' => false,
                'reason' => sprintf('Value must be >= %s.', $this->formatNumber($min)),
            ];
        }

        if ($max !== null && $numericValue > $max) {
            return [
                'passed' => false,
                'reason' => sprintf('Value must be <= %s.', $this->formatNumber($max)),
            ];
        }

        $comparator = $this->normalizeComparator($config['comparator'] ?? null);
        if ($comparator === null) {
            return ['passed' => false, 'reason' => 'Rule misconfigured: comparator is required.'];
        }

        $thresholds = $this->resolveThresholds($config, $comparator);
        if ($thresholds['error'] !== null) {
            return ['passed' => false, 'reason' => $thresholds['error']];
        }

        $passed = match ($comparator) {
            '>' => $numericValue > (float) $thresholds['threshold'],
            '>=' => $numericValue >= (float) $thresholds['threshold'],
            '<' => $numericValue < (float) $thresholds['threshold'],
            '<=' => $numericValue <= (float) $thresholds['threshold'],
            'equals' => abs($numericValue - (float) $thresholds['threshold']) < self::DEFAULT_POSITIVE_MIN,
            'between' => $numericValue >= (float) $thresholds['min'] && $numericValue <= (float) $thresholds['max'],
            default => false,
        };

        if ($passed) {
            return ['passed' => true, 'reason' => null];
        }

        if ($comparator === 'between') {
            return [
                'passed' => false,
                'reason' => sprintf(
                    'Value must be between %s and %s.',
                    $this->formatNumber((float) $thresholds['min']),
                    $this->formatNumber((float) $thresholds['max'])
                ),
            ];
        }

        return [
            'passed' => false,
            'reason' => sprintf(
                'Value must be %s %s.',
                $this->comparatorLabel($comparator),
                $this->formatNumber((float) $thresholds['threshold'])
            ),
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,string>
     */
    private function resolveSelectOptionKeys(array $config): array
    {
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }

        $keys = [];
        foreach ($options as $option) {
            $entry = is_array($option) ? $option : (is_object($option) ? (array) $option : $option);
            $key = null;
            if (is_array($entry)) {
                $key = $entry['key'] ?? $entry['value'] ?? $entry['id'] ?? $entry['label'] ?? null;
            } elseif (is_scalar($entry)) {
                $key = $entry;
            }

            if (!is_scalar($key)) {
                continue;
            }

            $normalized = trim((string) $key);
            if ($normalized === '') {
                continue;
            }
            $keys[] = $normalized;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string,mixed> $config
     */
    private function usesAutoMetric(array $config): bool
    {
        return $this->resolveAutoMetricKey($config) !== null;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function resolveAutoMetricKey(array $config): ?string
    {
        $raw = $config['auto_metric'] ?? null;
        if (!is_scalar($raw)) {
            return null;
        }

        $metricKey = trim((string) $raw);

        return $metricKey === '' ? null : $metricKey;
    }

    /**
     * @param array<string,mixed> $metrics
     */
    private function readMetricValue(array $metrics, string $metricKey): ?float
    {
        $candidateKeys = [
            $metricKey,
            strtolower($metricKey),
            str_replace(' ', '_', strtolower($metricKey)),
        ];

        if ($metricKey === 'risk_amount') {
            $candidateKeys[] = 'monetary_risk';
        }
        if ($metricKey === 'monetary_risk') {
            $candidateKeys[] = 'risk_amount';
        }
        if ($metricKey === 'r_multiple') {
            $candidateKeys[] = 'realized_r_multiple';
        }
        if ($metricKey === 'realized_r_multiple') {
            $candidateKeys[] = 'r_multiple';
        }

        foreach (array_values(array_unique($candidateKeys)) as $key) {
            if (!array_key_exists($key, $metrics)) {
                continue;
            }

            $metricValue = $this->asFiniteFloat($metrics[$key]);
            if ($metricValue !== null) {
                return $metricValue;
            }
        }

        return null;
    }

    private function normalizeComparator(mixed $rawComparator): ?string
    {
        if (!is_scalar($rawComparator)) {
            return null;
        }

        return match (strtolower(trim((string) $rawComparator))) {
            '>', 'gt', 'greater_than' => '>',
            '>=', 'gte', 'greater_than_or_equal' => '>=',
            '<', 'lt', 'less_than' => '<',
            '<=', 'lte', 'less_than_or_equal' => '<=',
            '=', 'eq', 'equals' => 'equals',
            'between', 'range' => 'between',
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $config
     * @return array{threshold:?float,min:?float,max:?float,error:?string}
     */
    private function resolveThresholds(array $config, string $comparator): array
    {
        if ($comparator === 'between') {
            $thresholdArray = $config['threshold'] ?? $config['thresholds'] ?? null;
            $min = $this->asFiniteFloat($config['threshold_min'] ?? null);
            $max = $this->asFiniteFloat($config['threshold_max'] ?? null);

            if ($min === null && $max === null && is_array($thresholdArray) && count($thresholdArray) >= 2) {
                $arrayValues = array_values($thresholdArray);
                $min = $this->asFiniteFloat($arrayValues[0] ?? null);
                $max = $this->asFiniteFloat($arrayValues[1] ?? null);
            }

            if ($min === null || $max === null) {
                return [
                    'threshold' => null,
                    'min' => null,
                    'max' => null,
                    'error' => 'Rule misconfigured: between comparator requires threshold_min and threshold_max.',
                ];
            }

            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }

            return [
                'threshold' => null,
                'min' => $min,
                'max' => $max,
                'error' => null,
            ];
        }

        $threshold = $this->asFiniteFloat($config['threshold'] ?? null);
        if ($threshold === null) {
            return [
                'threshold' => null,
                'min' => null,
                'max' => null,
                'error' => 'Rule misconfigured: threshold is required.',
            ];
        }

        return [
            'threshold' => $threshold,
            'min' => null,
            'max' => null,
            'error' => null,
        ];
    }

    private function asFiniteFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $normalized = (float) $value;
        if (is_infinite($normalized) || is_nan($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 6, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function comparatorLabel(string $comparator): string
    {
        return match ($comparator) {
            '>' => 'greater than',
            '>=' => 'greater than or equal to',
            '<' => 'less than',
            '<=' => 'less than or equal to',
            'equals' => 'equal to',
            default => $comparator,
        };
    }

    private function explicitOperatorLabel(string $operator): string
    {
        return match ($operator) {
            '>' => 'greater than',
            '>=' => 'greater than or equal to',
            '<' => 'less than',
            '<=' => 'less than or equal to',
            default => $operator,
        };
    }

    private function asTrimmedString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private function asTrimmedLowerString(mixed $value): ?string
    {
        $normalized = $this->asTrimmedString($value);
        return $normalized === null ? null : strtolower($normalized);
    }

    private function asBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return (bool) $value;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }
}
