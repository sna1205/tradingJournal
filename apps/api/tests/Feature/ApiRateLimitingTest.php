<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_login_is_throttled_after_five_attempts_per_ip_and_email(): void
    {
        $payload = [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }

    public function test_login_throttle_key_includes_email_dimension(): void
    {
        $primary = [
            'email' => 'primary@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', $primary)->assertStatus(422);
        }

        $secondary = [
            'email' => 'secondary@example.com',
            'password' => 'wrong-password',
        ];

        $this->postJson('/api/auth/login', $secondary)->assertStatus(422);
    }

    public function test_login_progressive_backoff_applies_after_repeated_failures(): void
    {
        $payload = [
            'email' => 'backoff@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(422);
        }

        $this->travel(61)->seconds();

        $this->postJson('/api/auth/login', $payload)->assertStatus(422);

        $locked = $this->postJson('/api/auth/login', $payload);
        $locked->assertStatus(429);
        $locked->assertJsonPath('message', 'Too many failed login attempts. Please retry later.');
        $this->assertGreaterThan(0, (int) $locked->json('retry_after'));
    }

    public function test_analytics_overview_is_throttled_after_twenty_requests_per_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/analytics/overview')->assertOk();
        }

        $this->getJson('/api/analytics/overview')->assertStatus(429);
    }
}
