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
        $userId = $this->resolveUserId($request);
        $scope = $request->input('scope');
        $accountId = $request->has('accountId') ? (int) $request->integer('accountId') : null;

        $checklists = $this->checklistService->listForScope($userId, [
            'scope' => is_string($scope) && $scope !== '' ? $scope : null,
            'account_id' => $accountId,
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
        $userId = $this->resolveUserId($request);
        $this->assertAccountOwnership($payload, $userId);

        $checklist = $this->checklistService->createChecklist($userId, $payload)->fresh(['account']);

        return response()->json($checklist, 201);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, Checklist $checklist)
    {
        $this->abortIfUnauthorizedChecklist($request, $checklist);

        $payload = $this->validatePayload($request, true);
        $userId = $this->resolveUserId($request);
        $this->assertAccountOwnership($payload, $userId);
        $updated = $this->checklistService->updateChecklist($checklist, $payload);

        return response()->json($updated);
    }

    public function destroy(Request $request, Checklist $checklist)
    {
        $this->abortIfUnauthorizedChecklist($request, $checklist);

        $this->checklistService->softDeleteChecklist($checklist);
        return response()->noContent();
    }

    public function duplicate(Request $request, Checklist $checklist)
    {
        $this->abortIfUnauthorizedChecklist($request, $checklist);

        $copy = $this->checklistService->duplicateChecklist($checklist);

        return response()->json($copy, 201);
    }

    /**
     * @throws ValidationException
     */
    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'name' => [$required, 'string', 'max:160'],
            'scope' => [$required, Rule::in(['global', 'account', 'strategy'])],
            'enforcement_mode' => ['sometimes', Rule::in(['soft', 'strict'])],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $scope = (string) $request->input('scope', '');
            $accountId = $request->input('account_id');

            if ($scope === 'account' && !is_numeric($accountId)) {
                $validator->errors()->add('account_id', 'account_id is required for account scope checklist.');
            }

            if ($scope !== 'account' && $accountId !== null && $accountId !== '') {
                $validator->errors()->add('account_id', 'account_id is only allowed for account scope checklist.');
            }
        });

        return $validator->validate();
    }

    private function abortIfUnauthorizedChecklist(Request $request, Checklist $checklist): void
    {
        $userId = $this->resolveUserId($request);

        $query = Checklist::query()->whereKey((int) $checklist->id);
        $this->checklistService->applyUserScope($query, $userId);

        abort_unless($query->exists(), 404);
    }

    private function resolveUserId(Request $request): ?int
    {
        $fromAuth = $request->user()?->id;
        if (is_int($fromAuth) && $fromAuth > 0) {
            return $fromAuth;
        }

        $headerRaw = $request->header('X-User-Id');
        $fromHeader = is_numeric($headerRaw) ? (int) $headerRaw : 0;
        if ($fromHeader > 0) {
            return $fromHeader;
        }

        $fromQuery = (int) $request->integer('user_id', 0);
        if ($fromQuery > 0) {
            return $fromQuery;
        }

        return null;
    }

    private function assertAccountOwnership(array $payload, ?int $userId): void
    {
        if (!array_key_exists('account_id', $payload) || $payload['account_id'] === null) {
            return;
        }

        $query = Account::query()->whereKey((int) $payload['account_id']);
        if ($userId === null) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $userId);
        }

        if (!$query->exists()) {
            throw ValidationException::withMessages([
                'account_id' => ['account_id is outside your scope.'],
            ]);
        }
    }
}

