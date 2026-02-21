<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use App\Services\TradeCalculationEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        private readonly TradeCalculationEngine $calculationEngine
    ) {
    }

    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));
        $filters = $this->normalizedFilterInputs($request->all());

        $trades = Trade::query()
            ->applyFilters($filters)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($trades);
    }

    public function store(Request $request)
    {
        $payload = $this->validatePayload($request);
        $payload = $this->applyDefaults($payload);
        $payload['pair'] = strtoupper((string) $payload['pair']);

        $trade = DB::transaction(function () use ($payload): Trade {
            return Trade::create($this->hydrateWithCalculatedFields($payload));
        });

        $this->touchAnalyticsCacheVersion();

        return response()->json($trade, 201);
    }

    public function show(Trade $trade)
    {
        return response()->json($trade);
    }

    public function update(Request $request, Trade $trade)
    {
        $payload = $this->validatePayload($request, true);
        $payload = $this->applyDefaults($payload, true);

        if (array_key_exists('pair', $payload)) {
            $payload['pair'] = strtoupper((string) $payload['pair']);
        }

        $recalculate = collect($this->calculationInputKeys())
            ->contains(fn (string $key) => array_key_exists($key, $payload));

        $updatedTrade = DB::transaction(function () use ($trade, $payload, $recalculate): Trade {
            $finalPayload = $payload;

            if ($recalculate) {
                $merged = [
                    ...$trade->only($this->calculationInputKeys()),
                    ...$finalPayload,
                ];
                $finalPayload = [
                    ...$finalPayload,
                    ...$this->calculationEngine->calculate($merged),
                ];
            }

            $trade->update($finalPayload);

            return $trade->fresh();
        });

        $this->touchAnalyticsCacheVersion();

        return response()->json($updatedTrade);
    }

    public function destroy(Trade $trade)
    {
        DB::transaction(fn () => $trade->delete());
        $this->touchAnalyticsCacheVersion();

        return response()->noContent();
    }

    /**
     * @throws ValidationException
     */
    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $input = $this->normalizedInput($request->all());
        $request->replace($input);

        $required = $isUpdate ? 'sometimes' : 'required';

        $validator = Validator::make($input, [
            'pair' => [$required, 'string', 'max:30'],
            'direction' => [$required, 'in:buy,sell'],
            'entry_price' => [$required, 'numeric', 'gt:0'],
            'stop_loss' => [$required, 'numeric', 'gt:0'],
            'take_profit' => [$required, 'numeric', 'gt:0'],
            'actual_exit_price' => [$required, 'numeric', 'gt:0'],
            'lot_size' => [$required, 'numeric', 'min:0.0001'],
            'account_balance_before_trade' => [$required, 'numeric', 'gt:0'],
            'followed_rules' => [$required, 'boolean'],
            'emotion' => [$required, Rule::in(self::EMOTION_VALUES)],
            'session' => ['sometimes', 'string', 'max:60'],
            'model' => ['sometimes', 'string', 'max:120'],
            'date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Calculated fields must come from server-side engine only.
            'risk_per_unit' => ['prohibited'],
            'reward_per_unit' => ['prohibited'],
            'monetary_risk' => ['prohibited'],
            'monetary_reward' => ['prohibited'],
            'profit_loss' => ['prohibited'],
            'rr' => ['prohibited'],
            'r_multiple' => ['prohibited'],
            'risk_percent' => ['prohibited'],
            'account_balance_after_trade' => ['prohibited'],
        ]);

        $validator->after(function ($validator) use ($input, $isUpdate): void {
            $hasEntry = array_key_exists('entry_price', $input);
            $hasStop = array_key_exists('stop_loss', $input);
            $hasTake = array_key_exists('take_profit', $input);

            if ($hasEntry && $hasStop && (float) $input['entry_price'] === (float) $input['stop_loss']) {
                $validator->errors()->add('stop_loss', 'Stop loss must differ from entry price.');
            }
            if ($hasEntry && $hasTake && (float) $input['entry_price'] === (float) $input['take_profit']) {
                $validator->errors()->add('take_profit', 'Take profit must differ from entry price.');
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

        return $payload;
    }

    private function hydrateWithCalculatedFields(array $payload): array
    {
        return [
            ...$payload,
            ...$this->calculationEngine->calculate($payload),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function calculationInputKeys(): array
    {
        return [
            'direction',
            'entry_price',
            'stop_loss',
            'take_profit',
            'actual_exit_price',
            'lot_size',
            'account_balance_before_trade',
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

