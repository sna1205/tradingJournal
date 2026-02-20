<?php

namespace Database\Factories;

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
        $riskPips = fake()->randomFloat(6, $config['risk_min'], $config['risk_max']);
        $rewardMultiplier = fake()->randomFloat(2, 1, 4);
        $outcome = fake()->randomElement(['win', 'win', 'win', 'loss', 'loss', 'breakeven']);
        $riskAmount = fake()->randomFloat(2, 40, 420);
        $rr = fake()->randomFloat(2, 0.8, 4.2);

        $stopLoss = $direction === 'buy'
            ? max(0.0001, $entryPrice - $riskPips)
            : $entryPrice + $riskPips;
        $takeProfit = $direction === 'buy'
            ? $entryPrice + ($riskPips * $rewardMultiplier)
            : max(0.0001, $entryPrice - ($riskPips * $rewardMultiplier));
        $profitLoss = match ($outcome) {
            'win' => round($riskAmount * $rr, 2),
            'loss' => round($riskAmount * -1, 2),
            default => 0.0,
        };

        return [
            'pair' => $pair,
            'direction' => $direction,
            'entry_price' => $entryPrice,
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'lot_size' => fake()->randomFloat(4, 0.02, 5),
            'profit_loss' => $profitLoss,
            'rr' => $rr,
            'session' => fake()->randomElement(['Asia', 'London', 'New York']),
            'model' => fake()->randomElement(['Breakout', 'Pullback', 'Liquidity Sweep', 'Reversal']),
            'date' => Carbon::now()
                ->subDays(fake()->numberBetween(0, 180))
                ->setTime(fake()->numberBetween(6, 22), fake()->numberBetween(0, 59)),
            'notes' => fake()->sentence(),
        ];
    }
}
