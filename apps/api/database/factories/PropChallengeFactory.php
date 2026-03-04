<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\PropChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PropChallenge>
 */
class PropChallengeFactory extends Factory
{
    protected $model = PropChallenge::class;

    public function definition(): array
    {
        $startingBalance = fake()->randomFloat(2, 5_000, 200_000);

        return [
            'account_id' => Account::factory(),
            'provider' => fake()->randomElement(['FTMO', 'MyForexFunds', 'FundedNext', 'The5ers']),
            'phase' => fake()->randomElement(['Phase 1', 'Phase 2', 'Verification']),
            'starting_balance' => $startingBalance,
            'profit_target_pct' => fake()->randomFloat(4, 6, 12),
            'max_daily_loss_pct' => fake()->randomFloat(4, 3, 6),
            'max_total_drawdown_pct' => fake()->randomFloat(4, 8, 12),
            'min_trading_days' => fake()->numberBetween(3, 10),
            'start_date' => Carbon::now()->subDays(fake()->numberBetween(1, 60))->toDateString(),
            'status' => 'active',
            'passed_at' => null,
            'failed_at' => null,
        ];
    }
}

