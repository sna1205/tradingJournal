<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Trade;
use App\Services\ChecklistService;
use App\Services\TradeChecklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TradeChecklistResponseController extends Controller
{
    public function __construct(
        private readonly ChecklistService $checklistService,
        private readonly TradeChecklistService $tradeChecklistService
    ) {
    }

    public function show(Request $request, Trade $trade)
    {
        $this->authorize('view', $trade);
        $userId = (int) $request->user()->id;

        $accountId = $trade->account_id !== null ? (int) $trade->account_id : null;
        $checklist = $this->checklistService->resolveApplicableChecklist($userId, $accountId);

        if ($checklist === null) {
            return response()->json([
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
            ]);
        }

        return response()->json(
            $this->tradeChecklistService->buildTradeChecklistState($trade, $checklist, true)
        );
    }

    /**
     * @throws ValidationException
     */
    public function upsert(Request $request, Trade $trade)
    {
        $this->authorize('update', $trade);
        $userId = (int) $request->user()->id;

        $validator = Validator::make($request->all(), [
            'checklist_id' => ['sometimes', 'nullable', 'integer', 'exists:checklists,id'],
            'responses' => ['required', 'array'],
            'responses.*.checklist_item_id' => ['required', 'integer', 'exists:checklist_items,id'],
            'responses.*.value' => ['nullable'],
        ]);

        $payload = $validator->validate();

        $accountId = $trade->account_id !== null ? (int) $trade->account_id : null;
        $checklist = null;

        if (array_key_exists('checklist_id', $payload) && $payload['checklist_id'] !== null) {
            $query = Checklist::query()->whereKey((int) $payload['checklist_id']);
            $this->checklistService->applyUserScope($query, $userId);
            $checklist = $query->first();
        }

        if ($checklist === null) {
            $checklist = $this->checklistService->resolveApplicableChecklist($userId, $accountId);
        }

        if ($checklist === null) {
            return response()->json([
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
            ]);
        }

        $result = $this->tradeChecklistService->upsertResponses($trade, $checklist, $payload['responses']);
        $this->checklistService->syncChecklistIncompleteFlag($trade, !$result['readiness']['ready']);

        return response()->json($result);
    }

}
