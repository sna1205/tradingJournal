<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

class EquityEngine
{
    /**
     * @param Collection<int, object> $trades
     * @return array{
     *   starting_balance:float,
     *   equity_points:array<int, float>,
     *   cumulative_profit:array<int, float>,
     *   equity_timestamps:array<int, string>,
     *   peak_balance:float,
     *   max_drawdown:float,
     *   max_drawdown_percent:float,
     *   current_drawdown:float,
     *   current_drawdown_percent:float,
     *   current_equity:float
     * }
     */
    public function build(Collection $trades, float $startingBalance): array
    {
        $runningBalance = $startingBalance;
        $peak = $startingBalance;
        $maxDrawdown = 0.0;

        $equityPoints = [];
        $cumulativeProfit = [];
        $equityTimestamps = [];

        foreach ($trades as $trade) {
            $profitLoss = (float) ($trade->profit_loss ?? 0);
            $runningBalance += $profitLoss;

            if ($runningBalance > $peak) {
                $peak = $runningBalance;
            }

            $drawdown = $peak - $runningBalance;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }

            $equityPoints[] = round($runningBalance, 2);
            $cumulativeProfit[] = round($runningBalance - $startingBalance, 2);
            $equityTimestamps[] = (string) data_get($trade, 'date');
        }

        $currentDrawdown = max(0, $peak - $runningBalance);
        $maxDrawdownPercent = $peak > 0
            ? ($maxDrawdown / $peak) * 100
            : 0.0;
        $currentDrawdownPercent = $peak > 0
            ? ($currentDrawdown / $peak) * 100
            : 0.0;

        return [
            'starting_balance' => round($startingBalance, 2),
            'equity_points' => $equityPoints,
            'cumulative_profit' => $cumulativeProfit,
            'equity_timestamps' => $equityTimestamps,
            'peak_balance' => round($peak, 2),
            'max_drawdown' => round($maxDrawdown, 2),
            'max_drawdown_percent' => round($maxDrawdownPercent, 4),
            'current_drawdown' => round($currentDrawdown, 2),
            'current_drawdown_percent' => round($currentDrawdownPercent, 4),
            'current_equity' => round($runningBalance, 2),
        ];
    }
}

