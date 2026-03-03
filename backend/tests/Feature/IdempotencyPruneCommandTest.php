<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_deletes_expired_and_legacy_rows(): void
    {
        config(['idempotency.ttl_minutes' => 60]);

        $user = User::factory()->create();
        $now = now();

        DB::table('idempotency_keys')->insert([
            [
                'user_id' => (int) $user->id,
                'route' => 'POST api/trades',
                'key' => 'expired-row',
                'request_hash' => str_repeat('a', 64),
                'response_code' => 201,
                'response_body' => '{"id":1}',
                'created_at' => $now->copy()->subHours(2),
                'expires_at' => $now->copy()->subMinute(),
            ],
            [
                'user_id' => (int) $user->id,
                'route' => 'POST api/trades',
                'key' => 'active-row',
                'request_hash' => str_repeat('b', 64),
                'response_code' => 201,
                'response_body' => '{"id":2}',
                'created_at' => $now->copy()->subMinutes(10),
                'expires_at' => $now->copy()->addHour(),
            ],
            [
                'user_id' => (int) $user->id,
                'route' => 'POST api/trades',
                'key' => 'legacy-old-row',
                'request_hash' => str_repeat('c', 64),
                'response_code' => 201,
                'response_body' => '{"id":3}',
                'created_at' => $now->copy()->subHours(3),
                'expires_at' => null,
            ],
            [
                'user_id' => (int) $user->id,
                'route' => 'POST api/trades',
                'key' => 'legacy-recent-row',
                'request_hash' => str_repeat('d', 64),
                'response_code' => 201,
                'response_body' => '{"id":4}',
                'created_at' => $now->copy()->subMinutes(15),
                'expires_at' => null,
            ],
        ]);

        $this->artisan('idempotency:prune')
            ->assertSuccessful();

        $keys = DB::table('idempotency_keys')->pluck('key')->all();

        $this->assertEqualsCanonicalizing(['active-row', 'legacy-recent-row'], $keys);
    }

    public function test_prune_command_dry_run_does_not_delete_rows(): void
    {
        $user = User::factory()->create();

        DB::table('idempotency_keys')->insert([
            'user_id' => (int) $user->id,
            'route' => 'POST api/trades',
            'key' => 'expired-dry-run',
            'request_hash' => str_repeat('e', 64),
            'response_code' => 201,
            'response_body' => '{"id":5}',
            'created_at' => now()->subHours(2),
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('idempotency:prune --dry-run')
            ->assertSuccessful();

        $this->assertDatabaseHas('idempotency_keys', [
            'key' => 'expired-dry-run',
        ]);
    }
}
