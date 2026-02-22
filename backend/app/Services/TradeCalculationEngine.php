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
     *   account_balance_before_trade:numeric-string|int|float,
     *   instrument_tick_size?:numeric-string|int|float|null,
     *   instrument_tick_value?:numeric-string|int|float|null,
     *   commission?:numeric-string|int|float|null,
     *   swap?:numeric-string|int|float|null,
     *   spread_cost?:numeric-string|int|float|null,
     *   slippage_cost?:numeric-string|int|float|null
     * } $input
     * @return array{
     *   risk_per_unit:float,
     *   reward_per_unit:float,
     *   monetary_risk:float,
     *   monetary_reward:float,
     *   gross_profit_loss:float,
     *   costs_total:float,
     *   commission:float,
     *   swap:float,
     *   spread_cost:float,
     *   slippage_cost:float,
     *   profit_loss:float,
     *   rr:float,
     *   r_multiple:float,
     *   risk_percent:float,
     *   account_balance_after_trade:float
     * }
     */
    public function calculate(array $input): array
    {
        $direction = strtolower((string) $input['direction']);
        $entryPrice = (float) $input['entry_price'];
        $stopLoss = (float) $input['stop_loss'];
        $takeProfit = (float) $input['take_profit'];
        $actualExitPrice = (float) $input['actual_exit_price'];
        $positionSize = (float) $input['lot_size'];
        $accountBalanceBeforeTrade = (float) $input['account_balance_before_trade'];
        $tickSize = (float) ($input['instrument_tick_size'] ?? 0);
        $tickValue = (float) ($input['instrument_tick_value'] ?? 0);

        $commission = (float) ($input['commission'] ?? 0);
        $swap = (float) ($input['swap'] ?? 0);
        $spreadCost = (float) ($input['spread_cost'] ?? 0);
        $slippageCost = (float) ($input['slippage_cost'] ?? 0);
        $costsTotal = $commission + $swap + $spreadCost + $slippageCost;

        $riskPerUnit = abs($entryPrice - $stopLoss);
        $rewardPerUnit = abs($takeProfit - $entryPrice);
        $monetaryRisk = $this->valueFromPriceDistance($riskPerUnit, $positionSize, $tickSize, $tickValue);
        $monetaryReward = $this->valueFromPriceDistance($rewardPerUnit, $positionSize, $tickSize, $tickValue);

        $profitPerUnit = $direction === 'sell'
            ? ($entryPrice - $actualExitPrice)
            : ($actualExitPrice - $entryPrice);
        $grossProfitLoss = $this->valueFromPriceDistance($profitPerUnit, $positionSize, $tickSize, $tickValue);
        $profitLoss = $grossProfitLoss - $costsTotal;

        $rr = $monetaryRisk > 0
            ? $monetaryReward / $monetaryRisk
            : 0.0;
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
            'gross_profit_loss' => round($grossProfitLoss, 6),
            'costs_total' => round($costsTotal, 6),
            'commission' => round($commission, 6),
            'swap' => round($swap, 6),
            'spread_cost' => round($spreadCost, 6),
            'slippage_cost' => round($slippageCost, 6),
            'profit_loss' => round($profitLoss, 2),
            'rr' => round($rr, 2),
            'r_multiple' => round($rMultiple, 4),
            'risk_percent' => round($riskPercent, 4),
            'account_balance_after_trade' => round($accountBalanceAfterTrade, 2),
        ];
    }

    private function valueFromPriceDistance(
        float $priceDistance,
        float $positionSize,
        float $tickSize,
        float $tickValue
    ): float {
        if ($tickSize > 0 && $tickValue > 0) {
            $ticks = $priceDistance / $tickSize;
            return $ticks * $tickValue * $positionSize;
        }

        // Legacy fallback for historical rows without instrument specs.
        return $priceDistance * $positionSize;
    }
}
