<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PropChallenge;
use App\Models\Trade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PropChallengeService
{
    public function getOrCreateChallenge(Account $account): PropChallenge
    {
        return PropChallenge::query()->firstOrCreate(
            ['account_id' => (int) $account->id],
            $this->defaultChallengePayload($account)
        );
    }

    /**
     * @return array{
     *   account_id:int,
     *   challenge_id:int,
     *   provider:string,
     *   phase:string,
     *   start_date:string,
     *   status:string,
     *   risk_state:string,
     *   target_progress:array{net_profit:float,target_profit:float,remaining:float,progress_pct:float,met:bool},
     *   daily_loss_headroom:array{limit:float,used:float,headroom:float,worst_used:float,breached:bool},
     *   total_dd_headroom:array{limit:float,used:float,headroom:float,breached:bool},
     *   min_days_progress:array{required:int,actual:int,remaining:int,progress_pct:float,met:bool},
     *   evaluated_through:string
     * }
     */
    public function status(Account $account): array
    {
        $challenge = $this->getOrCreateChallenge($account);
        $startingBalance = (float) $challenge->starting_balance;
        $targetProfit = ($startingBalance * (float) $challenge->profit_target_pct) / 100;
        $dailyLossLimit = ($startingBalance * (float) $challenge->max_daily_loss_pct) / 100;
        $totalDrawdownLimit = ($startingBalance * (float) $challenge->max_total_drawdown_pct) / 100;
        $challengeStartDate = Carbon::parse((string) $challenge->start_date)->toDateString();

        $trades = Trade::query()
            ->where('account_id', $account->id)
            ->whereDate('date', '>=', $challengeStartDate)
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'date', 'profit_loss']);

        $netProfit = (float) $trades->sum('profit_loss');
        $currentDay = Carbon::now()->toDateString();
        $dailyPnl = $this->dailyPnl($trades);

        $todayPnl = (float) ($dailyPnl[$currentDay] ?? 0.0);
        $todayLossUsed = $todayPnl < 0 ? abs($todayPnl) : 0.0;
        $worstDailyLoss = collect($dailyPnl)
            ->values()
            ->map(fn (float $pnl): float => $pnl < 0 ? abs($pnl) : 0.0)
            ->max() ?? 0.0;

        $equityStats = $this->equityRiskStats($trades, $startingBalance);
        $drawdownUsed = (float) $equityStats['drawdown_used'];
        $tradingDays = count($dailyPnl);
        $requiredDays = (int) $challenge->min_trading_days;

        $targetMet = $netProfit >= $targetProfit;
        $daysMet = $tradingDays >= $requiredDays;
        $dailyBreached = $worstDailyLoss > $dailyLossLimit;
        $drawdownBreached = $drawdownUsed > $totalDrawdownLimit;

        $riskState = 'in_progress';
        $computedStatus = (string) $challenge->status;
        if ($dailyBreached || $drawdownBreached) {
            $riskState = 'fail';
            $computedStatus = 'failed';
        } elseif ($targetMet && $daysMet) {
            $riskState = 'pass';
            $computedStatus = 'passed';
        }

        $targetProgressPct = $targetProfit > 0
            ? ($netProfit / $targetProfit) * 100
            : 0.0;
        $daysProgressPct = $requiredDays > 0
            ? ($tradingDays / $requiredDays) * 100
            : 0.0;

        return [
            'account_id' => (int) $account->id,
            'challenge_id' => (int) $challenge->id,
            'provider' => (string) $challenge->provider,
            'phase' => (string) $challenge->phase,
            'start_date' => $challengeStartDate,
            'status' => $computedStatus,
            'risk_state' => $riskState,
            'target_progress' => [
                'net_profit' => round($netProfit, 2),
                'target_profit' => round($targetProfit, 2),
                'remaining' => round(max(0.0, $targetProfit - $netProfit), 2),
                'progress_pct' => round($targetProgressPct, 4),
                'met' => $targetMet,
            ],
            'daily_loss_headroom' => [
                'limit' => round($dailyLossLimit, 2),
                'used' => round($todayLossUsed, 2),
                'headroom' => round(max(0.0, $dailyLossLimit - $todayLossUsed), 2),
                'worst_used' => round($worstDailyLoss, 2),
                'breached' => $dailyBreached,
            ],
            'total_dd_headroom' => [
                'limit' => round($totalDrawdownLimit, 2),
                'used' => round($drawdownUsed, 2),
                'headroom' => round(max(0.0, $totalDrawdownLimit - $drawdownUsed), 2),
                'breached' => $drawdownBreached,
            ],
            'min_days_progress' => [
                'required' => $requiredDays,
                'actual' => $tradingDays,
                'remaining' => max(0, $requiredDays - $tradingDays),
                'progress_pct' => round($daysProgressPct, 4),
                'met' => $daysMet,
            ],
            'evaluated_through' => Carbon::now()->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultChallengePayload(Account $account): array
    {
        return [
            'provider' => 'FTMO',
            'phase' => 'Phase 1',
            'starting_balance' => (float) $account->starting_balance,
            'profit_target_pct' => 10.0,
            'max_daily_loss_pct' => 5.0,
            'max_total_drawdown_pct' => 10.0,
            'min_trading_days' => 4,
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'passed_at' => null,
            'failed_at' => null,
        ];
    }

    /**
     * @param Collection<int, Trade> $trades
     * @return array<string, float>
     */
    private function dailyPnl(Collection $trades): array
    {
        return $trades
            ->groupBy(fn (Trade $trade): string => Carbon::parse((string) $trade->date)->toDateString())
            ->map(fn (Collection $rows): float => (float) $rows->sum('profit_loss'))
            ->all();
    }

    /**
     * @param Collection<int, Trade> $trades
     * @return array{drawdown_used:float}
     */
    private function equityRiskStats(Collection $trades, float $startingBalance): array
    {
        $equity = $startingBalance;
        $lowestEquity = $startingBalance;

        foreach ($trades as $trade) {
            $equity += (float) $trade->profit_loss;
            if ($equity < $lowestEquity) {
                $lowestEquity = $equity;
            }
        }

        return [
            'drawdown_used' => max(0.0, $startingBalance - $lowestEquity),
        ];
    }
}

