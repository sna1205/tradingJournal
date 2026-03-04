<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChecklistItemRuleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_explicit_rule_schema_and_normalizes_item_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $checklist = Checklist::query()->create([
            'user_id' => (int) $user->id,
            'name' => 'Schema Checklist',
            'scope' => 'global',
            'enforcement_mode' => 'strict',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/checklists/{$checklist->id}/items", [
            'title' => 'Risk cap',
            'type' => 'checkbox',
            'category' => 'Risk',
            'rule' => [
                'type' => 'auto_metric',
                'metric_key' => 'risk_percent',
                'operator' => '<=',
                'threshold' => 1,
                'required' => true,
            ],
            'config' => [],
            'is_active' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('type', 'number');
        $response->assertJsonPath('required', true);
        $response->assertJsonPath('config.rule.type', 'auto_metric');
        $response->assertJsonPath('config.rule.metric_key', 'risk_percent');
        $response->assertJsonPath('config.rule.operator', '<=');
        $response->assertJsonPath('config.rule.threshold', 1);
        $response->assertJsonPath('config.rule.required', true);
    }

    public function test_store_rejects_invalid_explicit_rule_schema(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $checklist = Checklist::query()->create([
            'user_id' => (int) $user->id,
            'name' => 'Invalid Schema Checklist',
            'scope' => 'global',
            'enforcement_mode' => 'strict',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/checklists/{$checklist->id}/items", [
            'title' => 'Boolean invalid operator',
            'type' => 'checkbox',
            'category' => 'Risk',
            'rule' => [
                'type' => 'boolean',
                'operator' => '>',
                'threshold' => true,
                'required' => true,
            ],
            'config' => [],
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rule.operator']);
    }

    public function test_store_allows_plain_number_item_without_comparator_threshold_config(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $checklist = Checklist::query()->create([
            'user_id' => (int) $user->id,
            'name' => 'Plain Number Checklist',
            'scope' => 'global',
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/checklists/{$checklist->id}/items", [
            'title' => 'Plan adherence score (%)',
            'type' => 'number',
            'required' => true,
            'category' => 'After Trading',
            'config' => [
                'min' => 0,
                'max' => 100,
                'step' => 5,
                'unit' => '%',
            ],
            'is_active' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('type', 'number');
        $response->assertJsonPath('config.min', 0);
        $response->assertJsonPath('config.max', 100);
        $response->assertJsonPath('config.step', 5);
    }
}
