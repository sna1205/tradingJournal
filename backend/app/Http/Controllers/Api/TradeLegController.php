<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Services\AccountBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TradeLegController extends Controller
{
    public function __construct(
        private readonly AccountBalanceService $accountBalanceService
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

        $created = DB::transaction(function () use ($trade, $payload): TradeLeg {
            $leg = $trade->legs()->create($payload);
            $this->accountBalanceService->rebuildAccountState((int) $trade->account_id);

            return $leg->fresh();
        });

        $this->touchAnalyticsCacheVersion();

        return response()->json($created, 201);
    }

    public function update(Request $request, TradeLeg $tradeLeg)
    {
        $trade = $tradeLeg->trade()->firstOrFail();
        $this->authorize('update', $trade);

        $payload = $this->validateLegPayload($request);

        $updated = DB::transaction(function () use ($tradeLeg, $payload): TradeLeg {
            $tradeLeg->update($payload);
            $trade = $tradeLeg->trade()->firstOrFail();
            $this->accountBalanceService->rebuildAccountState((int) $trade->account_id);

            return $tradeLeg->fresh();
        });

        $this->touchAnalyticsCacheVersion();

        return response()->json($updated);
    }

    public function destroy(TradeLeg $tradeLeg)
    {
        $trade = $tradeLeg->trade()->firstOrFail();
        $this->authorize('delete', $trade);

        DB::transaction(function () use ($tradeLeg): void {
            $trade = $tradeLeg->trade()->firstOrFail();
            $accountId = (int) $trade->account_id;
            $tradeLeg->delete();
            $this->accountBalanceService->rebuildAccountState($accountId);
        });

        $this->touchAnalyticsCacheVersion();

        return response()->noContent();
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
