<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Trade;
use App\Services\Analytics\BehavioralAnalyticsEngine;
use App\Services\Analytics\EquityEngine;
use App\Services\Analytics\StreakEngine;
use App\Services\Analytics\TradeMetricsEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly EquityEngine $equityEngine,
        private readonly StreakEngine $streakEngine,
        private readonly TradeMetricsEngine $metricsEngine,
        private readonly BehavioralAnalyticsEngine $behavioralAnalyticsEngine
    ) {
    }

    public function overview(Request $request)
    {
        $payload = $this->remember('overview', $request, function () use ($request): array {
            $query = $this->baseQuery($request);
            $trades = $this->chronologicalTrades($request);
            $startingBalance = $this->startingBalance($request);

            $equity = $this->equityEngine->build($trades, $startingBalance);
            $metrics = $this->metricsEngine->calculate($trades, (float) $equity['max_drawdown']);
            $notional = (float) ((clone $query)
                ->selectRaw('SUM(entry_price * lot_size) as total_notional')
                ->value('total_notional') ?? 0);

            $returnsPercent = $notional > 0
                ? ((float) $metrics['net_profit'] / $notional) * 100
                : 0.0;

            return [
                'total_trades' => $metrics['total_trades'],
                'win_rate' => $metrics['win_rate'],
                'total_profit' => $metrics['total_winning_amount'],
                'total_loss' => round(abs((float) $metrics['total_losing_amount']), 2),
                'profit_factor' => $metrics['profit_factor'],
                'returns_percent' => round($returnsPercent, 2),
                'expectancy' => $metrics['expectancy'],
                'average_r' => $metrics['average_r'],
                'recovery_factor' => $metrics['recovery_factor'],
            ];
        });

        return response()->json($payload);
    }

    public function daily(Request $request)
    {
        $payload = $this->remember('daily', $request, function () use ($request): array {
            return $this->baseQuery($request)
                ->selectRaw('DATE(`date`) as close_date, COUNT(*) as total_trades, ROUND(SUM(profit_loss), 2) as profit_loss, ROUND(AVG(COALESCE(r_multiple, rr)), 4) as average_r, SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) as wins')
                ->groupByRaw('DATE(`date`)')
                ->orderByRaw('DATE(`date`)')
                ->get()
                ->map(fn ($row) => [
                    'date' => (string) $row->close_date,
                    'close_date' => (string) $row->close_date,
                    'total_trades' => (int) $row->total_trades,
                    'profit_loss' => round((float) $row->profit_loss, 2),
                    'average_r' => round((float) ($row->average_r ?? 0), 4),
                    'win_rate' => (int) $row->total_trades > 0
                        ? round(((int) $row->wins / (int) $row->total_trades) * 100, 2)
                        : 0.0,
                ])
                ->values()
                ->all();
        });

        return response()->json($payload);
    }

    public function performanceProfile(Request $request)
    {
        $payload = $this->remember('performance-profile', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);
            $startingBalance = $this->startingBalance($request);

            $equity = $this->equityEngine->build($trades, $startingBalance);
            $metrics = $this->metricsEngine->calculate($trades, (float) $equity['max_drawdown']);

            $dailySeries = $this->dailyPnlSeries($request);
            $consistencyScore = $this->calculateConsistencyScore($dailySeries);

            return [
                'win_rate' => $metrics['win_rate'],
                'avg_rr' => $metrics['average_r'],
                'profit_factor' => $metrics['profit_factor'],
                'consistency_score' => $consistencyScore,
                'recovery_factor' => $metrics['recovery_factor'],
                'sharpe_ratio' => $metrics['sharpe_ratio'],
            ];
        });

        return response()->json($payload);
    }

    public function equity(Request $request)
    {
        $payload = $this->remember('equity', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);
            $startingBalance = $this->startingBalance($request);
            $equity = $this->equityEngine->build($trades, $startingBalance);

            return [
                'equity_points' => $equity['equity_points'],
                'cumulative_profit' => $equity['cumulative_profit'],
                'equity_timestamps' => $equity['equity_timestamps'],
            ];
        });

        return response()->json($payload);
    }

    public function drawdown(Request $request)
    {
        $payload = $this->remember('drawdown', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);
            $startingBalance = $this->startingBalance($request);
            $equity = $this->equityEngine->build($trades, $startingBalance);

            return [
                'max_drawdown' => $equity['max_drawdown'],
                'max_drawdown_percent' => $equity['max_drawdown_percent'],
                'current_drawdown' => $equity['current_drawdown'],
                'current_drawdown_percent' => $equity['current_drawdown_percent'],
                'peak_balance' => $equity['peak_balance'],
                'current_equity' => $equity['current_equity'],
            ];
        });

        return response()->json($payload);
    }

    public function streaks(Request $request)
    {
        $payload = $this->remember('streaks', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);

            return $this->streakEngine->calculate($trades);
        });

        return response()->json($payload);
    }

    public function metrics(Request $request)
    {
        $payload = $this->remember('metrics', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);
            $startingBalance = $this->startingBalance($request);
            $equity = $this->equityEngine->build($trades, $startingBalance);

            return $this->metricsEngine->calculate($trades, (float) $equity['max_drawdown']);
        });

        return response()->json($payload);
    }

    public function behavioral(Request $request)
    {
        $payload = $this->remember('behavioral', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);
            $startingBalance = $this->startingBalance($request);

            return $this->behavioralAnalyticsEngine->calculate($trades, $startingBalance);
        });

        return response()->json($payload);
    }

    public function rankings(Request $request)
    {
        $payload = $this->remember('rankings', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);

            return [
                'sessions' => $this->groupMetricsBy($trades, 'session', 'session'),
                'strategy_models' => $this->groupMetricsBy($trades, 'model', 'strategy_model'),
                'symbols' => $this->groupMetricsBy($trades, 'pair', 'symbol'),
            ];
        });

        return response()->json($payload);
    }

    public function monthlyHeatmap(Request $request)
    {
        $payload = $this->remember('monthly-heatmap', $request, function () use ($request): array {
            $rows = $this->baseQuery($request)
                ->selectRaw('DATE(`date`) as close_date, COUNT(*) as number_of_trades, ROUND(SUM(profit_loss), 2) as total_profit, ROUND(AVG(COALESCE(r_multiple, rr)), 4) as average_r, SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) as wins')
                ->groupByRaw('DATE(`date`)')
                ->orderByRaw('DATE(`date`)')
                ->get()
                ->map(function ($row): array {
                    $trades = (int) $row->number_of_trades;
                    $wins = (int) $row->wins;
                    $winRate = $trades > 0
                        ? ($wins / $trades) * 100
                        : 0.0;

                    return [
                        'close_date' => (string) $row->close_date,
                        'number_of_trades' => $trades,
                        'total_profit' => round((float) $row->total_profit, 2),
                        'average_r' => round((float) ($row->average_r ?? 0), 4),
                        'win_rate' => round($winRate, 2),
                    ];
                })
                ->values();

            $maxAbsDailyPnl = (float) $rows
                ->map(fn (array $row): float => abs((float) $row['total_profit']))
                ->max();

            $rows = $rows->map(function (array $row) use ($maxAbsDailyPnl): array {
                $intensity = $maxAbsDailyPnl > 0
                    ? abs((float) $row['total_profit']) / $maxAbsDailyPnl
                    : 0.0;
                $row['intensity'] = round($intensity, 4);
                return $row;
            });

            $months = $rows
                ->groupBy(fn (array $row): string => substr($row['close_date'], 0, 7))
                ->map(function (Collection $days, string $month): array {
                    [$year, $monthNumber] = explode('-', $month);
                    $label = now()->setDate((int) $year, (int) $monthNumber, 1)->format('F Y');

                    return [
                        'month' => $month,
                        'label' => $label,
                        'days' => $days->values()->all(),
                    ];
                })
                ->values()
                ->all();

            return [
                'months' => $months,
                'max_abs_daily_pnl' => round($maxAbsDailyPnl, 2),
            ];
        });

        return response()->json($payload);
    }

    public function riskStatus(Request $request)
    {
        $payload = $this->remember('risk-status', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);
            $startingBalance = $this->startingBalance($request);

            $equity = $this->equityEngine->build($trades, $startingBalance);
            $streaks = $this->streakEngine->calculate($trades);

            $latestTrade = $trades->last();
            $latestRiskPercent = (float) (data_get($latestTrade, 'risk_percent') ?? 0);
            $maxRiskPercent = (float) $trades
                ->map(fn ($trade): float => (float) (data_get($trade, 'risk_percent') ?? 0))
                ->max();

            $revengeAfterLoss = $this->findRevengeAfterLossFlags($trades);

            $warnings = [];
            if ($latestRiskPercent > 3 || $maxRiskPercent > 3) {
                $warnings[] = 'Risk percent exceeded 3%';
            }
            if ((int) $streaks['current_loss_streak'] >= 3) {
                $warnings[] = 'Three consecutive losses detected';
            }
            if ((float) $equity['current_drawdown_percent'] > 10) {
                $warnings[] = 'Current drawdown above 10%';
            }
            if (count($revengeAfterLoss) > 0) {
                $warnings[] = 'Revenge emotion detected after a losing trade';
            }

            return [
                'risk_percent_warning' => $latestRiskPercent > 3 || $maxRiskPercent > 3,
                'loss_streak_caution' => (int) $streaks['current_loss_streak'] >= 3,
                'drawdown_banner' => (float) $equity['current_drawdown_percent'] > 10,
                'revenge_behavior_flag' => count($revengeAfterLoss) > 0,
                'latest_risk_percent' => round($latestRiskPercent, 4),
                'max_risk_percent' => round($maxRiskPercent, 4),
                'current_loss_streak' => (int) $streaks['current_loss_streak'],
                'current_drawdown_percent' => round((float) $equity['current_drawdown_percent'], 4),
                'revenge_after_loss_events' => $revengeAfterLoss,
                'warnings' => $warnings,
            ];
        });

        return response()->json($payload);
    }

    public function accounts(Request $request)
    {
        $payload = $this->remember('accounts', $request, function () use ($request): array {
            $requestedAccountIds = $this->requestedAccountIds($request);

            $accountQuery = Account::query()->orderByDesc('is_active')->orderBy('name');
            if (count($requestedAccountIds) > 0) {
                $accountQuery->whereIn('id', $requestedAccountIds);
            }
            if ($request->has('is_active')) {
                $accountQuery->where('is_active', $request->boolean('is_active'));
            }

            $accounts = $accountQuery->get();
            $rows = $accounts->map(function (Account $account) use ($request): array {
                $trades = $this->baseQuery($request)
                    ->where('account_id', $account->id)
                    ->orderBy('date')
                    ->orderBy('id')
                    ->get();

                $equity = $this->equityEngine->build($trades, (float) $account->starting_balance);
                $metrics = $this->metricsEngine->calculate($trades, (float) $equity['max_drawdown']);

                return [
                    'account_id' => (int) $account->id,
                    'name' => (string) $account->name,
                    'broker' => (string) $account->broker,
                    'account_type' => (string) $account->account_type,
                    'currency' => (string) $account->currency,
                    'is_active' => (bool) $account->is_active,
                    'starting_balance' => (float) $account->starting_balance,
                    'current_balance' => (float) $account->current_balance,
                    'computed_current_balance' => (float) $equity['current_equity'],
                    'total_trades' => (int) $metrics['total_trades'],
                    'win_rate' => (float) $metrics['win_rate'],
                    'net_profit' => (float) $metrics['net_profit'],
                    'profit_factor' => (float) $metrics['profit_factor'],
                    'expectancy' => (float) $metrics['expectancy'],
                    'max_drawdown' => (float) $equity['max_drawdown'],
                    'max_drawdown_percent' => (float) $equity['max_drawdown_percent'],
                ];
            })->values()->all();

            return [
                'accounts' => $rows,
                'portfolio_starting_balance' => $accounts->sum(fn (Account $account): float => (float) $account->starting_balance),
                'portfolio_current_balance' => $accounts->sum(fn (Account $account): float => (float) $account->current_balance),
            ];
        });

        return response()->json($payload);
    }

    public function portfolio(Request $request)
    {
        $payload = $this->remember('portfolio', $request, function () use ($request): array {
            $trades = $this->chronologicalTrades($request);
            $startingBalance = $this->startingBalance($request);
            $equity = $this->equityEngine->build($trades, $startingBalance);
            $metrics = $this->metricsEngine->calculate($trades, (float) $equity['max_drawdown']);

            return [
                'scope' => 'portfolio',
                'starting_balance' => round($startingBalance, 2),
                'current_equity' => (float) $equity['current_equity'],
                'net_profit' => (float) $metrics['net_profit'],
                'win_rate' => (float) $metrics['win_rate'],
                'profit_factor' => (float) $metrics['profit_factor'],
                'expectancy' => (float) $metrics['expectancy'],
                'max_drawdown' => (float) $equity['max_drawdown'],
                'max_drawdown_percent' => (float) $equity['max_drawdown_percent'],
                'equity_points' => $equity['equity_points'],
                'equity_timestamps' => $equity['equity_timestamps'],
                'cumulative_profit' => $equity['cumulative_profit'],
            ];
        });

        return response()->json($payload);
    }

    public function portfolioAnalytics(Request $request)
    {
        $payload = $this->remember('portfolio-analytics', $request, function () use ($request): array {
            $requestedAccountIds = $this->requestedAccountIds($request);
            $accountsQuery = Account::query()
                ->orderByDesc('is_active')
                ->orderBy('name');
            if (count($requestedAccountIds) > 0) {
                $accountsQuery->whereIn('id', $requestedAccountIds);
            }

            $accounts = $accountsQuery->get();
            if ($accounts->isEmpty()) {
                return [
                    'portfolio_equity' => [
                        'starting_balance' => 0.0,
                        'current_equity' => 0.0,
                        'net_profit' => 0.0,
                        'equity_points' => [],
                        'equity_timestamps' => [],
                    ],
                    'portfolio_drawdown' => [
                        'max_drawdown' => 0.0,
                        'max_drawdown_percent' => 0.0,
                        'current_drawdown' => 0.0,
                        'current_drawdown_percent' => 0.0,
                        'peak_balance' => 0.0,
                    ],
                    'total_trades' => 0,
                    'win_rate' => 0.0,
                    'account_breakdown' => [],
                ];
            }

            $accountIds = $accounts->pluck('id')->all();
            $trades = $this->baseQuery($request)
                ->whereIn('account_id', $accountIds)
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            $startingBalance = (float) $accounts->sum(fn (Account $account): float => (float) $account->starting_balance);
            $totalTrades = $trades->count();
            $wins = $trades->filter(fn (Trade $trade): bool => (float) $trade->profit_loss > 0)->count();
            $winRate = $totalTrades > 0 ? round(($wins / $totalTrades) * 100, 2) : 0.0;

            $portfolioCurve = $this->buildPortfolioEquityCurve($accounts, $trades);
            $accountBreakdown = $accounts
                ->map(function (Account $account) use ($trades): array {
                    $rows = $trades
                        ->where('account_id', $account->id)
                        ->values();

                    $equity = $this->equityEngine->build($rows, (float) $account->starting_balance);
                    $metrics = $this->metricsEngine->calculate($rows, (float) $equity['max_drawdown']);

                    return [
                        'account_id' => (int) $account->id,
                        'name' => (string) $account->name,
                        'broker' => (string) $account->broker,
                        'account_type' => (string) $account->account_type,
                        'currency' => (string) $account->currency,
                        'starting_balance' => (float) $account->starting_balance,
                        'current_balance' => (float) $account->current_balance,
                        'net_profit' => (float) $metrics['net_profit'],
                        'total_trades' => (int) $metrics['total_trades'],
                        'win_rate' => (float) $metrics['win_rate'],
                        'max_drawdown' => (float) $equity['max_drawdown'],
                        'max_drawdown_percent' => (float) $equity['max_drawdown_percent'],
                    ];
                })
                ->values()
                ->all();

            return [
                'portfolio_equity' => [
                    'starting_balance' => round($startingBalance, 2),
                    'current_equity' => $portfolioCurve['current_equity'],
                    'net_profit' => $portfolioCurve['net_profit'],
                    'equity_points' => $portfolioCurve['equity_points'],
                    'equity_timestamps' => $portfolioCurve['equity_timestamps'],
                ],
                'portfolio_drawdown' => [
                    'max_drawdown' => $portfolioCurve['max_drawdown'],
                    'max_drawdown_percent' => $portfolioCurve['max_drawdown_percent'],
                    'current_drawdown' => $portfolioCurve['current_drawdown'],
                    'current_drawdown_percent' => $portfolioCurve['current_drawdown_percent'],
                    'peak_balance' => $portfolioCurve['peak_balance'],
                ],
                'total_trades' => $totalTrades,
                'win_rate' => $winRate,
                'account_breakdown' => $accountBreakdown,
            ];
        });

        return response()->json($payload);
    }

    private function baseQuery(Request $request): Builder
    {
        $filters = $request->only([
            'account_id',
            'account_ids',
            'pair',
            'symbol',
            'direction',
            'session',
            'model',
            'strategy_model',
            'emotion',
            'followed_rules',
            'date_from',
            'date_to',
            'close_date_from',
            'close_date_to',
        ]);

        if (!($filters['pair'] ?? null) && ($filters['symbol'] ?? null)) {
            $filters['pair'] = $filters['symbol'];
        }
        if (!($filters['model'] ?? null) && ($filters['strategy_model'] ?? null)) {
            $filters['model'] = $filters['strategy_model'];
        }
        if (!($filters['date_from'] ?? null) && ($filters['close_date_from'] ?? null)) {
            $filters['date_from'] = $filters['close_date_from'];
        }
        if (!($filters['date_to'] ?? null) && ($filters['close_date_to'] ?? null)) {
            $filters['date_to'] = $filters['close_date_to'];
        }

        if (is_string($filters['account_ids'] ?? null)) {
            $filters['account_ids'] = array_filter(
                array_map('trim', explode(',', $filters['account_ids'])),
                fn (string $value): bool => $value !== ''
            );
        }

        return Trade::query()->applyFilters($filters);
    }

    /**
     * @return Collection<int, Trade>
     */
    private function chronologicalTrades(Request $request): Collection
    {
        return $this->baseQuery($request)
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function dailyPnlSeries(Request $request): Collection
    {
        return $this->baseQuery($request)
            ->selectRaw('DATE(`date`) as trade_date, SUM(profit_loss) as pnl')
            ->groupByRaw('DATE(`date`)')
            ->orderByRaw('DATE(`date`)')
            ->get();
    }

    private function calculateConsistencyScore(Collection $dailySeries): float
    {
        $totalDays = $dailySeries->count();
        if ($totalDays === 0) {
            return 0.0;
        }

        $positiveDays = $dailySeries->where('pnl', '>', 0)->count();

        return round(($positiveDays / $totalDays) * 100, 2);
    }

    private function startingBalance(Request $request): float
    {
        if ($request->filled('starting_balance')) {
            $requested = (float) $request->input('starting_balance');

            return $requested > 0 ? $requested : 10_000.0;
        }

        $accountId = (int) $request->integer('account_id', 0);
        if ($accountId > 0) {
            $accountStarting = (float) (Account::query()->whereKey($accountId)->value('starting_balance') ?? 0);

            return $accountStarting > 0
                ? $accountStarting
                : (float) env('ANALYTICS_STARTING_BALANCE', 10_000);
        }

        $requestedAccountIds = $this->requestedAccountIds($request);
        $query = Account::query();
        if (count($requestedAccountIds) > 0) {
            $query->whereIn('id', $requestedAccountIds);
        } else {
            $query->where('is_active', true);
        }

        $portfolioStarting = (float) $query->sum('starting_balance');

        return $portfolioStarting > 0
            ? $portfolioStarting
            : (float) env('ANALYTICS_STARTING_BALANCE', 10_000);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupMetricsBy(Collection $trades, string $key, string $labelKey): array
    {
        return $trades
            ->groupBy(fn ($trade): string => (string) (data_get($trade, $key) ?: 'Unknown'))
            ->map(function (Collection $rows, string $value) use ($labelKey): array {
                $metrics = $this->metricsEngine->calculate($rows->values());

                return [
                    $labelKey => $value,
                    'total_trades' => (int) $metrics['total_trades'],
                    'win_rate' => (float) $metrics['win_rate'],
                    'profit_factor' => $metrics['profit_factor'],
                    'expectancy' => (float) $metrics['expectancy'],
                    'total_pnl' => (float) $metrics['net_profit'],
                    'avg_r' => (float) $metrics['avg_r'],
                ];
            })
            ->sortByDesc('expectancy')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findRevengeAfterLossFlags(Collection $trades): array
    {
        $flags = [];
        $values = $trades->values();
        for ($i = 1; $i < $values->count(); $i++) {
            $current = $values[$i];
            $previous = $values[$i - 1];

            $currentEmotion = (string) (data_get($current, 'emotion') ?? '');
            $previousPnl = (float) (data_get($previous, 'profit_loss') ?? 0);
            if ($previousPnl < 0 && strtolower($currentEmotion) === 'revenge') {
                $flags[] = [
                    'trade_id' => data_get($current, 'id'),
                    'date' => data_get($current, 'date'),
                    'previous_trade_id' => data_get($previous, 'id'),
                ];
            }
        }

        return $flags;
    }

    /**
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    private function remember(string $namespace, Request $request, callable $callback, int $ttlSeconds = 90)
    {
        $version = (int) Cache::get('analytics:version', 1);
        $queryHash = md5((string) json_encode($request->query()));
        $cacheKey = "analytics:{$namespace}:v{$version}:{$queryHash}";

        return Cache::remember($cacheKey, $ttlSeconds, $callback);
    }

    /**
     * @return array<int, int>
     */
    private function requestedAccountIds(Request $request): array
    {
        $raw = $request->input('account_ids');
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Account> $accounts
     * @param Collection<int, Trade> $trades
     * @return array{
     *   equity_points:array<int, float>,
     *   equity_timestamps:array<int, string>,
     *   current_equity:float,
     *   net_profit:float,
     *   peak_balance:float,
     *   max_drawdown:float,
     *   max_drawdown_percent:float,
     *   current_drawdown:float,
     *   current_drawdown_percent:float
     * }
     */
    private function buildPortfolioEquityCurve(Collection $accounts, Collection $trades): array
    {
        $balances = [];
        foreach ($accounts as $account) {
            $balances[(int) $account->id] = (float) $account->starting_balance;
        }

        $starting = (float) array_sum($balances);
        $running = $starting;
        $peak = $starting;
        $maxDrawdown = 0.0;
        $equityPoints = [];
        $equityTimestamps = [];

        foreach ($trades as $trade) {
            $accountId = (int) $trade->account_id;
            $profitLoss = (float) $trade->profit_loss;
            $balances[$accountId] = (float) ($balances[$accountId] ?? 0) + $profitLoss;
            $running = (float) array_sum($balances);

            if ($running > $peak) {
                $peak = $running;
            }

            $drawdown = $peak - $running;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }

            $equityPoints[] = round($running, 2);
            $equityTimestamps[] = (string) $trade->date;
        }

        $currentDrawdown = max(0, $peak - $running);
        $maxDrawdownPercent = $peak > 0 ? ($maxDrawdown / $peak) * 100 : 0.0;
        $currentDrawdownPercent = $peak > 0 ? ($currentDrawdown / $peak) * 100 : 0.0;

        return [
            'equity_points' => $equityPoints,
            'equity_timestamps' => $equityTimestamps,
            'current_equity' => round($running, 2),
            'net_profit' => round($running - $starting, 2),
            'peak_balance' => round($peak, 2),
            'max_drawdown' => round($maxDrawdown, 2),
            'max_drawdown_percent' => round($maxDrawdownPercent, 4),
            'current_drawdown' => round($currentDrawdown, 2),
            'current_drawdown_percent' => round($currentDrawdownPercent, 4),
        ];
    }
}
