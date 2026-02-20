<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    public function overview(Request $request)
    {
        $query = $this->baseQuery($request);

        $totalTrades = (clone $query)->count();
        $winningTrades = (clone $query)->where('profit_loss', '>', 0)->count();
        $totalProfit = (float) ((clone $query)->where('profit_loss', '>', 0)->sum('profit_loss') ?? 0);
        $totalLoss = abs((float) ((clone $query)->where('profit_loss', '<', 0)->sum('profit_loss') ?? 0));
        $netProfit = (float) ((clone $query)->sum('profit_loss') ?? 0);
        $notional = (float) ((clone $query)->selectRaw('SUM(entry_price * lot_size) as total_notional')->value('total_notional') ?? 0);

        $winRate = $totalTrades > 0
            ? round(($winningTrades / $totalTrades) * 100, 2)
            : 0.0;
        $profitFactor = $totalLoss > 0
            ? round($totalProfit / $totalLoss, 2)
            : null;
        $returnsPercent = $notional > 0
            ? round(($netProfit / $notional) * 100, 2)
            : 0.0;

        return response()->json([
            'total_trades' => $totalTrades,
            'win_rate' => $winRate,
            'total_profit' => round($totalProfit, 2),
            'total_loss' => round($totalLoss, 2),
            'profit_factor' => $profitFactor,
            'returns_percent' => $returnsPercent,
        ]);
    }

    public function daily(Request $request)
    {
        $daily = $this->baseQuery($request)
            ->selectRaw('DATE(`date`) as date, COUNT(*) as total_trades, ROUND(SUM(profit_loss), 2) as profit_loss')
            ->groupByRaw('DATE(`date`)')
            ->orderByRaw('DATE(`date`)')
            ->get();

        return response()->json($daily);
    }

    public function performanceProfile(Request $request)
    {
        $query = $this->baseQuery($request);

        $totalTrades = (clone $query)->count();
        $winningTrades = (clone $query)->where('profit_loss', '>', 0)->count();
        $totalProfit = (float) ((clone $query)->where('profit_loss', '>', 0)->sum('profit_loss') ?? 0);
        $totalLoss = abs((float) ((clone $query)->where('profit_loss', '<', 0)->sum('profit_loss') ?? 0));
        $netProfit = (float) ((clone $query)->sum('profit_loss') ?? 0);
        $avgRr = (float) ((clone $query)->avg('rr') ?? 0);

        $winRate = $totalTrades > 0
            ? round(($winningTrades / $totalTrades) * 100, 2)
            : 0.0;
        $profitFactor = $totalLoss > 0
            ? round($totalProfit / $totalLoss, 2)
            : null;

        $dailySeries = $this->dailyPnlSeries($query);
        $consistencyScore = $this->calculateConsistencyScore($dailySeries);
        $maxDrawdown = $this->calculateMaxDrawdown($dailySeries);
        $recoveryFactor = $maxDrawdown > 0
            ? round($netProfit / $maxDrawdown, 2)
            : null;

        return response()->json([
            'win_rate' => $winRate,
            'avg_rr' => round($avgRr, 2),
            'profit_factor' => $profitFactor,
            'consistency_score' => $consistencyScore,
            'recovery_factor' => $recoveryFactor,
        ]);
    }

    private function baseQuery(Request $request): Builder
    {
        return Trade::query()
            ->applyFilters($request->only([
                'pair',
                'direction',
                'session',
                'model',
                'date_from',
                'date_to',
            ]));
    }

    private function dailyPnlSeries(Builder $query): Collection
    {
        return (clone $query)
            ->selectRaw('DATE(`date`) as date, SUM(profit_loss) as pnl')
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

    private function calculateMaxDrawdown(Collection $dailySeries): float
    {
        if ($dailySeries->isEmpty()) {
            return 0.0;
        }

        $equity = 0.0;
        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($dailySeries as $day) {
            $equity += (float) $day->pnl;
            $peak = max($peak, $equity);
            $drawdown = $peak - $equity;
            $maxDrawdown = max($maxDrawdown, $drawdown);
        }

        return round($maxDrawdown, 2);
    }
}
