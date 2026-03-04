<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsCacheIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_cache_is_isolated_by_authenticated_user_for_identical_query_params(): void
    {
        Cache::flush();

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $accountA = Account::factory()->create([
            'user_id' => $userA->id,
            'starting_balance' => 10_000,
            'current_balance' => 10_100,
            'is_active' => true,
        ]);
        $accountB = Account::factory()->create([
            'user_id' => $userB->id,
            'starting_balance' => 10_000,
            'current_balance' => 9_950,
            'is_active' => true,
        ]);

        Trade::factory()->create([
            'account_id' => $accountA->id,
            'profit_loss' => 100.0,
            'r_multiple' => 1.0,
            'date' => '2026-01-10 10:00:00',
        ]);
        Trade::factory()->create([
            'account_id' => $accountB->id,
            'profit_loss' => -50.0,
            'r_multiple' => -0.5,
            'date' => '2026-01-10 10:00:00',
        ]);

        $query = 'date_from=2026-01-01&date_to=2026-01-31';

        Sanctum::actingAs($userA);
        $responseA = $this->getJson("/api/analytics/overview?{$query}");
        $responseA->assertOk();
        $this->assertSame(100.0, (float) $responseA->json('total_profit'));
        $this->assertSame(0.0, (float) $responseA->json('total_loss'));

        Sanctum::actingAs($userB);
        $responseB = $this->getJson("/api/analytics/overview?{$query}");
        $responseB->assertOk();
        $this->assertSame(0.0, (float) $responseB->json('total_profit'));
        $this->assertSame(50.0, (float) $responseB->json('total_loss'));

        $this->assertNotSame(
            $responseA->json('total_profit'),
            $responseB->json('total_profit'),
            'Analytics cache leaked payload between tenants with identical query params.'
        );
    }
}
