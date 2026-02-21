<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
{
    protected $model = Trade::class;

    public function definition(): array
    {
        $pairConfig = [
            'EURUSD' => ['min' => 1.06, 'max' => 1.12, 'risk_min' => 0.0004, 'risk_max' => 0.0020],
            'GBPUSD' => ['min' => 1.22, 'max' => 1.31, 'risk_min' => 0.0005, 'risk_max' => 0.0025],
            'USDJPY' => ['min' => 145.0, 'max' => 159.0, 'risk_min' => 0.04, 'risk_max' => 0.35],
            'XAUUSD' => ['min' => 1900.0, 'max' => 2400.0, 'risk_min' => 2.0, 'risk_max' => 18.0],
            'BTCUSD' => ['min' => 35000.0, 'max' => 98000.0, 'risk_min' => 120.0, 'risk_max' => 1300.0],
            'ETHUSD' => ['min' => 1800.0, 'max' => 5200.0, 'risk_min' => 8.0, 'risk_max' => 95.0],
        ];

        $pair = fake()->randomElement(array_keys($pairConfig));
        $config = $pairConfig[$pair];
        $direction = fake()->randomElement(['buy', 'sell']);
        $entryPrice = fake()->randomFloat(6, $config['min'], $config['max']);
        $riskPerUnit = fake()->randomFloat(6, $config['risk_min'], $config['risk_max']);
        $rewardMultiplier = fake()->randomFloat(3, 1.0, 4.5);
        $rewardPerUnit = $riskPerUnit * $rewardMultiplier;
        $positionSize = fake()->randomFloat(4, 0.02, 5);
        $accountBefore = fake()->randomFloat(2, 1_500, 120_000);
        $outcome = fake()->randomElement(['win', 'win', 'loss', 'loss', 'breakeven']);

        $stopLoss = $direction === 'buy'
            ? max(0.0001, $entryPrice - $riskPerUnit)
            : $entryPrice + $riskPerUnit;
        $takeProfit = $direction === 'buy'
            ? $entryPrice + $rewardPerUnit
            : max(0.0001, $entryPrice - $rewardPerUnit);
        $actualExitPrice = match ($outcome) {
            'win' => $takeProfit,
            'loss' => $stopLoss,
            default => $entryPrice,
        };

        $monetaryRisk = $riskPerUnit * $positionSize;
        $monetaryReward = $rewardPerUnit * $positionSize;
        $profitPerUnit = $direction === 'sell'
            ? ($entryPrice - $actualExitPrice)
            : ($actualExitPrice - $entryPrice);
        $profitLoss = $profitPerUnit * $positionSize;
        $rMultiple = $monetaryRisk > 0 ? ($profitLoss / $monetaryRisk) : 0.0;
        $riskPercent = $accountBefore > 0 ? (($monetaryRisk / $accountBefore) * 100) : 0.0;
        $accountAfter = $accountBefore + $profitLoss;

        return [
            'account_id' => Account::factory(),
            'pair' => $pair,
            'direction' => $direction,
            'entry_price' => $entryPrice,
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'actual_exit_price' => $actualExitPrice,
            'lot_size' => $positionSize,
            'risk_per_unit' => round($riskPerUnit, 6),
            'reward_per_unit' => round($rewardPerUnit, 6),
            'monetary_risk' => round($monetaryRisk, 6),
            'monetary_reward' => round($monetaryReward, 6),
            'profit_loss' => round($profitLoss, 2),
            'rr' => round($rMultiple, 2),
            'r_multiple' => round($rMultiple, 4),
            'risk_percent' => round($riskPercent, 4),
            'account_balance_before_trade' => round($accountBefore, 2),
            'account_balance_after_trade' => round($accountAfter, 2),
            'followed_rules' => fake()->boolean(70),
            'emotion' => fake()->randomElement(['neutral', 'calm', 'confident', 'fearful', 'greedy', 'hesitant', 'revenge']),
            'session' => fake()->randomElement(['Asia', 'London', 'New York']),
            'model' => fake()->randomElement(['Breakout', 'Pullback', 'Liquidity Sweep', 'Reversal']),
            'date' => Carbon::now()
                ->subDays(fake()->numberBetween(0, 180))
                ->setTime(fake()->numberBetween(6, 22), fake()->numberBetween(0, 59)),
            'notes' => fake()->sentence(),
        ];
    }
}
