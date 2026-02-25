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
    private const SESSION_ENUM_VALUES = [
        'asia',
        'london',
        'new_york',
        'overlap',
        'off_session',
    ];

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
                'strategyModel',
                'setup',
                'killzone',
                'tags',
                'images' => fn ($query) => $query
                    ->select(['id', 'trade_id', 'image_url', 'thumbnail_url', 'file_size', 'file_type', 'sort_order', 'context_tag', 'timeframe', 'annotation_notes'])
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
                'realized_r_multiple' => $payloadWithMetrics['realized_r_multiple'],
                'avg_entry_price' => $payloadWithMetrics['avg_entry_price'],
                'avg_exit_price' => $payloadWithMetrics['avg_exit_price'],
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

            $legs = $this->normalizeLegsForWrite($payloadWithMetrics['legs'] ?? [], (string) $payloadWithMetrics['date']);
            $createdTrade = Trade::create($this->extractTradeAttributes($payloadWithMetrics));
            if (count($legs) > 0) {
                $createdTrade->legs()->createMany($legs);
            }
            $this->syncTradeTags($createdTrade, $this->extractTagIds($payloadWithMetrics));
            $this->accountBalanceService->rebuildAccountState((int) $account->id);

            return $createdTrade->fresh(['account', 'instrument', 'strategyModel', 'setup', 'killzone', 'tags', 'legs']);
        });

        $this->touchAnalyticsCacheVersion();

        return response()->json($trade, 201);
    }

    public function show(Trade $trade)
    {
        $trade->load([
            'account',
            'instrument',
            'strategyModel',
            'setup',
            'killzone',
            'tags',
            'psychology',
            'legs' => fn ($query) => $query->orderBy('executed_at')->orderBy('id'),
            'images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        $disk = (string) config('filesystems.trade_images_disk', 'public');
        $images = $trade->images->map(
            fn (TradeImage $image): array => $this->serializeTradeImage($image, $disk)
        )->values();
        $trade->unsetRelation('images');

        return response()->json([
            'trade' => $trade,
            'legs' => $trade->legs->values(),
            'psychology' => $trade->psychology,
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

            $trade->update($this->extractTradeAttributes($payloadWithMetrics));

            if (array_key_exists('legs', $payload)) {
                $legs = $this->normalizeLegsForWrite($payloadWithMetrics['legs'] ?? [], (string) $payloadWithMetrics['date']);
                $trade->legs()->delete();
                if (count($legs) > 0) {
                    $trade->legs()->createMany($legs);
                }
            }
            $this->syncTradeTags($trade, $this->extractTagIds($payloadWithMetrics));

            $this->accountBalanceService->rebuildMany([$previousAccountId, (int) $trade->account_id]);

            return $trade->fresh(['account', 'instrument', 'strategyModel', 'setup', 'killzone', 'tags', 'legs']);
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
        $requiredWithoutLegs = $isUpdate ? 'sometimes' : 'required_without:legs';

        $validator = Validator::make($input, [
            'account_id' => [$required, 'integer', 'exists:accounts,id'],
            'instrument_id' => [$required, 'integer', 'exists:instruments,id'],
            'strategy_model_id' => ['sometimes', 'nullable', 'integer', 'exists:strategy_models,id'],
            'setup_id' => ['sometimes', 'nullable', 'integer', 'exists:setups,id'],
            'killzone_id' => ['sometimes', 'nullable', 'integer', 'exists:killzones,id'],
            'session_enum' => ['sometimes', 'nullable', Rule::in(self::SESSION_ENUM_VALUES)],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:trade_tags,id'],
            'pair' => [$required, 'string', 'max:30', 'regex:/^[A-Z0-9._\/-]+$/i'],
            'direction' => [$required, 'in:buy,sell'],
            'entry_price' => [$required, 'numeric', 'gt:0'],
            'stop_loss' => [$required, 'numeric', 'gt:0'],
            'take_profit' => [$required, 'numeric', 'gt:0'],
            'actual_exit_price' => [$requiredWithoutLegs, 'numeric', 'gt:0'],
            'lot_size' => [$requiredWithoutLegs, 'numeric', 'min:0.0001'],
            'legs' => ['sometimes', 'array', 'min:1'],
            'legs.*.leg_type' => ['required_with:legs', 'in:entry,exit'],
            'legs.*.price' => ['required_with:legs', 'numeric', 'gt:0'],
            'legs.*.quantity_lots' => ['required_with:legs', 'numeric', 'min:0.0001'],
            'legs.*.executed_at' => ['nullable', 'date'],
            'legs.*.fees' => ['sometimes', 'numeric'],
            'legs.*.notes' => ['nullable', 'string', 'max:2000'],
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
            'avg_entry_price' => ['prohibited'],
            'avg_exit_price' => ['prohibited'],
            'realized_r_multiple' => ['prohibited'],
            'risk_percent' => ['prohibited'],
            'account_balance_before_trade' => ['prohibited'],
            'account_balance_after_trade' => ['prohibited'],
        ]);

        $validator->after(function ($validator) use ($input, $isUpdate, $existingTrade): void {
            $legs = $this->normalizeLegsForCalculation(
                $input['legs'] ?? null,
                (string) ($input['date'] ?? ($existingTrade?->date ?? now()->toIso8601String()))
            );
            $legSummary = $this->summarizeLegs($legs);

            $entryPrice = $legSummary['avg_entry_price'] > 0
                ? $legSummary['avg_entry_price']
                : $this->readFloatFromInputOrTrade($input, 'entry_price', $existingTrade);
            $stopLoss = $this->readFloatFromInputOrTrade($input, 'stop_loss', $existingTrade);
            $takeProfit = $this->readFloatFromInputOrTrade($input, 'take_profit', $existingTrade);
            $direction = (string) ($input['direction'] ?? ($existingTrade?->direction ?? ''));
            $instrumentId = $this->readIntFromInputOrTrade($input, 'instrument_id', $existingTrade);
            $killzoneId = $this->readIntFromInputOrTrade($input, 'killzone_id', $existingTrade);
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

            if (array_key_exists('legs', $input)) {
                if ($legSummary['entry_count'] === 0) {
                    $validator->errors()->add('legs', 'At least one entry leg is required.');
                }
                if ($legSummary['exit_count'] === 0) {
                    $validator->errors()->add('legs', 'At least one exit leg is required.');
                }
                if ($legSummary['exit_quantity'] > ($legSummary['entry_quantity'] + 0.000001)) {
                    $validator->errors()->add('legs', 'Exit quantity cannot exceed total entry quantity.');
                }

                foreach ($legs as $index => $leg) {
                    $timestamp = strtotime((string) $leg['executed_at']);
                    if ($timestamp !== false && $timestamp > now()->addMinute()->getTimestamp()) {
                        $validator->errors()->add("legs.$index.executed_at", 'Leg execution time cannot be in the future.');
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

            if ($killzoneId !== null) {
                $killzoneSessionEnum = (string) (DB::table('killzones')
                    ->where('id', $killzoneId)
                    ->value('session_enum') ?? '');
                $sessionEnum = (string) ($input['session_enum'] ?? ($existingTrade?->session_enum ?? ''));
                if ($killzoneSessionEnum !== '' && $sessionEnum !== '' && $killzoneSessionEnum !== $sessionEnum) {
                    $validator->errors()->add('session_enum', 'Session enum must match selected killzone.');
                }
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
        if (!array_key_exists('strategy_model_id', $input) && array_key_exists('model_id', $input)) {
            $input['strategy_model_id'] = $input['model_id'];
        }
        if (!array_key_exists('session_enum', $input) && array_key_exists('sessionEnum', $input)) {
            $input['session_enum'] = $input['sessionEnum'];
        }
        if (!array_key_exists('tag_ids', $input) && array_key_exists('tags', $input)) {
            $input['tag_ids'] = $input['tags'];
        }
        if (!array_key_exists('date', $input) && array_key_exists('close_date', $input)) {
            $input['date'] = $input['close_date'];
        }

        unset($input['symbol'], $input['position_size'], $input['strategy_model'], $input['model_id'], $input['sessionEnum'], $input['tags'], $input['close_date']);

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
        if (!array_key_exists('strategy_model_id', $input) && array_key_exists('model_id', $input)) {
            $input['strategy_model_id'] = $input['model_id'];
        }
        if (!array_key_exists('tag_ids', $input) && array_key_exists('tags', $input)) {
            $input['tag_ids'] = $input['tags'];
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
            'strategy_model_id' => $input['strategy_model_id'] ?? null,
            'setup_id' => $input['setup_id'] ?? null,
            'killzone_id' => $input['killzone_id'] ?? null,
            'tag_ids' => $input['tag_ids'] ?? null,
            'pair' => $input['pair'] ?? null,
            'direction' => $input['direction'] ?? null,
            'session_enum' => $input['session_enum'] ?? null,
            'session' => $input['session'] ?? null,
            'model' => $input['model'] ?? null,
            'image_context_tag' => $input['image_context_tag'] ?? null,
            'image_timeframe' => $input['image_timeframe'] ?? null,
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
        $payload['session_enum'] = $payload['session_enum'] ?? null;
        $payload['model'] = $payload['model'] ?? 'General';
        $payload['tag_ids'] = $payload['tag_ids'] ?? [];
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

        $existingLegs = $existingTrade->legs()
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($leg): array => [
                'leg_type' => (string) $leg->leg_type,
                'price' => (float) $leg->price,
                'quantity_lots' => (float) $leg->quantity_lots,
                'executed_at' => (string) $leg->executed_at,
                'fees' => (float) ($leg->fees ?? 0),
                'notes' => $leg->notes,
            ])
            ->all();
        $existingTagIds = $existingTrade->tags()
            ->pluck('trade_tags.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return [
            'account_id' => $payload['account_id'] ?? (int) $existingTrade->account_id,
            'instrument_id' => $payload['instrument_id'] ?? (int) $existingTrade->instrument_id,
            'strategy_model_id' => $payload['strategy_model_id'] ?? ($existingTrade->strategy_model_id !== null ? (int) $existingTrade->strategy_model_id : null),
            'setup_id' => $payload['setup_id'] ?? ($existingTrade->setup_id !== null ? (int) $existingTrade->setup_id : null),
            'killzone_id' => $payload['killzone_id'] ?? ($existingTrade->killzone_id !== null ? (int) $existingTrade->killzone_id : null),
            'session_enum' => $payload['session_enum'] ?? ($existingTrade->session_enum !== null ? (string) $existingTrade->session_enum : null),
            'tag_ids' => $payload['tag_ids'] ?? $existingTagIds,
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
            'legs' => $payload['legs'] ?? $existingLegs,
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
        $normalizedLegs = $this->normalizeLegsForCalculation(
            $payload['legs'] ?? null,
            (string) ($payload['date'] ?? CarbonImmutable::now()->toIso8601String())
        );
        $legSummary = $this->summarizeLegs($normalizedLegs);

        $prepared = [
            ...$payload,
            'pair' => strtoupper((string) $instrument->symbol),
            'entry_price' => (float) ($payload['entry_price'] ?? 0),
            'actual_exit_price' => (float) ($payload['actual_exit_price'] ?? ($payload['entry_price'] ?? 0)),
            'lot_size' => (float) ($payload['lot_size'] ?? 0),
            'account_balance_before_trade' => (float) $account->current_balance,
            'instrument_tick_size' => (float) $instrument->tick_size,
            'instrument_tick_value' => (float) $instrument->tick_value,
            'commission' => (float) ($payload['commission'] ?? 0),
            'swap' => (float) ($payload['swap'] ?? 0),
            'spread_cost' => (float) ($payload['spread_cost'] ?? 0),
            'slippage_cost' => (float) ($payload['slippage_cost'] ?? 0),
            'legs' => $normalizedLegs,
            'tag_ids' => $this->extractTagIds($payload),
        ];

        $prepared = $this->applyTaxonomyLabels($prepared);

        $calculated = $this->calculationEngine->calculate($prepared);
        $lotSizeFromLegs = $legSummary['entry_quantity'] > 0
            ? $legSummary['entry_quantity']
            : (float) ($payload['lot_size'] ?? 0);

        return [
            ...$prepared,
            ...$calculated,
            'entry_price' => (float) $calculated['avg_entry_price'],
            'actual_exit_price' => (float) $calculated['avg_exit_price'],
            'lot_size' => $lotSizeFromLegs,
            'avg_entry_price' => (float) $calculated['avg_entry_price'],
            'avg_exit_price' => (float) $calculated['avg_exit_price'],
            'r_multiple' => (float) $calculated['realized_r_multiple'],
            'realized_r_multiple' => (float) $calculated['realized_r_multiple'],
        ];
    }

    /**
     * @param mixed $legs
     * @return array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }>
     */
    private function normalizeLegsForCalculation(mixed $legs, string $fallbackExecutedAt): array
    {
        if (!is_array($legs)) {
            return [];
        }

        $normalized = [];
        foreach ($legs as $index => $leg) {
            $row = is_array($leg)
                ? $leg
                : (is_object($leg) ? (array) $leg : null);
            if ($row === null) {
                continue;
            }

            $legType = strtolower((string) ($row['leg_type'] ?? ''));
            if (!in_array($legType, ['entry', 'exit'], true)) {
                continue;
            }

            $price = (float) ($row['price'] ?? 0);
            $quantity = (float) ($row['quantity_lots'] ?? 0);
            if ($price <= 0 || $quantity <= 0) {
                continue;
            }

            $executedAt = (string) ($row['executed_at'] ?? '');
            $timestamp = strtotime($executedAt);
            if ($timestamp === false) {
                $fallbackTimestamp = strtotime($fallbackExecutedAt);
                $timestamp = $fallbackTimestamp !== false
                    ? ($fallbackTimestamp + (int) $index)
                    : (time() + (int) $index);
            }

            $normalized[] = [
                'leg_type' => $legType,
                'price' => $price,
                'quantity_lots' => $quantity,
                'executed_at' => date('c', $timestamp),
                'fees' => (float) ($row['fees'] ?? 0),
                'notes' => isset($row['notes']) && $row['notes'] !== null
                    ? (string) $row['notes']
                    : null,
            ];
        }

        usort(
            $normalized,
            fn (array $left, array $right): int => strcmp($left['executed_at'], $right['executed_at'])
        );

        return $normalized;
    }

    /**
     * @param array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }> $legs
     * @return array{
     *   entry_count:int,
     *   exit_count:int,
     *   entry_quantity:float,
     *   exit_quantity:float,
     *   avg_entry_price:float,
     *   avg_exit_price:float
     * }
     */
    private function summarizeLegs(array $legs): array
    {
        $entryLegs = array_values(array_filter($legs, fn (array $leg): bool => $leg['leg_type'] === 'entry'));
        $exitLegs = array_values(array_filter($legs, fn (array $leg): bool => $leg['leg_type'] === 'exit'));

        $entryQuantity = array_sum(array_column($entryLegs, 'quantity_lots'));
        $exitQuantity = array_sum(array_column($exitLegs, 'quantity_lots'));

        return [
            'entry_count' => count($entryLegs),
            'exit_count' => count($exitLegs),
            'entry_quantity' => $entryQuantity,
            'exit_quantity' => $exitQuantity,
            'avg_entry_price' => $this->weightedAveragePrice($entryLegs),
            'avg_exit_price' => $this->weightedAveragePrice($exitLegs),
        ];
    }

    /**
     * @param array<int,array{price:float,quantity_lots:float}> $legs
     */
    private function weightedAveragePrice(array $legs): float
    {
        $totalQty = array_sum(array_column($legs, 'quantity_lots'));
        if ($totalQty <= 0) {
            return 0.0;
        }

        $weightedSum = array_reduce(
            $legs,
            fn (float $sum, array $leg): float => $sum + ($leg['price'] * $leg['quantity_lots']),
            0.0
        );

        return $weightedSum / $totalQty;
    }

    /**
     * @param array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }> $legs
     * @return array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float,
     *   notes:string|null
     * }>
     */
    private function normalizeLegsForWrite(array $legs, string $fallbackExecutedAt): array
    {
        return $this->normalizeLegsForCalculation($legs, $fallbackExecutedAt);
    }

    /**
     * @param array<string,mixed> $payloadWithMetrics
     * @return array<string,mixed>
     */
    private function extractTradeAttributes(array $payloadWithMetrics): array
    {
        return collect($payloadWithMetrics)
            ->except([
                'legs',
                'tag_ids',
                'instrument_tick_size',
                'instrument_tick_value',
            ])
            ->all();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyTaxonomyLabels(array $payload): array
    {
        $strategyModelId = isset($payload['strategy_model_id']) && is_numeric($payload['strategy_model_id'])
            ? (int) $payload['strategy_model_id']
            : null;
        $setupId = isset($payload['setup_id']) && is_numeric($payload['setup_id'])
            ? (int) $payload['setup_id']
            : null;
        $killzoneId = isset($payload['killzone_id']) && is_numeric($payload['killzone_id'])
            ? (int) $payload['killzone_id']
            : null;

        $strategyModelName = $strategyModelId !== null
            ? (string) (DB::table('strategy_models')->where('id', $strategyModelId)->value('name') ?? '')
            : '';
        $setupName = $setupId !== null
            ? (string) (DB::table('setups')->where('id', $setupId)->value('name') ?? '')
            : '';
        $killzoneSession = $killzoneId !== null
            ? (string) (DB::table('killzones')->where('id', $killzoneId)->value('session_enum') ?? '')
            : '';

        $sessionEnum = (string) ($payload['session_enum'] ?? '');
        if ($sessionEnum === '' && $killzoneSession !== '') {
            $sessionEnum = $killzoneSession;
        }

        $sessionLabel = $this->sessionLabelFromEnum($sessionEnum);
        if ($sessionLabel === '') {
            $sessionLabel = (string) ($payload['session'] ?? 'N/A');
        }

        $modelLabel = $strategyModelName !== ''
            ? $strategyModelName
            : ((string) ($payload['model'] ?? 'General'));
        if ($setupName !== '' && !str_contains(strtolower($modelLabel), strtolower($setupName))) {
            $modelLabel = trim($modelLabel . ' - ' . $setupName);
        }

        return [
            ...$payload,
            'session_enum' => $sessionEnum !== '' ? $sessionEnum : null,
            'session' => $sessionLabel,
            'model' => $modelLabel,
        ];
    }

    private function sessionLabelFromEnum(string $sessionEnum): string
    {
        return match ($sessionEnum) {
            'asia' => 'Asia',
            'london' => 'London',
            'new_york' => 'New York',
            'overlap' => 'London/NY Overlap',
            'off_session' => 'Off Session',
            default => '',
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,int>
     */
    private function extractTagIds(array $payload): array
    {
        $raw = $payload['tag_ids'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,int> $tagIds
     */
    private function syncTradeTags(Trade $trade, array $tagIds): void
    {
        $trade->tags()->sync($tagIds);
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
     * @return array{id:int,image_url:string,thumbnail_url:string,file_size:int,file_type:string,sort_order:int,context_tag:?string,timeframe:?string,annotation_notes:?string}
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
            'context_tag' => $image->context_tag !== null ? (string) $image->context_tag : null,
            'timeframe' => $image->timeframe !== null ? (string) $image->timeframe : null,
            'annotation_notes' => $image->annotation_notes !== null ? (string) $image->annotation_notes : null,
        ];
    }

    private function storageUrl(string $path, string $disk): string
    {
        // Always prefer relative /storage URLs for local disks so frontend host/proxy
        // differences (e.g. Vite 5173, Docker service hostnames) do not break images.
        $relativeFromPath = $this->extractStoragePath($path);
        if ($relativeFromPath !== null) {
            return $relativeFromPath;
        }

        $url = Storage::disk($disk)->url($path);
        $relativeFromUrl = $this->extractStoragePath($url);
        if ($relativeFromUrl !== null) {
            return $relativeFromUrl;
        }

        return $url;
    }

    private function extractStoragePath(string $value): ?string
    {
        if (str_starts_with($value, '/storage/')) {
            return $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/storage/')) {
            return null;
        }

        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        return $path . $query;
    }
}
