<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountRiskPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountRiskPolicy>
 */
class AccountRiskPolicyFactory extends Factory
{
    protected $model = AccountRiskPolicy::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'max_risk_per_trade_pct' => 1.0000,
            'max_daily_loss_pct' => 5.0000,
            'max_total_drawdown_pct' => 10.0000,
            'max_open_risk_pct' => 2.0000,
            'enforce_hard_limits' => true,
            'allow_override' => false,
        ];
    }
}

