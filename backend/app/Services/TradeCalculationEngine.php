<?php

namespace App\Services;

class TradeCalculationEngine
{
    /**
     * @param array{
     *   direction:string,
     *   entry_price:numeric-string|int|float,
     *   stop_loss:numeric-string|int|float,
     *   take_profit:numeric-string|int|float,
     *   actual_exit_price:numeric-string|int|float,
     *   lot_size:numeric-string|int|float,
     *   account_balance_before_trade:numeric-string|int|float
     * } $input
     * @return array{
     *   risk_per_unit:float,
     *   reward_per_unit:float,
     *   monetary_risk:float,
     *   monetary_reward:float,
     *   profit_loss:float,
     *   rr:float,
     *   r_multiple:float,
     *   risk_percent:float,
     *   account_balance_after_trade:float
     * }
     */
    public function calculate(array $input): array
    {
        $direction = (string) $input['direction'];
        $entryPrice = (float) $input['entry_price'];
        $stopLoss = (float) $input['stop_loss'];
        $takeProfit = (float) $input['take_profit'];
        $actualExitPrice = (float) $input['actual_exit_price'];
        $positionSize = (float) $input['lot_size'];
        $accountBalanceBeforeTrade = (float) $input['account_balance_before_trade'];

        $riskPerUnit = abs($entryPrice - $stopLoss);
        $rewardPerUnit = abs($takeProfit - $entryPrice);
        $monetaryRisk = $riskPerUnit * $positionSize;
        $monetaryReward = $rewardPerUnit * $positionSize;

        $profitPerUnit = $direction === 'sell'
            ? ($entryPrice - $actualExitPrice)
            : ($actualExitPrice - $entryPrice);
        $profitLoss = $profitPerUnit * $positionSize;

        $rMultiple = $monetaryRisk > 0
            ? $profitLoss / $monetaryRisk
            : 0.0;
        $riskPercent = $accountBalanceBeforeTrade > 0
            ? ($monetaryRisk / $accountBalanceBeforeTrade) * 100
            : 0.0;
        $accountBalanceAfterTrade = $accountBalanceBeforeTrade + $profitLoss;

        return [
            'risk_per_unit' => round($riskPerUnit, 6),
            'reward_per_unit' => round($rewardPerUnit, 6),
            'monetary_risk' => round($monetaryRisk, 6),
            'monetary_reward' => round($monetaryReward, 6),
            'profit_loss' => round($profitLoss, 2),
            'rr' => round($rMultiple, 2),
            'r_multiple' => round($rMultiple, 4),
            'risk_percent' => round($riskPercent, 4),
            'account_balance_after_trade' => round($accountBalanceAfterTrade, 2),
        ];
    }
}
