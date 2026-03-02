<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Trade;
use App\Support\ApiErrorResponder;
use App\Services\AccountBalanceService;
use App\Services\Analytics\EquityEngine;
use App\Services\Analytics\StreakEngine;
use App\Services\Analytics\TradeMetricsEngine;
use App\Services\PropChallengeService;
use App\Services\TradeRiskPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    private const MAX_ANALYTICS_WINDOW_DAYS = 366;
    private const DEFAULT_ANALYTICS_WINDOW_DAYS = 180;
    private const MAX_CURSOR_PAGE_SIZE = 250;
    private const DEFAULT_CURSOR_PAGE_SIZE = 120;

    public function __construct(
        private readonly AccountBalanceService $accountBalanceService,
        private readonly EquityEngine $equityEngine,
        private readonly TradeMetricsEngine $metricsEngine,
        private readonly StreakEngine $streakEngine,
        private readonly TradeRiskPolicyService $tradeRiskPolicyService,
        private readonly PropChallengeService $propChallengeService
    ) {
    }

    public function index(Request $request)
    {
        $query = Account::query()
            ->where('user_id', (int) $request->user()->id)
            ->orderByDesc('is_active')
            ->orderBy('name');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->get());
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $payload = $this->validatePayload($request);

        $account = Account::query()->create([
            ...$payload,
            'user_id' => (int) $request->user()->id,
            'current_balance' => $payload['starting_balance'],
        ]);

        return response()->json($account, 201);
    }

    public function show(Account $account)
    {
        $this->authorize('view', $account);
        $this->accountBalanceService->rebuildAccountState((int) $account->id);

        return response()->json($account->fresh());
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, Account $account)
    {
        $this->authorize('update', $account);
        $payload = $this->validatePayload($request, true, (int) $account->id);

        DB::transaction(function () use ($account, $payload): void {
            $account->update($payload);

            if (array_key_exists('starting_balance', $payload)) {
                $this->accountBalanceService->rebuildAccountState((int) $account->id);
            }
        });

        return response()->json($account->fresh());
    }

    public function destroy(Account $account)
    {
        $this->authorize('delete', $account);

        if ($account->trades()->exists()) {
            return ApiErrorResponder::respond(
                request: request(),
                status: 422,
                code: 'account_delete_blocked',
                message: 'Cannot delete account with existing trades. Reassign or delete trades first.',
                details: [[
                    'field' => 'account',
                    'message' => 'Cannot delete account with existing trades. Reassign or delete trades first.',
                ]]
            );
        }

        $account->delete();

        return response()->noContent();
    }

    public function equity(Request $request, Account $account)
    {
        $this->authorize('view', $account);
        $window = $this->resolveAnalyticsWindow($request);

        $trades = Trade::query()
            ->where('account_id', $account->id)
            ->whereDate('date', '>=', $window['date_from'])
            ->whereDate('date', '<=', $window['date_to'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $equity = $this->equityEngine->build($trades, (float) $account->starting_balance);
        $basePayload = [
            'account_id' => (int) $account->id,
            'equity_points' => $equity['equity_points'],
            'equity_timestamps' => $equity['equity_timestamps'],
            'max_drawdown' => $equity['max_drawdown'],
            'peak_balance' => $equity['peak_balance'],
            'net_profit' => round((float) $equity['current_equity'] - (float) $account->starting_balance, 2),
            'window' => [
                'date_from' => $window['date_from'],
                'date_to' => $window['date_to'],
            ],
        ];

        if (!$request->has('cursor') && !$request->has('limit')) {
            return response()->json($basePayload);
        }

        $rows = [];
        $equityPoints = is_array($equity['equity_points'] ?? null) ? $equity['equity_points'] : [];
        $equityTimestamps = is_array($equity['equity_timestamps'] ?? null) ? $equity['equity_timestamps'] : [];
        foreach ($equityPoints as $index => $point) {
            $rows[] = [
                'index' => $index,
                'equity' => (float) $point,
                'timestamp' => (string) ($equityTimestamps[$index] ?? ''),
            ];
        }
        $page = $this->cursorPaginateRows($rows, $request);

        return response()->json([
            ...$basePayload,
            'series' => $page['items'],
            'pageInfo' => $page['pageInfo'],
        ]);
    }

    public function analytics(Request $request, Account $account)
    {
        $this->authorize('view', $account);
        $window = $this->resolveAnalyticsWindow($request);

        $trades = Trade::query()
            ->where('account_id', $account->id)
            ->whereDate('date', '>=', $window['date_from'])
            ->whereDate('date', '<=', $window['date_to'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $equity = $this->equityEngine->build($trades, (float) $account->starting_balance);
        $metrics = $this->metricsEngine->calculate($trades, (float) $equity['max_drawdown']);
        $streaks = $this->streakEngine->calculate($trades);

        $longestWin = (int) ($streaks['longest_win_streak'] ?? 0);
        $longestLoss = (int) ($streaks['longest_loss_streak'] ?? 0);
        $longestType = 'flat';
        $longestLength = 0;
        if ($longestWin > $longestLoss) {
            $longestType = 'win';
            $longestLength = $longestWin;
        } elseif ($longestLoss > 0) {
            $longestType = 'loss';
            $longestLength = $longestLoss;
        }

        return response()->json([
            'account_id' => (int) $account->id,
            'win_rate' => (float) ($metrics['win_rate'] ?? 0),
            'profit_factor' => $metrics['profit_factor'] !== null ? (float) $metrics['profit_factor'] : null,
            'expectancy' => (float) ($metrics['expectancy'] ?? 0),
            'max_drawdown' => (float) ($equity['max_drawdown'] ?? 0),
            'max_drawdown_percent' => (float) ($equity['max_drawdown_percent'] ?? 0),
            'recovery_factor' => $metrics['recovery_factor'] !== null ? (float) $metrics['recovery_factor'] : null,
            'average_r' => (float) ($metrics['average_r'] ?? 0),
            'longest_streak' => [
                'type' => $longestType,
                'length' => $longestLength,
            ],
            'longest_win_streak' => $longestWin,
            'longest_loss_streak' => $longestLoss,
            'total_trades' => (int) ($metrics['total_trades'] ?? 0),
            'net_profit' => (float) ($metrics['net_profit'] ?? 0),
            'window' => [
                'date_from' => $window['date_from'],
                'date_to' => $window['date_to'],
            ],
        ]);
    }

    public function riskPolicy(Account $account)
    {
        $this->authorize('view', $account);
        $policy = $this->tradeRiskPolicyService->getOrCreatePolicy((int) $account->id);

        return response()->json($policy);
    }

    /**
     * @throws ValidationException
     */
    public function upsertRiskPolicy(Request $request, Account $account)
    {
        $this->authorize('update', $account);
        $payload = $this->validateRiskPolicyPayload($request);
        $user = $request->user();
        $changingPolicyMode = array_key_exists('allow_override', $payload)
            || array_key_exists('enforce_hard_limits', $payload);

        if ($user->isTrader() && (($payload['allow_override'] ?? false) === true)) {
            return ApiErrorResponder::respond(
                request: $request,
                status: 403,
                code: 'forbidden_override_mode',
                message: 'Trader role cannot enable trade risk overrides.',
                details: [[
                    'field' => 'allow_override',
                    'message' => 'Trader role cannot enable trade risk overrides.',
                ]],
                legacyErrors: [
                    'allow_override' => ['Trader role cannot enable trade risk overrides.'],
                ]
            );
        }

        if ($changingPolicyMode && ! $user->canManageRiskPolicyMode()) {
            return ApiErrorResponder::respond(
                request: $request,
                status: 403,
                code: 'forbidden_policy_mode_change',
                message: 'Only admin/governance roles can change risk policy mode.'
            );
        }

        $policy = $this->tradeRiskPolicyService->getOrCreatePolicy((int) $account->id);
        $policy->fill($payload);
        $policy->save();

        return response()->json($policy->fresh());
    }

    public function challenge(Account $account)
    {
        $this->authorize('view', $account);
        $challenge = $this->propChallengeService->getOrCreateChallenge($account);

        return response()->json($challenge);
    }

    /**
     * @throws ValidationException
     */
    public function upsertChallenge(Request $request, Account $account)
    {
        $this->authorize('update', $account);
        $payload = $this->validateChallengePayload($request);
        $challenge = $this->propChallengeService->getOrCreateChallenge($account);
        $challenge->fill($payload);
        $challenge->save();

        return response()->json($challenge->fresh());
    }

    public function challengeStatus(Account $account)
    {
        $this->authorize('view', $account);
        return response()->json(
            $this->propChallengeService->status($account)
        );
    }

    /**
     * @throws ValidationException
     */
    private function validatePayload(Request $request, bool $isUpdate = false, ?int $ignoreId = null): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';
        $input = $request->except(['user_id']);
        $userId = (int) $request->user()->id;

        $validator = Validator::make($input, [
            'name' => [
                $required,
                'string',
                'max:120',
                Rule::unique('accounts', 'name')
                    ->where(fn (QueryBuilder $query) => $query->where('user_id', $userId))
                    ->ignore($ignoreId),
            ],
            'broker' => [$required, 'string', 'max:120'],
            'account_type' => [$required, Rule::in(['funded', 'personal', 'demo'])],
            'starting_balance' => [$required, 'numeric', 'gt:0'],
            'currency' => [$required, 'string', 'max:12'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $validator->validate();
    }

    /**
     * @throws ValidationException
     */
    private function validateRiskPolicyPayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'max_risk_per_trade_pct' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'max_daily_loss_pct' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'max_total_drawdown_pct' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'max_open_risk_pct' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'enforce_hard_limits' => ['sometimes', 'boolean'],
            'allow_override' => ['sometimes', 'boolean'],
        ]);

        return $validator->validate();
    }

    /**
     * @throws ValidationException
     */
    private function validateChallengePayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'provider' => ['sometimes', 'string', 'max:120'],
            'phase' => ['sometimes', 'string', 'max:80'],
            'starting_balance' => ['sometimes', 'numeric', 'gt:0'],
            'profit_target_pct' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'max_daily_loss_pct' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'max_total_drawdown_pct' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'min_trading_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'start_date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'passed', 'failed', 'paused'])],
            'passed_at' => ['sometimes', 'nullable', 'date'],
            'failed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $status = (string) $request->input('status', '');
            $passedAt = $request->input('passed_at');
            $failedAt = $request->input('failed_at');

            if ($status === 'passed' && $passedAt === null) {
                $validator->errors()->add('passed_at', 'passed_at is required when status is passed.');
            }
            if ($status === 'failed' && $failedAt === null) {
                $validator->errors()->add('failed_at', 'failed_at is required when status is failed.');
            }
        });

        return $validator->validate();
    }

    /**
     * @return array{date_from:string,date_to:string}
     */
    private function resolveAnalyticsWindow(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_CURSOR_PAGE_SIZE],
            'cursor' => ['sometimes', 'string'],
        ]);
        $validated = $validator->validate();

        $today = CarbonImmutable::now()->endOfDay();
        $fromRaw = $validated['date_from'] ?? null;
        $toRaw = $validated['date_to'] ?? null;

        $from = $fromRaw !== null
            ? CarbonImmutable::parse((string) $fromRaw)->startOfDay()
            : null;
        $to = $toRaw !== null
            ? CarbonImmutable::parse((string) $toRaw)->endOfDay()
            : null;

        if ($from === null && $to === null) {
            $to = $today;
            $from = $to->subDays(self::DEFAULT_ANALYTICS_WINDOW_DAYS - 1)->startOfDay();
        } elseif ($from !== null && $to === null) {
            $to = $today->greaterThan($from) ? $today : $from->copy()->endOfDay();
        } elseif ($from === null && $to !== null) {
            $from = $to->subDays(self::DEFAULT_ANALYTICS_WINDOW_DAYS - 1)->startOfDay();
        }

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'date_range' => ['date_from must be earlier than or equal to date_to.'],
            ]);
        }

        $windowDays = $from->diffInDays($to) + 1;
        if ($windowDays > self::MAX_ANALYTICS_WINDOW_DAYS) {
            throw ValidationException::withMessages([
                'date_range' => [sprintf(
                    'Date window exceeds max of %d days.',
                    self::MAX_ANALYTICS_WINDOW_DAYS
                )],
            ]);
        }

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{
     *   items:array<int,array<string,mixed>>,
     *   pageInfo:array{limit:int,nextCursor:?string,hasMore:bool}
     * }
     */
    private function cursorPaginateRows(array $rows, Request $request): array
    {
        $limit = max(1, min(
            self::MAX_CURSOR_PAGE_SIZE,
            (int) $request->integer('limit', self::DEFAULT_CURSOR_PAGE_SIZE)
        ));

        $cursorValue = trim((string) $request->query('cursor', ''));
        $offset = 0;
        if ($cursorValue !== '' && ctype_digit($cursorValue)) {
            $offset = max(0, (int) $cursorValue);
        }

        $slice = array_slice($rows, $offset, $limit + 1);
        $hasMore = count($slice) > $limit;
        if ($hasMore) {
            array_pop($slice);
        }

        return [
            'items' => array_values($slice),
            'pageInfo' => [
                'limit' => $limit,
                'nextCursor' => $hasMore ? (string) ($offset + $limit) : null,
                'hasMore' => $hasMore,
            ],
        ];
    }
}
