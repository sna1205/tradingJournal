<?php

namespace Database\Factories;

use App\Models\MissedTrade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MissedTrade>
 */
class MissedTradeFactory extends Factory
{
    protected $model = MissedTrade::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pair' => fake()->randomElement(['EURUSD', 'GBPUSD', 'USDJPY', 'XAUUSD', 'BTCUSD', 'ETHUSD']),
            'model' => fake()->randomElement(['Breakout', 'Pullback', 'Liquidity Sweep', 'Reversal']),
            'reason' => fake()->randomElement([
                'Late entry',
                'No confirmation',
                'Risk limit reached',
                'News event',
                'Emotional hesitation',
                'Setup not clean',
                'Missed alert',
            ]),
            'date' => Carbon::now()
                ->subDays(fake()->numberBetween(0, 120))
                ->setTime(fake()->numberBetween(7, 20), fake()->numberBetween(0, 59)),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
