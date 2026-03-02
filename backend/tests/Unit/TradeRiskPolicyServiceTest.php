<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\AccountRiskPolicy;
use App\Models\User;
use App\Services\TradeRiskPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeRiskPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_blocks_when_monetary_risk_implies_percent_above_limit_even_if_input_percent_is_rounded_down(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        AccountRiskPolicy::query()->create([
            'account_id' => (int) $account->id,
            'max_risk_per_trade_pct' => 1.0000,
            'max_daily_loss_pct' => 10.0000,
            'max_total_drawdown_pct' => 50.0000,
            'max_open_risk_pct' => 1.0000,
            'enforce_hard_limits' => true,
            'allow_override' => false,
        ]);

        $service = app(TradeRiskPolicyService::class);
        $evaluation = $service->evaluate([
            'account_id' => (int) $account->id,
            'account_starting_balance' => 10_000,
            'account_current_balance' => 10_000,
            'risk_percent' => 1.0000,
            'monetary_risk' => 100.09,
            'trade_date' => now()->toIso8601String(),
        ]);

        $this->assertFalse((bool) $evaluation['allowed']);
        $this->assertGreaterThan(1.0, (float) ($evaluation['stats']['risk_percent'] ?? 0));
    }

    public function test_trader_role_cannot_use_override_even_when_account_policy_allows_it(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        AccountRiskPolicy::query()->create([
            'account_id' => (int) $account->id,
            'max_risk_per_trade_pct' => 0.5000,
            'max_daily_loss_pct' => 10.0000,
            'max_total_drawdown_pct' => 50.0000,
            'max_open_risk_pct' => 0.5000,
            'enforce_hard_limits' => true,
            'allow_override' => true,
        ]);

        $service = app(TradeRiskPolicyService::class);

        $traderEvaluation = $service->evaluate([
            'account_id' => (int) $account->id,
            'account_starting_balance' => 10_000,
            'account_current_balance' => 10_000,
            'risk_percent' => 3.0,
            'monetary_risk' => 300.0,
            'trade_date' => now()->toIso8601String(),
            'risk_override_reason' => 'intentional override request',
            'actor_role' => 'trader',
        ]);

        $adminEvaluation = $service->evaluate([
            'account_id' => (int) $account->id,
            'account_starting_balance' => 10_000,
            'account_current_balance' => 10_000,
            'risk_percent' => 3.0,
            'monetary_risk' => 300.0,
            'trade_date' => now()->toIso8601String(),
            'risk_override_reason' => 'intentional override request',
            'actor_role' => 'admin',
        ]);

        $this->assertFalse((bool) $traderEvaluation['allowed']);
        $this->assertFalse((bool) ($traderEvaluation['policy']['allow_override'] ?? true));
        $this->assertTrue((bool) $adminEvaluation['allowed']);
        $this->assertTrue((bool) ($adminEvaluation['policy']['allow_override'] ?? false));
    }
}
