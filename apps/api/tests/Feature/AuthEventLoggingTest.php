<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_writes_auth_event_with_ip_and_user_agent(): void
    {
        $user = User::factory()->create([
            'email' => 'audit-fail@example.com',
            'password' => 'password123',
        ]);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
            ->withHeaders(['User-Agent' => 'AuthAuditTest/1.0'])
            ->postJson('/api/auth/login', [
                'email' => 'audit-fail@example.com',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->id,
            'email' => 'audit-fail@example.com',
            'ip' => '198.51.100.11',
            'user_agent' => 'AuthAuditTest/1.0',
            'action' => 'login',
            'outcome' => 'fail',
            'reason' => 'invalid_credentials',
        ]);
    }

    public function test_successful_login_writes_auth_event_with_ip_and_user_agent(): void
    {
        $user = User::factory()->create([
            'email' => 'audit-success@example.com',
            'password' => 'password123',
        ]);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeaders(['User-Agent' => 'AuthAuditTest/2.0'])
            ->postJson('/api/auth/login', [
                'email' => 'audit-success@example.com',
                'password' => 'password123',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->id,
            'email' => 'audit-success@example.com',
            'ip' => '203.0.113.7',
            'user_agent' => 'AuthAuditTest/2.0',
            'action' => 'login',
            'outcome' => 'success',
            'reason' => null,
        ]);
    }
}
