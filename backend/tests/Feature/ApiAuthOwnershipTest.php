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

    public function test_register_login_and_me_endpoints_issue_and_use_tokens(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Alice Trader',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $register->assertCreated();
        $token = (string) $register->json('token');
        $this->assertNotSame('', $token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('email', 'alice@example.com');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $login->assertOk();
        $secondToken = (string) $login->json('token');
        $this->assertNotSame('', $secondToken);
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
}
