<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Checklist;
use App\Services\ChecklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChecklistController extends Controller
{
    public function __construct(
        private readonly ChecklistService $checklistService
    ) {
    }

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $scope = $request->input('scope');
        $accountId = $request->has('accountId') ? (int) $request->integer('accountId') : null;
        $strategyModelId = $request->has('strategyModelId')
            ? (int) $request->integer('strategyModelId')
            : null;

        $checklists = $this->checklistService->listForScope($userId, [
            'scope' => is_string($scope) && $scope !== '' ? $scope : null,
            'account_id' => $accountId,
            'strategy_model_id' => $strategyModelId,
            'search' => $request->input('search'),
            'is_active' => $request->has('include_inactive')
                ? null
                : true,
        ]);

        return response()->json($checklists);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $payload = $this->validatePayload($request);
        $userId = (int) $request->user()->id;
        $this->assertAccountOwnership($payload, $userId);

        $checklist = $this->checklistService->createChecklist($userId, $payload)->fresh(['account', 'strategyModel']);

        return response()->json($checklist, 201);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, Checklist $checklist)
    {
        $this->authorize('update', $checklist);

        $payload = $this->validatePayload($request, true, $checklist);
        $userId = (int) $request->user()->id;
        $this->assertAccountOwnership($payload, $userId);
        $updated = $this->checklistService->updateChecklist($checklist, $payload);

        return response()->json($updated);
    }

    public function destroy(Request $request, Checklist $checklist)
    {
        $this->authorize('delete', $checklist);

        $this->checklistService->softDeleteChecklist($checklist);
        return response()->noContent();
    }

    public function duplicate(Request $request, Checklist $checklist)
    {
        $this->authorize('view', $checklist);

        $copy = $this->checklistService->duplicateChecklist($checklist);

        return response()->json($copy, 201);
    }

    /**
     * @throws ValidationException
     */
    private function validatePayload(Request $request, bool $isUpdate = false, ?Checklist $existingChecklist = null): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'name' => [$required, 'string', 'max:160'],
            'scope' => [$required, Rule::in(['global', 'account', 'strategy'])],
            'enforcement_mode' => ['sometimes', Rule::in(['soft', 'strict'])],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'strategy_model_id' => ['sometimes', 'nullable', 'integer', 'exists:strategy_models,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request, $existingChecklist): void {
            $scope = (string) $request->input('scope', (string) ($existingChecklist?->scope ?? ''));
            $accountId = $request->input('account_id');
            $strategyModelId = $request->input('strategy_model_id');

            if ($scope === 'account' && !is_numeric($accountId)) {
                $validator->errors()->add('account_id', 'account_id is required for account scope checklist.');
            }

            if ($scope === 'strategy' && !is_numeric($strategyModelId)) {
                $validator->errors()->add('strategy_model_id', 'strategy_model_id is required for strategy scope checklist.');
            }

            if ($scope !== 'account' && $accountId !== null && $accountId !== '') {
                $validator->errors()->add('account_id', 'account_id is only allowed for account scope checklist.');
            }

            if ($scope !== 'strategy' && $strategyModelId !== null && $strategyModelId !== '') {
                $validator->errors()->add('strategy_model_id', 'strategy_model_id is only allowed for strategy scope checklist.');
            }
        });

        return $validator->validate();
    }

    private function assertAccountOwnership(array $payload, int $userId): void
    {
        if (!array_key_exists('account_id', $payload) || $payload['account_id'] === null) {
            return;
        }

        $query = Account::query()
            ->whereKey((int) $payload['account_id'])
            ->where('user_id', $userId);

        if (!$query->exists()) {
            throw ValidationException::withMessages([
                'account_id' => ['account_id is outside your scope.'],
            ]);
        }
    }
}
