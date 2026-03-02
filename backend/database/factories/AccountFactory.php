<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        $startingBalance = fake()->randomFloat(2, 2_500, 200_000);

        return [
            'user_id' => null,
            'name' => fake()->unique()->randomElement([
                'FTMO 100K',
                'Personal Swing',
                'Demo Scalping',
                'Challenge Phase 2',
                'Prop Evaluation',
            ]),
            'broker' => fake()->randomElement(['FTMO', 'IC Markets', 'Pepperstone', 'OANDA', 'Bybit']),
            'account_type' => fake()->randomElement(['funded', 'personal', 'demo']),
            'starting_balance' => $startingBalance,
            'current_balance' => $startingBalance,
            'currency' => 'USD',
            'is_active' => fake()->boolean(85),
        ];
    }
}
