<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

class TradeMetricsEngine
{
    /**
     * @param Collection<int, object> $trades
     * @return array{
     *   total_trades:int,
     *   wins:int,
     *   losses:int,
     *   breakeven:int,
     *   win_rate:float,
     *   loss_rate:float,
     *   average_win:float,
     *   average_loss:float,
     *   total_winning_amount:float,
     *   total_losing_amount:float,
     *   net_profit:float,
     *   profit_factor:float|null,
     *   expectancy:float,
     *   recovery_factor:float|null,
     *   average_r:float,
     *   avg_r:float,
     *   sharpe_ratio:float|null
     * }
     */
    public function calculate(Collection $trades, ?float $maxDrawdown = null): array
    {
        $totalTrades = $trades->count();
        $wins = $trades->filter(fn ($trade) => (float) ($trade->profit_loss ?? 0) > 0)->count();
        $losses = $trades->filter(fn ($trade) => (float) ($trade->profit_loss ?? 0) < 0)->count();
        $breakeven = max(0, $totalTrades - $wins - $losses);

        $totalWinningAmount = (float) $trades
            ->filter(fn ($trade) => (float) ($trade->profit_loss ?? 0) > 0)
            ->sum('profit_loss');
        $totalLosingAmount = (float) $trades
            ->filter(fn ($trade) => (float) ($trade->profit_loss ?? 0) < 0)
            ->sum('profit_loss');
        $netProfit = (float) $trades->sum('profit_loss');

        $winRate = $totalTrades > 0
            ? $wins / $totalTrades
            : 0.0;
        $lossRate = $totalTrades > 0
            ? $losses / $totalTrades
            : 0.0;

        $averageWin = $wins > 0
            ? $totalWinningAmount / $wins
            : 0.0;
        $averageLoss = $losses > 0
            ? abs($totalLosingAmount) / $losses
            : 0.0;

        $profitFactor = abs($totalLosingAmount) > 0
            ? $totalWinningAmount / abs($totalLosingAmount)
            : null;
        $expectancy = ($winRate * $averageWin) - ($lossRate * $averageLoss);

        $effectiveMaxDrawdown = $maxDrawdown ?? 0.0;
        $recoveryFactor = $effectiveMaxDrawdown > 0
            ? $netProfit / $effectiveMaxDrawdown
            : null;

        $rValues = $trades
            ->map(function ($trade): float {
                $candidate = data_get($trade, 'r_multiple');
                if ($candidate !== null) {
                    return (float) $candidate;
                }

                return (float) (data_get($trade, 'rr') ?? 0);
            });
        $averageR = $rValues->count() > 0
            ? ((float) $rValues->sum()) / $rValues->count()
            : 0.0;

        $returns = $trades
            ->map(function ($trade): ?float {
                $balanceBefore = (float) (data_get($trade, 'account_balance_before_trade') ?? 0);
                if ($balanceBefore <= 0) {
                    return null;
                }

                return ((float) (data_get($trade, 'profit_loss') ?? 0)) / $balanceBefore;
            })
            ->filter(fn ($value) => $value !== null)
            ->values();

        $sharpeRatio = $this->calculateSharpeRatio($returns);

        return [
            'total_trades' => $totalTrades,
            'wins' => $wins,
            'losses' => $losses,
            'breakeven' => $breakeven,
            'win_rate' => round($winRate * 100, 2),
            'loss_rate' => round($lossRate * 100, 2),
            'average_win' => round($averageWin, 2),
            'average_loss' => round($averageLoss, 2),
            'total_winning_amount' => round($totalWinningAmount, 2),
            'total_losing_amount' => round($totalLosingAmount, 2),
            'net_profit' => round($netProfit, 2),
            'profit_factor' => $profitFactor === null ? null : round($profitFactor, 4),
            'expectancy' => round($expectancy, 4),
            'recovery_factor' => $recoveryFactor === null ? null : round($recoveryFactor, 4),
            'average_r' => round($averageR, 4),
            'avg_r' => round($averageR, 4),
            'sharpe_ratio' => $sharpeRatio === null ? null : round($sharpeRatio, 4),
        ];
    }

    /**
     * @param Collection<int, float|null> $returns
     */
    private function calculateSharpeRatio(Collection $returns): ?float
    {
        $count = $returns->count();
        if ($count < 2) {
            return null;
        }

        $mean = ((float) $returns->sum()) / $count;

        $variance = $returns
            ->map(fn (float $value): float => ($value - $mean) ** 2)
            ->sum() / $count;

        $stdDeviation = sqrt($variance);
        if ($stdDeviation <= 0) {
            return null;
        }

        return $mean / $stdDeviation;
    }
}

