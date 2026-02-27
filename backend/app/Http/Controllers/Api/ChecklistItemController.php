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
                if (!is_scalar($rawComparator) || trim((string) $rawComparator) === '') {
                    $validator->errors()->add('config.comparator', 'Number rules require a comparator.');
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
        });

        return $validator->validate();
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
}
