<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TradeConcurrencyException;
use App\Http\Controllers\Controller;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Support\ApiErrorResponder;
use App\Services\TradeExecutionOrchestrator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TradeLegController extends Controller
{
    public function __construct(
        private readonly TradeExecutionOrchestrator $tradeExecutionOrchestrator
    ) {
    }

    public function index(Trade $trade)
    {
        $this->authorize('view', $trade);

        $legs = $trade->legs()
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get();

        return response()->json($legs);
    }

    public function store(Request $request, Trade $trade)
    {
        $this->authorize('update', $trade);
        $payload = $this->validateLegPayload($request);

        try {
            $result = $this->tradeExecutionOrchestrator->mutateLegs((int) $trade->id, [
                'operations' => [[
                    'action' => 'add',
                    'payload' => $payload,
                ]],
            ], $request->user(), $request->header('If-Match'));
        } catch (TradeConcurrencyException $exception) {
            return $this->tradeConflictResponse($exception);
        } catch (ValidationException|HttpResponseException $exception) {
            return $this->standardizedViolationResponse($exception);
        }

        /** @var TradeLeg|null $created */
        $created = $result['operation_result']['created'][0] ?? null;
        abort_if($created === null, 500, 'Leg mutation completed without a created leg result.');

        $this->touchAnalyticsCacheVersion();

        return response()
            ->json($created, 201)
            ->header('ETag', $this->tradeExecutionOrchestrator->buildTradeEtag($result['trade']));
    }

    public function update(Request $request, TradeLeg $tradeLeg)
    {
        $trade = $tradeLeg->trade()->firstOrFail();
        $this->authorize('update', $trade);

        $payload = $this->validateLegPayload($request);

        try {
            $result = $this->tradeExecutionOrchestrator->mutateLegs((int) $trade->id, [
                'operations' => [[
                    'action' => 'update',
                    'trade_leg_id' => (int) $tradeLeg->id,
                    'payload' => $payload,
                ]],
            ], $request->user(), $request->header('If-Match'));
        } catch (TradeConcurrencyException $exception) {
            return $this->tradeConflictResponse($exception);
        } catch (ValidationException|HttpResponseException $exception) {
            return $this->standardizedViolationResponse($exception);
        }

        /** @var TradeLeg|null $updated */
        $updated = $result['operation_result']['updated'][0] ?? null;
        abort_if($updated === null, 500, 'Leg mutation completed without an updated leg result.');

        $this->touchAnalyticsCacheVersion();

        return response()
            ->json($updated)
            ->header('ETag', $this->tradeExecutionOrchestrator->buildTradeEtag($result['trade']));
    }

    public function destroy(Request $request, TradeLeg $tradeLeg)
    {
        $trade = $tradeLeg->trade()->firstOrFail();
        $this->authorize('delete', $trade);

        try {
            $this->tradeExecutionOrchestrator->mutateLegs((int) $trade->id, [
                'operations' => [[
                    'action' => 'delete',
                    'trade_leg_id' => (int) $tradeLeg->id,
                ]],
            ], $request->user(), $request->header('If-Match'));
        } catch (TradeConcurrencyException $exception) {
            return $this->tradeConflictResponse($exception);
        } catch (ValidationException|HttpResponseException $exception) {
            return $this->standardizedViolationResponse($exception);
        }

        $this->touchAnalyticsCacheVersion();

        return response()->noContent();
    }

    private function standardizedViolationResponse(ValidationException|HttpResponseException $exception): JsonResponse
    {
        $legacyErrors = [];
        $details = [];

        if ($exception instanceof ValidationException) {
            $legacyErrors = $exception->errors();
            $details = ApiErrorResponder::flattenValidationErrors($legacyErrors);
            return ApiErrorResponder::respond(
                request: request(),
                status: 422,
                code: 'trade_leg_mutation_blocked',
                message: 'Trade leg mutation blocked by enforcement policy.',
                details: $details,
                legacyErrors: $legacyErrors
            );
        }

        $response = $exception->getResponse();
        $decoded = json_decode((string) $response->getContent(), true);
        if (is_array($decoded)) {
            $legacyErrors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
            $details = array_filter([
                'failing_rules' => $decoded['failing_rules'] ?? null,
                'failed_required_rule_ids' => $decoded['failed_required_rule_ids'] ?? null,
                'failed_rule_reasons' => $decoded['failed_rule_reasons'] ?? null,
                'checklist' => $decoded['checklist'] ?? null,
            ], fn ($value): bool => $value !== null);
        }

        return ApiErrorResponder::respond(
            request: request(),
            status: 422,
            code: 'trade_leg_mutation_blocked',
            message: 'Trade leg mutation blocked by enforcement policy.',
            details: [[
                'field' => 'trade',
                'message' => 'Leg mutation violates enforcement policy.',
            ]],
            legacyErrors: $legacyErrors,
            meta: ['violation' => $details]
        );
    }

    private function tradeConflictResponse(TradeConcurrencyException $exception): JsonResponse
    {
        return ApiErrorResponder::respond(
            request: request(),
            status: 409,
            code: 'trade_revision_conflict',
            message: $exception->getMessage(),
            details: [[
                'field' => 'revision',
                'message' => 'Trade revision no longer matches latest server state.',
            ]],
            meta: [
                'current' => [
                    'revision' => $exception->currentRevision(),
                    'updatedAt' => $exception->currentUpdatedAt(),
                    'etag' => $exception->currentEtag(),
                ],
            ]
        )->header('ETag', $exception->currentEtag());
    }

    /**
     * @throws ValidationException
     * @return array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }
     */
    private function validateLegPayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'leg_type' => ['required', 'in:entry,exit'],
            'price' => ['required', 'numeric', 'gt:0'],
            'quantity_lots' => ['required', 'numeric', 'min:0.0001'],
            'executed_at' => ['required', 'date'],
            'fees' => ['sometimes', 'numeric'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator): void {
            $executedAt = $validator->getData()['executed_at'] ?? null;
            if ($executedAt === null) {
                return;
            }

            $timestamp = strtotime((string) $executedAt);
            if ($timestamp !== false && $timestamp > now()->addMinute()->getTimestamp()) {
                $validator->errors()->add('executed_at', 'Leg execution time cannot be in the future.');
            }
        });

        $validated = $validator->validate();

        return [
            'leg_type' => (string) $validated['leg_type'],
            'price' => (float) $validated['price'],
            'quantity_lots' => (float) $validated['quantity_lots'],
            'executed_at' => (string) $validated['executed_at'],
            'fees' => (float) ($validated['fees'] ?? 0),
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function touchAnalyticsCacheVersion(): void
    {
        if (!Cache::has('analytics:version')) {
            Cache::forever('analytics:version', 1);
        }

        Cache::increment('analytics:version');
    }
}
