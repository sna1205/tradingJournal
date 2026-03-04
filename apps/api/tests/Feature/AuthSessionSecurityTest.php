<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_all_removes_other_sessions_and_revokes_tokens(): void
    {
        config([
            'session.driver' => 'database',
            'session.lifetime' => 120,
            'sanctum.expiration' => 120,
        ]);

        $user = User::factory()->create();

        DB::table((string) config('session.table', 'sessions'))->insert([
            [
                'id' => 'session-alpha',
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'payload' => base64_encode(serialize(['_token' => 'alpha'])),
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'session-beta',
                'user_id' => $user->id,
                'ip_address' => '127.0.0.2',
                'user_agent' => 'phpunit',
                'payload' => base64_encode(serialize(['_token' => 'beta'])),
                'last_activity' => now()->timestamp,
            ],
        ]);

        $revokedToken = $user->createToken('revoked-device')->plainTextToken;
        $activeToken = $user->createToken('current-device')->plainTextToken;

        app('auth')->forgetGuards();
        $logoutAll = $this->withHeaders(['Authorization' => "Bearer {$activeToken}"])
            ->postJson('/api/auth/logout-all');

        $logoutAll->assertOk();
        $logoutAll->assertJsonPath('revoked_sessions', 2);
        $logoutAll->assertJsonPath('revoked_tokens', 2);

        $this->assertDatabaseMissing((string) config('session.table', 'sessions'), [
            'id' => 'session-alpha',
        ]);
        $this->assertDatabaseMissing((string) config('session.table', 'sessions'), [
            'id' => 'session-beta',
        ]);
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());

        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$revokedToken}"])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_personal_access_token_expires_with_finite_sanctum_lifetime(): void
    {
        config([
            'sanctum.expiration' => 1,
            'session.lifetime' => 120,
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('short-lived')->plainTextToken;

        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->travel(2)->minutes();

        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_issued_personal_access_token_stores_expires_at_timestamp(): void
    {
        config([
            'sanctum.expiration' => 120,
            'session.lifetime' => 120,
        ]);

        $user = User::factory()->create();
        $newToken = $user->createToken('expiry-assert');

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $newToken->accessToken->id,
        ]);
        $this->assertNotNull($newToken->accessToken->expires_at);
    }
}
