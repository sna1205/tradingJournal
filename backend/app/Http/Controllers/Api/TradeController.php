<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use App\Models\TradeImage;
use App\Services\AccountBalanceService;
use App\Services\TradeCalculationEngine;
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
        private readonly AccountBalanceService $accountBalanceService
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

    public function store(Request $request)
    {
        $payload = $this->validatePayload($request);
        $payload = $this->applyDefaults($payload);
        $payload['pair'] = strtoupper((string) $payload['pair']);

        $trade = DB::transaction(function () use ($payload): Trade {
            $account = $this->resolveAccountForWrite((int) $payload['account_id']);

            $payloadWithBalance = [
                ...$payload,
                'account_balance_before_trade' => (float) $account->current_balance,
            ];

            $createdTrade = Trade::create($this->hydrateWithCalculatedFields($payloadWithBalance));
            $this->accountBalanceService->rebuildAccountState((int) $account->id);

            return $createdTrade->fresh(['account']);
        });

        $this->touchAnalyticsCacheVersion();

        return response()->json($trade, 201);
    }

    public function show(Trade $trade)
    {
        $trade->load([
            'account',
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
            if (array_key_exists('account_id', $payload)) {
                $this->resolveAccountForWrite((int) $payload['account_id']);
            }

            $trade->update($payload);

            $this->accountBalanceService->rebuildMany([$previousAccountId, (int) $trade->account_id]);

            return $trade->fresh(['account']);
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
            'pair' => [$required, 'string', 'max:30', 'regex:/^[A-Z0-9._\/-]+$/i'],
            'direction' => [$required, 'in:buy,sell'],
            'entry_price' => [$required, 'numeric', 'gt:0'],
            'stop_loss' => [$required, 'numeric', 'gt:0'],
            'take_profit' => [$required, 'numeric', 'gt:0'],
            'actual_exit_price' => [$required, 'numeric', 'gt:0'],
            'lot_size' => [$required, 'numeric', 'min:0.0001'],
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
