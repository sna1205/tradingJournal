<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiDeprecatedAliasHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_alias_returns_deprecation_headers_and_logs_warning(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Log::spy();

        $response = $this->getJson('/api/rules');

        $response->assertOk();
        $response->assertHeader('Deprecation', 'true');
        $this->assertSunsetWindow($response);
        $this->assertStringContainsString('/api/checklists', (string) $response->headers->get('Link'));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Deprecated API alias used.'
                    && ($context['canonical_path'] ?? null) === '/api/checklists';
            })
            ->once();
    }

    public function test_trade_checklist_resolve_alias_returns_deprecation_headers(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = Account::factory()->create([
            'user_id' => (int) $user->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/trade-checklist/resolve?'.http_build_query([
            'account_id' => (int) $account->id,
        ]));

        $response->assertOk();
        $response->assertHeader('Deprecation', 'true');
        $this->assertSunsetWindow($response);
        $this->assertStringContainsString('/api/trade-rules/resolve', (string) $response->headers->get('Link'));
    }

    public function test_trade_checklist_preview_alias_returns_deprecation_headers(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = Account::factory()->create([
            'user_id' => (int) $user->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/trade-checklist/preview', [
            'account_id' => (int) $account->id,
            'responses' => [],
            'precheck_metrics' => [],
        ]);

        $response->assertOk();
        $response->assertHeader('Deprecation', 'true');
        $this->assertSunsetWindow($response);
        $this->assertStringContainsString('/api/trade-rules/preview', (string) $response->headers->get('Link'));
    }

    public function test_analytics_risk_status_alias_returns_deprecation_headers(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/analytics/risk_status');

        $response->assertOk();
        $response->assertHeader('Deprecation', 'true');
        $this->assertSunsetWindow($response);
        $this->assertStringContainsString('/api/analytics/risk-status', (string) $response->headers->get('Link'));
    }

    private function assertSunsetWindow(TestResponse $response): void
    {
        $sunsetRaw = (string) $response->headers->get('Sunset');
        $this->assertNotSame('', $sunsetRaw, 'Sunset header is missing.');

        $sunset = CarbonImmutable::parse($sunsetRaw);
        $nowUtc = CarbonImmutable::now('UTC');

        $this->assertTrue(
            $sunset->greaterThan($nowUtc->addDays(30)),
            sprintf('Expected Sunset > 30 days ahead, got %s', $sunset->toIso8601String())
        );
        $this->assertTrue(
            $sunset->lessThan($nowUtc->addDays(60)),
            sprintf('Expected Sunset < 60 days ahead, got %s', $sunset->toIso8601String())
        );
    }
}
