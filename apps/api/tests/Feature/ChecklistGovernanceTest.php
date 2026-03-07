<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Checklist;
use App\Models\User;
use App\Services\ChecklistService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChecklistGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_active_checklist_creation_deactivates_previous_scope_match(): void
    {
        $account = $this->createOwnedAccount();

        $first = $this->postJson('/api/checklists', [
            'name' => 'Account Risk Checklist A',
            'scope' => 'account',
            'account_id' => (int) $account->id,
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);
        $first->assertCreated();
        $firstId = (int) $first->json('id');

        $second = $this->postJson('/api/checklists', [
            'name' => 'Account Risk Checklist B',
            'scope' => 'account',
            'account_id' => (int) $account->id,
            'enforcement_mode' => 'strict',
            'is_active' => true,
        ]);
        $second->assertCreated();
        $secondId = (int) $second->json('id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertDatabaseHas('checklists', [
            'id' => $firstId,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('checklists', [
            'id' => $secondId,
            'is_active' => true,
        ]);

        $activeCount = Checklist::query()
            ->where('user_id', (int) $this->user->id)
            ->where('scope', 'account')
            ->where('account_id', (int) $account->id)
            ->where('is_active', true)
            ->count();
        $this->assertSame(1, $activeCount);
    }

    public function test_database_unique_constraint_rejects_two_active_scope_matches(): void
    {
        $account = $this->createOwnedAccount();

        Checklist::query()->create([
            'user_id' => (int) $this->user->id,
            'scope' => 'account',
            'account_id' => (int) $account->id,
            'strategy_model_id' => null,
            'name' => 'Constraint Source',
            'revision' => 1,
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        Checklist::query()->create([
            'user_id' => (int) $this->user->id,
            'scope' => 'account',
            'account_id' => (int) $account->id,
            'strategy_model_id' => null,
            'name' => 'Constraint Target',
            'revision' => 1,
            'enforcement_mode' => 'strict',
            'is_active' => true,
        ]);
    }

    public function test_update_returns_409_for_stale_if_match_etag(): void
    {
        $checklist = Checklist::query()->create([
            'user_id' => (int) $this->user->id,
            'scope' => 'global',
            'account_id' => null,
            'strategy_model_id' => null,
            'name' => 'Concurrency Source',
            'revision' => 1,
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);

        $service = app(ChecklistService::class);
        $staleEtag = $service->buildChecklistEtag($checklist);

        $firstUpdate = $this->withHeaders([
            'If-Match' => $staleEtag,
        ])->putJson("/api/checklists/{$checklist->id}", [
            'name' => 'Concurrency Update #1',
        ]);
        $firstUpdate->assertOk();

        $conflict = $this->withHeaders([
            'If-Match' => $staleEtag,
        ])->putJson("/api/checklists/{$checklist->id}", [
            'name' => 'Concurrency Update #2',
        ]);

        $conflict->assertStatus(409);
        $conflict->assertJsonPath('message', 'Checklist update conflict. The checklist has been modified by another request.');
        $conflict->assertJsonPath('current.revision', 2);
        $conflict->assertJsonPath('current.updated_at', Checklist::query()->findOrFail((int) $checklist->id)->updated_at?->toISOString());
    }

    public function test_update_returns_409_for_stale_revision_and_updated_at_payload(): void
    {
        $checklist = Checklist::query()->create([
            'user_id' => (int) $this->user->id,
            'scope' => 'global',
            'account_id' => null,
            'strategy_model_id' => null,
            'name' => 'Payload Conflict Source',
            'revision' => 1,
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);

        $staleRevision = (int) $checklist->revision;
        $staleUpdatedAt = $checklist->updated_at?->toISOString();

        $checklist->forceFill([
            'name' => 'Payload Conflict Updated',
            'revision' => $staleRevision + 1,
        ])->save();

        $conflict = $this->putJson("/api/checklists/{$checklist->id}", [
            'name' => 'Payload Conflict Final',
            'revision' => $staleRevision,
            'updated_at' => $staleUpdatedAt,
        ]);

        $conflict->assertStatus(409);
        $conflict->assertJsonPath('current.revision', $staleRevision + 1);
    }

    public function test_enforcement_mode_update_writes_audit_row(): void
    {
        $checklist = Checklist::query()->create([
            'user_id' => (int) $this->user->id,
            'scope' => 'global',
            'account_id' => null,
            'strategy_model_id' => null,
            'name' => 'Audit Source',
            'revision' => 1,
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);

        $etag = app(ChecklistService::class)->buildChecklistEtag($checklist);

        $response = $this->withHeaders([
            'If-Match' => $etag,
        ])->putJson("/api/checklists/{$checklist->id}", [
            'enforcement_mode' => 'strict',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('checklist_enforcement_mode_audits', [
            'checklist_id' => (int) $checklist->id,
            'checklist_user_id' => (int) $this->user->id,
            'changed_by_user_id' => (int) $this->user->id,
            'from_enforcement_mode' => 'soft',
            'to_enforcement_mode' => 'strict',
        ]);
    }

    private function createOwnedAccount(): Account
    {
        return Account::factory()->create([
            'user_id' => (int) $this->user->id,
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);
    }
}
