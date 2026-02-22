<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\Trade;
use App\Models\TradeImage;
use App\Services\AccountBalanceService;
use App\Services\TradeCalculationEngine;
use App\Services\TradeRiskPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TradeController extends Controller
{
    private const EMOTION_VALUES = [
        'neutral',
        'calm',
        'confident',
        'fearful',
        'greedy',
        'hesitant',
        'revenge',
    ];

    public function __construct(
        private readonly TradeCalculationEngine $calculationEngine,
        private readonly AccountBalanceService $accountBalanceService,
        private readonly TradeRiskPolicyService $tradeRiskPolicyService
    ) {
    }

    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));
        $filters = $this->normalizedFilterInputs($request->all());
        $disk = (string) config('filesystems.trade_images_disk', 'public');

        $trades = Trade::query()
            ->with([
                'account',
                'instrument',
                'images' => fn ($query) => $query
                    ->select(['id', 'trade_id', 'image_url', 'thumbnail_url', 'file_size', 'file_type', 'sort_order'])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->withCount('images')
            ->applyFilters($filters)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $trades->getCollection()->transform(function (Trade $trade) use ($disk): Trade {
            $serialized = $trade->images->map(
                fn (TradeImage $image): array => $this->serializeTradeImage($image, $disk)
            )->values();

            $trade->setRelation('images', $serialized);
            return $trade;
        });

        return response()->json($trades);
    }

    /**
     * Validate risk policy limits before save.
     */
    public function precheck(Request $request)
    {
        $tradeId = (int) $request->integer('trade_id', 0);
        $existingTrade = $tradeId > 0
            ? Trade::query()->find($tradeId)
            : null;
        $isUpdate = $existingTrade !== null;

        $payload = $this->validatePayload($request, $isUpdate, $existingTrade);
        $payload = $this->applyDefaults($payload, $isUpdate);
        $payload = $this->normalizePrecheckPayload($payload, $existingTrade);
        $payload['pair'] = strtoupper((string) $payload['pair']);

        $account = $this->resolveAccountForRead((int) $payload['account_id']);
        $instrument = $this->resolveInstrumentForRead((int) $payload['instrument_id']);
        $payloadWithMetrics = $this->buildPayloadWithCalculatedFields($payload, $account, $instrument);
        $riskEvaluation = $this->evaluateRiskPolicy(
            $payloadWithMetrics,
            $account,
            $isUpdate ? (int) $existingTrade->id : null
        );

        return response()->json([
            'allowed' => $riskEvaluation['allowed'],
            'requires_override_reason' => $riskEvaluation['requires_override_reason'],
            'policy' => $riskEvaluation['policy'],
            'violations' => $riskEvaluation['violations'],
            'stats' => $riskEvaluation['stats'],
            'calculated' => [
                'monetary_risk' => $payloadWithMetrics['monetary_risk'],
                'monetary_reward' => $payloadWithMetrics['monetary_reward'],
                'gross_profit_loss' => $payloadWithMetrics['gross_profit_loss'],
                'costs_total' => $payloadWithMetrics['costs_total'],
                'profit_loss' => $payloadWithMetrics['profit_loss'],
                'risk_percent' => $payloadWithMetrics['risk_percent'],
                'r_multiple' => $payloadWithMetrics['r_multiple'],
                'rr' => $payloadWithMetrics['rr'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $payload = $this->validatePayload($request);
        $payload = $this->applyDefaults($payload);
        $payload['pair'] = strtoupper((string) $payload['pair']);

        $trade = DB::transaction(function () use ($payload): Trade {
            $account = $this->resolveAccountForWrite((int) $payload['account_id']);
            $instrument = $this->resolveInstrumentForRead((int) $payload['instrument_id']);
            $payloadWithMetrics = $this->buildPayloadWithCalculatedFields($payload, $account, $instrument);
            $riskEvaluation = $this->evaluateRiskPolicy($payloadWithMetrics, $account, null);
            $this->throwIfRiskPolicyBlocked($riskEvaluation);

            $createdTrade = Trade::create($payloadWithMetrics);
            $this->accountBalanceService->rebuildAccountState((int) $account->id);

            return $createdTrade->fresh(['account', 'instrument']);
        });

        $this->touchAnalyticsCacheVersion();

        return response()->json($trade, 201);
    }

    public function show(Trade $trade)
    {
        $trade->load([
            'account',
            'instrument',
            'images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        $disk = (string) config('filesystems.trade_images_disk', 'public');
        $images = $trade->images->map(
            fn (TradeImage $image): array => $this->serializeTradeImage($image, $disk)
        )->values();
        $trade->unsetRelation('images');

        return response()->json([
            'trade' => $trade,
            'images' => $images,
        ]);
    }

    public function update(Request $request, Trade $trade)
    {
        $payload = $this->validatePayload($request, true, $trade);
        $payload = $this->applyDefaults($payload, true);

        if (array_key_exists('pair', $payload)) {
            $payload['pair'] = strtoupper((string) $payload['pair']);
        }

        $previousAccountId = (int) $trade->account_id;

        $updatedTrade = DB::transaction(function () use ($trade, $payload, $previousAccountId): Trade {
            $effectivePayload = $this->normalizePrecheckPayload($payload, $trade);
            $account = $this->resolveAccountForWrite((int) $effectivePayload['account_id']);
            $instrument = $this->resolveInstrumentForRead((int) $effectivePayload['instrument_id']);
            $payloadWithMetrics = $this->buildPayloadWithCalculatedFields($effectivePayload, $account, $instrument);
            $riskEvaluation = $this->evaluateRiskPolicy($payloadWithMetrics, $account, (int) $trade->id);
            $this->throwIfRiskPolicyBlocked($riskEvaluation);

            $trade->update($payload);

            $this->accountBalanceService->rebuildMany([$previousAccountId, (int) $trade->account_id]);

            return $trade->fresh(['account', 'instrument']);
        });

        $this->touchAnalyticsCacheVersion();

        return response()->json($updatedTrade);
    }

    public function destroy(Trade $trade)
    {
        $accountId = (int) $trade->account_id;
        DB::transaction(function () use ($trade, $accountId): void {
            $trade->delete();
            $this->accountBalanceService->rebuildAccountState($accountId);
        });
        $this->touchAnalyticsCacheVersion();

        return response()->noContent();
    }

    /**
     * @throws ValidationException
     */
    private function validatePayload(Request $request, bool $isUpdate = false, ?Trade $existingTrade = null): array
    {
        $input = $this->normalizedInput($request->all());
        $request->replace($input);

        $required = $isUpdate ? 'sometimes' : 'required';

        $validator = Validator::make($input, [
            'account_id' => [$required, 'integer', 'exists:accounts,id'],
            'instrument_id' => [$required, 'integer', 'exists:instruments,id'],
            'pair' => [$required, 'string', 'max:30', 'regex:/^[A-Z0-9._\/-]+$/i'],
            'direction' => [$required, 'in:buy,sell'],
            'entry_price' => [$required, 'numeric', 'gt:0'],
            'stop_loss' => [$required, 'numeric', 'gt:0'],
            'take_profit' => [$required, 'numeric', 'gt:0'],
            'actual_exit_price' => [$required, 'numeric', 'gt:0'],
            'lot_size' => [$required, 'numeric', 'min:0.0001'],
            'commission' => ['sometimes', 'numeric', 'min:0'],
            'swap' => ['sometimes', 'numeric'],
            'spread_cost' => ['sometimes', 'numeric', 'min:0'],
            'slippage_cost' => ['sometimes', 'numeric', 'min:0'],
            'risk_override_reason' => ['nullable', 'string', 'max:2000'],
            'followed_rules' => [$required, 'boolean'],
            'emotion' => [$required, Rule::in(self::EMOTION_VALUES)],
            'session' => ['sometimes', 'string', 'max:60'],
            'model' => ['sometimes', 'string', 'max:120'],
            'date' => [$required, 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Calculated fields must come from server-side engine only.
            'risk_per_unit' => ['prohibited'],
            'reward_per_unit' => ['prohibited'],
            'monetary_risk' => ['prohibited'],
            'monetary_reward' => ['prohibited'],
            'gross_profit_loss' => ['prohibited'],
            'costs_total' => ['prohibited'],
            'profit_loss' => ['prohibited'],
            'rr' => ['prohibited'],
            'r_multiple' => ['prohibited'],
            'risk_percent' => ['prohibited'],
            'account_balance_before_trade' => ['prohibited'],
            'account_balance_after_trade' => ['prohibited'],
        ]);

        $validator->after(function ($validator) use ($input, $isUpdate, $existingTrade): void {
            $entryPrice = $this->readFloatFromInputOrTrade($input, 'entry_price', $existingTrade);
            $stopLoss = $this->readFloatFromInputOrTrade($input, 'stop_loss', $existingTrade);
            $takeProfit = $this->readFloatFromInputOrTrade($input, 'take_profit', $existingTrade);
            $direction = (string) ($input['direction'] ?? ($existingTrade?->direction ?? ''));
            $instrumentId = $this->readIntFromInputOrTrade($input, 'instrument_id', $existingTrade);
            $pair = strtoupper((string) ($input['pair'] ?? ($existingTrade?->pair ?? '')));

            if ($entryPrice !== null && $stopLoss !== null && $entryPrice === $stopLoss) {
                $validator->errors()->add('stop_loss', 'Stop loss must differ from entry price.');
            }
            if ($entryPrice !== null && $takeProfit !== null && $entryPrice === $takeProfit) {
                $validator->errors()->add('take_profit', 'Take profit must differ from entry price.');
            }

            if ($entryPrice !== null && $stopLoss !== null && $takeProfit !== null && in_array($direction, ['buy', 'sell'], true)) {
                if ($direction === 'buy') {
                    if ($stopLoss >= $entryPrice) {
                        $validator->errors()->add('stop_loss', 'For buy trades, stop loss must be below entry.');
                    }
                    if ($takeProfit <= $entryPrice) {
                        $validator->errors()->add('take_profit', 'For buy trades, take profit must be above entry.');
                    }
                } else {
                    if ($stopLoss <= $entryPrice) {
                        $validator->errors()->add('stop_loss', 'For sell trades, stop loss must be above entry.');
                    }
                    if ($takeProfit >= $entryPrice) {
                        $validator->errors()->add('take_profit', 'For sell trades, take profit must be below entry.');
                    }
                }
            }

            if (array_key_exists('date', $input)) {
                $timestamp = strtotime((string) $input['date']);
                if ($timestamp !== false && $timestamp > now()->addMinute()->getTimestamp()) {
                    $validator->errors()->add('date', 'Close date cannot be in the future.');
                }
            }

            if ($instrumentId !== null && $pair !== '') {
                $instrumentSymbol = (string) (DB::table('instruments')
                    ->where('id', $instrumentId)
                    ->value('symbol') ?? '');
                if ($instrumentSymbol !== '' && strtoupper($instrumentSymbol) !== $pair) {
                    $validator->errors()->add('pair', 'Pair must match selected instrument symbol.');
                }
            }
            if ($instrumentId === null) {
                $validator->errors()->add('instrument_id', 'Instrument is required.');
            }

            if (!$isUpdate && !array_key_exists('pair', $input)) {
                $validator->errors()->add('pair', 'Symbol is required.');
            }
        });

        return $validator->validate();
    }

    private function normalizedInput(array $input): array
    {
        if (!array_key_exists('pair', $input) && array_key_exists('symbol', $input)) {
            $input['pair'] = $input['symbol'];
        }
        if (!array_key_exists('lot_size', $input) && array_key_exists('position_size', $input)) {
            $input['lot_size'] = $input['position_size'];
        }
        if (!array_key_exists('model', $input) && array_key_exists('strategy_model', $input)) {
            $input['model'] = $input['strategy_model'];
        }
        if (!array_key_exists('date', $input) && array_key_exists('close_date', $input)) {
            $input['date'] = $input['close_date'];
        }

        unset($input['symbol'], $input['position_size'], $input['strategy_model'], $input['close_date']);

        return $input;
    }

    private function readFloatFromInputOrTrade(array $input, string $field, ?Trade $trade): ?float
    {
        if (array_key_exists($field, $input) && is_numeric($input[$field])) {
            return (float) $input[$field];
        }

        if ($trade !== null && isset($trade->{$field}) && is_numeric((string) $trade->{$field})) {
            return (float) $trade->{$field};
        }

        return null;
    }

    private function readIntFromInputOrTrade(array $input, string $field, ?Trade $trade): ?int
    {
        if (array_key_exists($field, $input) && is_numeric($input[$field])) {
            return (int) $input[$field];
        }

        if ($trade !== null && isset($trade->{$field}) && is_numeric((string) $trade->{$field})) {
            return (int) $trade->{$field};
        }

        return null;
    }

    private function normalizedFilterInputs(array $input): array
    {
        if (!array_key_exists('pair', $input) && array_key_exists('symbol', $input)) {
            $input['pair'] = $input['symbol'];
        }
        if (!array_key_exists('model', $input) && array_key_exists('strategy_model', $input)) {
            $input['model'] = $input['strategy_model'];
        }
        if (!array_key_exists('date_from', $input) && array_key_exists('close_date_from', $input)) {
            $input['date_from'] = $input['close_date_from'];
        }
        if (!array_key_exists('date_to', $input) && array_key_exists('close_date_to', $input)) {
            $input['date_to'] = $input['close_date_to'];
        }

        return [
            'account_id' => $input['account_id'] ?? null,
            'account_ids' => $input['account_ids'] ?? null,
            'instrument_id' => $input['instrument_id'] ?? null,
            'pair' => $input['pair'] ?? null,
            'direction' => $input['direction'] ?? null,
            'session' => $input['session'] ?? null,
            'model' => $input['model'] ?? null,
            'emotion' => $input['emotion'] ?? null,
            'followed_rules' => $input['followed_rules'] ?? null,
            'date_from' => $input['date_from'] ?? null,
            'date_to' => $input['date_to'] ?? null,
        ];
    }

    private function applyDefaults(array $payload, bool $isUpdate = false): array
    {
        if ($isUpdate) {
            return $payload;
        }

        $payload['session'] = $payload['session'] ?? 'N/A';
        $payload['model'] = $payload['model'] ?? 'General';
        $payload['date'] = $payload['date'] ?? now()->toDateTimeString();
        $payload['commission'] = $payload['commission'] ?? 0;
        $payload['swap'] = $payload['swap'] ?? 0;
        $payload['spread_cost'] = $payload['spread_cost'] ?? 0;
        $payload['slippage_cost'] = $payload['slippage_cost'] ?? 0;

        return $payload;
    }

    private function normalizePrecheckPayload(array $payload, ?Trade $existingTrade = null): array
    {
        if ($existingTrade === null) {
            return $payload;
        }

        return [
            'account_id' => $payload['account_id'] ?? (int) $existingTrade->account_id,
            'instrument_id' => $payload['instrument_id'] ?? (int) $existingTrade->instrument_id,
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
            'emotion' => $payload['emotion'] ?? (string) $existingTrade->emotion,
            'risk_override_reason' => $payload['risk_override_reason'] ?? $existingTrade->risk_override_reason,
            'session' => $payload['session'] ?? (string) $existingTrade->session,
            'model' => $payload['model'] ?? (string) $existingTrade->model,
            'date' => $payload['date'] ?? (string) $existingTrade->date,
            'notes' => $payload['notes'] ?? $existingTrade->notes,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param object{id:int,starting_balance:numeric-string|int|float,current_balance:numeric-string|int|float} $account
     * @param object{id:int,tick_size:numeric-string|int|float,tick_value:numeric-string|int|float,symbol:string} $instrument
     * @return array<string, mixed>
     */
    private function buildPayloadWithCalculatedFields(array $payload, object $account, object $instrument): array
    {
        $prepared = [
            ...$payload,
            'pair' => strtoupper((string) $instrument->symbol),
            'account_balance_before_trade' => (float) $account->current_balance,
            'instrument_tick_size' => (float) $instrument->tick_size,
            'instrument_tick_value' => (float) $instrument->tick_value,
            'commission' => (float) ($payload['commission'] ?? 0),
            'swap' => (float) ($payload['swap'] ?? 0),
            'spread_cost' => (float) ($payload['spread_cost'] ?? 0),
            'slippage_cost' => (float) ($payload['slippage_cost'] ?? 0),
        ];

        return [
            ...$prepared,
            ...$this->calculationEngine->calculate($prepared),
        ];
    }

    /**
     * @param array<string, mixed> $payloadWithMetrics
     * @param object{id:int,starting_balance:numeric-string|int|float,current_balance:numeric-string|int|float} $account
     * @return array{
     *   allowed:bool,
     *   requires_override_reason:bool,
     *   policy:array<string,mixed>,
     *   violations:array<int,array{code:string,message:string,limit:float,actual:float}>,
     *   stats:array<string,float>
     * }
     */
    private function evaluateRiskPolicy(array $payloadWithMetrics, object $account, ?int $excludeTradeId): array
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
        ]);
    }

    /**
     * @param array{
     *   allowed:bool,
     *   requires_override_reason:bool,
     *   violations:array<int,array{code:string,message:string,limit:float,actual:float}>
     * } $evaluation
     * @throws ValidationException
     */
    private function throwIfRiskPolicyBlocked(array $evaluation): void
    {
        if ((bool) $evaluation['allowed']) {
            return;
        }

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

    private function touchAnalyticsCacheVersion(): void
    {
        if (!Cache::has('analytics:version')) {
            Cache::forever('analytics:version', 1);
        }

        Cache::increment('analytics:version');
    }

    private function resolveAccountForWrite(int $accountId): object
    {
        return DB::table('accounts')
            ->where('id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function resolveAccountForRead(int $accountId): object
    {
        return DB::table('accounts')
            ->where('id', $accountId)
            ->firstOrFail();
    }

    private function resolveInstrumentForRead(int $instrumentId): object
    {
        return DB::table('instruments')
            ->where('id', $instrumentId)
            ->firstOrFail();
    }

    /**
     * @return array{id:int,image_url:string,thumbnail_url:string,file_size:int,file_type:string,sort_order:int}
     */
    private function serializeTradeImage(TradeImage $image, string $disk): array
    {
        return [
            'id' => (int) $image->id,
            'image_url' => $this->storageUrl($image->image_url, $disk),
            'thumbnail_url' => $this->storageUrl($image->thumbnail_url, $disk),
            'file_size' => (int) $image->file_size,
            'file_type' => (string) $image->file_type,
            'sort_order' => (int) $image->sort_order,
        ];
    }

    private function storageUrl(string $path, string $disk): string
    {
        $requestBase = rtrim((string) (request()?->getSchemeAndHttpHost() ?: config('app.url')), '/');

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $this->normalizeLocalStorageUrl($path, $requestBase);
        }

        $url = Storage::disk($disk)->url($path);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $this->normalizeLocalStorageUrl($url, $requestBase);
        }

        $base = $requestBase;
        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        return $base . '/' . ltrim($url, '/');
    }

    private function normalizeLocalStorageUrl(string $url, string $requestBase): string
    {
        if ($requestBase === '') {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $path = $parts['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/storage/')) {
            return $url;
        }

        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        return $requestBase . $path . $query;
    }
}
