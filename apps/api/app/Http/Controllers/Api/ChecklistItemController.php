<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Services\ChecklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChecklistItemController extends Controller
{
    private const EXPLICIT_RULE_TYPES = ['boolean', 'numeric', 'select', 'auto_metric'];
    private const EXPLICIT_RULE_OPERATORS = ['>', '>=', '<', '<=', '==', '!=', 'in', 'not_in'];

    public function __construct(
        private readonly ChecklistService $checklistService
    ) {
    }

    public function index(Request $request, Checklist $checklist)
    {
        $this->abortIfUnauthorizedChecklist($request, $checklist);

        return response()->json(
            $this->checklistService->listItems($checklist)
        );
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request, Checklist $checklist)
    {
        $this->abortIfUnauthorizedChecklist($request, $checklist);

        $payload = $this->validatePayload($request);
        $item = $this->checklistService->createItem($checklist, $payload);

        return response()->json($item, 201);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, ChecklistItem $checklistItem)
    {
        $this->abortIfUnauthorizedItem($request, $checklistItem);

        $payload = $this->validatePayload($request, true);
        $updated = $this->checklistService->updateItem($checklistItem, $payload);

        return response()->json($updated);
    }

    /**
     * @throws ValidationException
     */
    public function reorder(Request $request, Checklist $checklist)
    {
        $this->abortIfUnauthorizedChecklist($request, $checklist);

        $validator = Validator::make($request->all(), [
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'exists:checklist_items,id'],
        ]);

        $payload = $validator->validate();

        $this->checklistService->reorderItems($checklist, $payload['item_ids']);

        return response()->json([
            'items' => $this->checklistService->listItems($checklist),
        ]);
    }

    public function destroy(Request $request, ChecklistItem $checklistItem)
    {
        $this->abortIfUnauthorizedItem($request, $checklistItem);

        $this->checklistService->softDeleteItem($checklistItem);

        return response()->noContent();
    }

    /**
     * @throws ValidationException
     */
    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'order_index' => ['sometimes', 'integer', 'min:0'],
            'title' => [$required, 'string', 'max:220'],
            'type' => [$required, Rule::in(['checkbox', 'dropdown', 'number', 'text', 'scale'])],
            'required' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:80'],
            'help_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'config' => ['sometimes', 'array'],
            'rule' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $type = (string) $request->input('type', '');
            $config = $request->input('config', []);
            if (!is_array($config)) {
                $validator->errors()->add('config', 'config must be an object.');
                return;
            }

            if ($type === 'dropdown') {
                if (!array_key_exists('options', $config) || !is_array($config['options']) || count($config['options']) === 0) {
                    $validator->errors()->add('config.options', 'Dropdown requires at least one option.');
                    return;
                }

                $normalizedKeys = collect($config['options'])
                    ->map(function ($option): string {
                        $entry = is_array($option) ? $option : (is_object($option) ? (array) $option : $option);
                        $key = is_array($entry)
                            ? ($entry['key'] ?? $entry['value'] ?? $entry['id'] ?? $entry['label'] ?? null)
                            : $entry;

                        return is_scalar($key) ? trim((string) $key) : '';
                    })
                    ->filter(fn (string $value): bool => $value !== '')
                    ->unique()
                    ->values();

                if ($normalizedKeys->isEmpty()) {
                    $validator->errors()->add('config.options', 'Dropdown options must include non-empty option keys.');
                }
            }

            if ($type === 'number') {
                foreach (['min', 'max', 'step'] as $key) {
                    if (array_key_exists($key, $config) && !is_numeric($config[$key])) {
                        $validator->errors()->add("config.$key", "$key must be numeric.");
                    }
                }

                $rawComparator = $config['comparator'] ?? null;
                $hasThreshold = array_key_exists('threshold', $config)
                    || array_key_exists('threshold_min', $config)
                    || array_key_exists('threshold_max', $config);

                if (!is_scalar($rawComparator) || trim((string) $rawComparator) === '') {
                    if ($hasThreshold) {
                        $validator->errors()->add('config.comparator', 'Comparator is required when thresholds are provided.');
                    }
                    return;
                }

                $normalizedComparator = strtolower(trim((string) $rawComparator));
                $validComparators = ['>', '>=', '<', '<=', '=', 'equals', 'between'];
                if (!in_array($normalizedComparator, $validComparators, true)) {
                    $validator->errors()->add('config.comparator', 'Comparator must be one of >, >=, <, <=, equals, between.');
                    return;
                }

                if ($normalizedComparator === 'between') {
                    $minThreshold = $config['threshold_min'] ?? null;
                    $maxThreshold = $config['threshold_max'] ?? null;
                    $thresholdArray = $config['threshold'] ?? null;
                    if (!is_numeric($minThreshold) || !is_numeric($maxThreshold)) {
                        if (!is_array($thresholdArray) || count($thresholdArray) < 2 || !is_numeric($thresholdArray[0]) || !is_numeric($thresholdArray[1])) {
                            $validator->errors()->add(
                                'config.threshold',
                                'Between comparator requires numeric threshold_min and threshold_max.'
                            );
                        }
                    }
                } else {
                    if (!array_key_exists('threshold', $config) || !is_numeric($config['threshold'])) {
                        $validator->errors()->add('config.threshold', 'Number rules require a numeric threshold.');
                    }
                }
            }

            if ($type === 'text' && array_key_exists('maxLength', $config) && (!is_numeric($config['maxLength']) || (int) $config['maxLength'] < 1)) {
                $validator->errors()->add('config.maxLength', 'maxLength must be a positive integer.');
            }

            if ($type === 'scale') {
                $min = $config['min'] ?? null;
                $max = $config['max'] ?? null;
                if (!is_numeric($min) || !is_numeric($max)) {
                    $validator->errors()->add('config', 'Scale requires numeric min and max.');
                    return;
                }
                if ((int) $min >= (int) $max) {
                    $validator->errors()->add('config', 'Scale min must be lower than max.');
                }
            }

            $rawRule = $this->extractRawRuleDefinition($request);
            if ($rawRule !== null) {
                $this->validateExplicitRuleSchema($validator, $rawRule);
            }
        });

        $payload = $validator->validate();

        return $this->normalizeRulePayload($payload);
    }

    private function abortIfUnauthorizedChecklist(Request $request, Checklist $checklist): void
    {
        $this->authorize('view', $checklist);
    }

    private function abortIfUnauthorizedItem(Request $request, ChecklistItem $item): void
    {
        $checklist = Checklist::query()->whereKey((int) $item->checklist_id)->first();
        abort_unless($checklist !== null, 404);
        $this->abortIfUnauthorizedChecklist($request, $checklist);
    }

    private function extractRawRuleDefinition(Request $request): mixed
    {
        if ($request->exists('rule')) {
            return $request->input('rule');
        }

        $config = $request->input('config');
        if (is_array($config) && array_key_exists('rule', $config)) {
            return $config['rule'];
        }

        return null;
    }

    private function validateExplicitRuleSchema($validator, mixed $rawRule): void
    {
        if (!is_array($rawRule)) {
            $validator->errors()->add('rule', 'Rule definition must be an object.');
            return;
        }

        $type = $this->asTrimmedLowerString($rawRule['type'] ?? null);
        if ($type === null || !in_array($type, self::EXPLICIT_RULE_TYPES, true)) {
            $validator->errors()->add('rule.type', 'Rule type must be one of boolean, numeric, select, auto_metric.');
        }

        $operator = $this->asTrimmedLowerString($rawRule['operator'] ?? null);
        if ($operator === null || !in_array($operator, self::EXPLICIT_RULE_OPERATORS, true)) {
            $validator->errors()->add('rule.operator', 'Rule operator must be one of >, >=, <, <=, ==, !=, in, not_in.');
        }

        if (!array_key_exists('threshold', $rawRule)) {
            $validator->errors()->add('rule.threshold', 'Rule threshold is required.');
        }

        if (!array_key_exists('required', $rawRule) || !is_bool($rawRule['required'])) {
            $validator->errors()->add('rule.required', 'Rule required must be a boolean.');
        }

        $metricKey = $this->asTrimmedString($rawRule['metric_key'] ?? null);
        if ($type === 'auto_metric' && $metricKey === null) {
            $validator->errors()->add('rule.metric_key', 'auto_metric rules require metric_key.');
        }
        if (array_key_exists('metric_key', $rawRule) && $rawRule['metric_key'] !== null && $metricKey === null) {
            $validator->errors()->add('rule.metric_key', 'metric_key must be a non-empty string or null.');
        }

        $threshold = $rawRule['threshold'] ?? null;
        if ($operator === 'in' || $operator === 'not_in') {
            if (!is_array($threshold) || count($threshold) === 0) {
                $validator->errors()->add('rule.threshold', 'in/not_in operators require a non-empty threshold array.');
                return;
            }

            foreach ($threshold as $index => $entry) {
                if (!is_scalar($entry)) {
                    $validator->errors()->add("rule.threshold.$index", 'Each threshold entry must be a scalar value.');
                    continue;
                }
                if (trim((string) $entry) === '') {
                    $validator->errors()->add("rule.threshold.$index", 'Threshold entries cannot be empty.');
                }
            }
        } elseif (is_array($threshold)) {
            $validator->errors()->add('rule.threshold', 'Only in/not_in operators accept array thresholds.');
        } elseif (($type === 'numeric' || $type === 'auto_metric') && !is_numeric($threshold)) {
            $validator->errors()->add('rule.threshold', 'Numeric and auto_metric rules require numeric thresholds.');
        }

        if ($type === 'boolean' && !in_array($operator, ['==', '!='], true)) {
            $validator->errors()->add('rule.operator', 'Boolean rules only support == or != operators.');
        }
        if ($type === 'select' && !in_array($operator, ['==', '!=', 'in', 'not_in'], true)) {
            $validator->errors()->add('rule.operator', 'Select rules only support ==, !=, in, not_in operators.');
        }
        if (($type === 'numeric' || $type === 'auto_metric') && !in_array($operator, ['>', '>=', '<', '<=', '==', '!='], true)) {
            $validator->errors()->add('rule.operator', 'Numeric and auto_metric rules only support >, >=, <, <=, ==, != operators.');
        }
    }

    private function normalizeRulePayload(array $payload): array
    {
        $explicitRule = $payload['rule'] ?? ($payload['config']['rule'] ?? null);
        $normalizedRule = is_array($explicitRule)
            ? $this->normalizeExplicitRule($explicitRule)
            : $this->normalizeLegacyRule($payload);

        if ($normalizedRule !== null) {
            $payload['required'] = (bool) $normalizedRule['required'];
            $payload['type'] = $this->mapRuleTypeToChecklistItemType((string) $normalizedRule['type']);
            $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
            $config['rule'] = $normalizedRule;

            if ((string) $normalizedRule['type'] === 'select' && !array_key_exists('options', $config)) {
                $threshold = $normalizedRule['threshold'];
                $options = is_array($threshold) ? $threshold : [$threshold];
                $config['options'] = array_values(array_map(
                    fn (mixed $entry): string => trim((string) $entry),
                    array_filter($options, fn (mixed $entry): bool => is_scalar($entry) && trim((string) $entry) !== '')
                ));
            }

            $payload['config'] = $config;
        }

        unset($payload['rule']);

        return $payload;
    }

    private function normalizeExplicitRule(array $rawRule): ?array
    {
        $type = $this->asTrimmedLowerString($rawRule['type'] ?? null);
        $operator = $this->asTrimmedLowerString($rawRule['operator'] ?? null);
        $required = $rawRule['required'] ?? null;

        if ($type === null || !in_array($type, self::EXPLICIT_RULE_TYPES, true)) {
            return null;
        }
        if ($operator === null || !in_array($operator, self::EXPLICIT_RULE_OPERATORS, true)) {
            return null;
        }
        if (!array_key_exists('threshold', $rawRule) || !is_bool($required)) {
            return null;
        }

        $threshold = $rawRule['threshold'];
        if ($operator === 'in' || $operator === 'not_in') {
            if (!is_array($threshold)) {
                return null;
            }
            $threshold = array_values(array_map(
                fn (mixed $entry): string => trim((string) $entry),
                array_filter($threshold, fn (mixed $entry): bool => is_scalar($entry) && trim((string) $entry) !== '')
            ));
        } elseif ($type === 'numeric' || $type === 'auto_metric') {
            if (!is_numeric($threshold)) {
                return null;
            }
            $threshold = (float) $threshold;
        } elseif (is_scalar($threshold)) {
            $threshold = trim((string) $threshold);
        }

        if (($operator === 'in' || $operator === 'not_in') && $threshold === []) {
            return null;
        }

        $normalized = [
            'type' => $type,
            'operator' => $operator,
            'threshold' => $threshold,
            'required' => (bool) $required,
        ];

        $metricKey = $this->asTrimmedString($rawRule['metric_key'] ?? null);
        if ($metricKey !== null) {
            $normalized['metric_key'] = $metricKey;
        } elseif ($type === 'auto_metric') {
            return null;
        }

        return $normalized;
    }

    private function normalizeLegacyRule(array $payload): ?array
    {
        $type = (string) ($payload['type'] ?? '');
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $required = (bool) ($payload['required'] ?? false);

        if ($type === 'checkbox') {
            return [
                'type' => 'boolean',
                'operator' => '==',
                'threshold' => 'true',
                'required' => $required,
            ];
        }

        if ($type === 'dropdown') {
            $options = $this->extractOptionStrings($config['options'] ?? null);
            if ($options === []) {
                return null;
            }

            return [
                'type' => 'select',
                'operator' => 'in',
                'threshold' => $options,
                'required' => $required,
            ];
        }

        if ($type !== 'number') {
            return null;
        }

        $normalizedOperator = $this->normalizeLegacyComparatorToExplicitOperator($config['comparator'] ?? null);
        if ($normalizedOperator === null || !array_key_exists('threshold', $config) || !is_numeric($config['threshold'])) {
            return null;
        }

        $legacy = [
            'type' => $this->asTrimmedString($config['auto_metric'] ?? null) !== null ? 'auto_metric' : 'numeric',
            'operator' => $normalizedOperator,
            'threshold' => (float) $config['threshold'],
            'required' => $required,
        ];

        $metricKey = $this->asTrimmedString($config['auto_metric'] ?? null);
        if ($metricKey !== null) {
            $legacy['metric_key'] = $metricKey;
        }

        return $legacy;
    }

    /**
     * @return array<int,string>
     */
    private function extractOptionStrings(mixed $rawOptions): array
    {
        if (!is_array($rawOptions)) {
            return [];
        }

        $options = [];
        foreach ($rawOptions as $entry) {
            if (is_scalar($entry)) {
                $normalized = trim((string) $entry);
            } elseif (is_array($entry)) {
                $candidate = $entry['key'] ?? $entry['value'] ?? $entry['id'] ?? $entry['label'] ?? null;
                $normalized = is_scalar($candidate) ? trim((string) $candidate) : '';
            } else {
                $normalized = '';
            }

            if ($normalized !== '') {
                $options[] = $normalized;
            }
        }

        return array_values(array_unique($options));
    }

    private function normalizeLegacyComparatorToExplicitOperator(mixed $rawComparator): ?string
    {
        if (!is_scalar($rawComparator)) {
            return null;
        }

        return match (strtolower(trim((string) $rawComparator))) {
            '>', 'gt', 'greater_than' => '>',
            '>=', 'gte', 'greater_than_or_equal' => '>=',
            '<', 'lt', 'less_than' => '<',
            '<=', 'lte', 'less_than_or_equal' => '<=',
            '=', 'eq', 'equals' => '==',
            default => null,
        };
    }

    private function mapRuleTypeToChecklistItemType(string $ruleType): string
    {
        return match ($ruleType) {
            'boolean' => 'checkbox',
            'select' => 'dropdown',
            'numeric', 'auto_metric' => 'number',
            default => 'checkbox',
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
}
