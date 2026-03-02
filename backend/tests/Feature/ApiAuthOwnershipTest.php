<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Checklist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAuthOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_login_and_me_endpoints_use_session_cookie_authentication(): void
    {
        $headers = $this->statefulSpaHeaders();

        $this->get('/sanctum/csrf-cookie', $headers)->assertNoContent();

        $register = $this->postJson('/api/auth/register', [
            'name' => 'Alice Trader',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $headers);

        $register->assertCreated();
        $register->assertJsonMissingPath('token');
        $register->assertJsonPath('user.email', 'alice@example.com');

        $this->getJson('/api/auth/me', $headers)
            ->assertOk()
            ->assertJsonPath('email', 'alice@example.com');

        $logout = $this->postJson('/api/auth/logout', [], $headers);
        $logout->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/api/auth/me', $headers)->assertUnauthorized();

        $this->get('/sanctum/csrf-cookie', $headers)->assertNoContent();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'password123',
        ], $headers);

        $login->assertOk();
        $login->assertJsonMissingPath('token');
        $login->assertJsonPath('user.email', 'alice@example.com');
        $this->getJson('/api/auth/me', $headers)
            ->assertOk()
            ->assertJsonPath('email', 'alice@example.com');
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->getJson('/api/accounts')->assertUnauthorized();
    }

    public function test_user_cannot_access_another_users_account(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $ownAccount = Account::factory()->create(['user_id' => $userA->id]);
        $otherAccount = Account::factory()->create(['user_id' => $userB->id]);

        Sanctum::actingAs($userA);

        $index = $this->getJson('/api/accounts');
        $index->assertOk();
        $this->assertSame([$ownAccount->id], collect($index->json())->pluck('id')->all());

        $this->getJson("/api/accounts/{$otherAccount->id}")
            ->assertForbidden();
    }

    public function test_spoofed_user_headers_and_query_do_not_change_checklist_scope(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $checklistA = Checklist::query()->create([
            'user_id' => $userA->id,
            'account_id' => null,
            'name' => 'A checklist',
            'scope' => 'global',
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);
        Checklist::query()->create([
            'user_id' => $userB->id,
            'account_id' => null,
            'name' => 'B checklist',
            'scope' => 'global',
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson(
            "/api/checklists?user_id={$userB->id}",
            ['X-User-Id' => (string) $userB->id]
        );

        $response->assertOk();
        $this->assertSame([$checklistA->id], collect($response->json())->pluck('id')->all());
    }

    /**
     * @return array<string, string>
     */
    private function statefulSpaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
            'Accept' => 'application/json',
        ];
    }
}
