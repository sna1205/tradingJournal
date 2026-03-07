<?php

namespace Tests\Unit;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\TradeChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeChecklistServiceEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private TradeChecklistService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TradeChecklistService::class);
        $this->user = User::factory()->create();
    }

    /**
     * @dataProvider comparatorProvider
     */
    public function test_numeric_comparators_are_evaluated_correctly(
        string $comparator,
        array $config,
        float $value,
        bool $expectedPass
    ): void {
        $item = $this->createItemWithConfig('number', [
            ...$config,
            'comparator' => $comparator,
        ]);

        $result = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => $value,
            ]]
        );

        if ($expectedPass) {
            $this->assertTrue((bool) ($result['readiness']['ready'] ?? false));
            $this->assertSame([], $result['failed_required_rule_ids'] ?? []);
        } else {
            $this->assertFalse((bool) ($result['readiness']['ready'] ?? true));
            $this->assertSame([(int) $item->id], $result['failed_required_rule_ids'] ?? []);
            $this->assertNotEmpty($result['failed_rule_reasons'] ?? []);
        }
    }

    public static function comparatorProvider(): array
    {
        return [
            'greater-than-pass' => ['>', ['threshold' => 1.5], 2.0, true],
            'greater-than-fail' => ['>', ['threshold' => 1.5], 1.0, false],
            'greater-or-equal-pass' => ['>=', ['threshold' => 2], 2.0, true],
            'greater-or-equal-fail' => ['>=', ['threshold' => 2], 1.99, false],
            'less-than-pass' => ['<', ['threshold' => 2], 1.5, true],
            'less-than-fail' => ['<', ['threshold' => 2], 2.0, false],
            'less-or-equal-pass' => ['<=', ['threshold' => 2], 2.0, true],
            'less-or-equal-fail' => ['<=', ['threshold' => 2], 2.1, false],
            'equals-pass' => ['equals', ['threshold' => 2], 2.0, true],
            'equals-fail' => ['equals', ['threshold' => 2], 2.1, false],
            'between-pass' => ['between', ['threshold_min' => 1, 'threshold_max' => 3], 2.0, true],
            'between-fail' => ['between', ['threshold_min' => 1, 'threshold_max' => 3], 4.0, false],
        ];
    }

    public function test_numeric_bounds_block_zero_and_negative_by_default(): void
    {
        $item = $this->createItemWithConfig('number', [
            'comparator' => '>=',
            'threshold' => 0,
        ]);

        $result = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => 0,
            ]]
        );

        $this->assertFalse((bool) ($result['readiness']['ready'] ?? true));
        $this->assertSame([(int) $item->id], $result['failed_required_rule_ids'] ?? []);
    }

    public function test_numeric_bounds_allow_zero_when_explicitly_configured(): void
    {
        $item = $this->createItemWithConfig('number', [
            'comparator' => '>=',
            'threshold' => 0,
            'allow_zero' => true,
            'min' => 0,
        ]);

        $result = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => 0,
            ]]
        );

        $this->assertTrue((bool) ($result['readiness']['ready'] ?? false));
        $this->assertSame([], $result['failed_required_rule_ids'] ?? []);
    }

    public function test_select_rule_must_match_allowed_option_key(): void
    {
        $item = $this->createItemWithConfig('dropdown', [
            'options' => ['A', 'B', 'C'],
        ]);

        $result = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => 'Z',
            ]]
        );

        $this->assertFalse((bool) ($result['readiness']['ready'] ?? true));
        $this->assertSame([(int) $item->id], $result['failed_required_rule_ids'] ?? []);
    }

    public function test_auto_metric_rules_use_context_metrics(): void
    {
        $item = $this->createItemWithConfig('number', [
            'comparator' => '<=',
            'threshold' => 1.0,
            'auto_metric' => 'risk_percent',
        ]);

        $passResult = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => null,
            ]],
            true,
            ['risk_percent' => 0.8]
        );
        $this->assertTrue((bool) ($passResult['readiness']['ready'] ?? false));

        $failResult = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => null,
            ]],
            true,
            ['risk_percent' => 1.8]
        );
        $this->assertFalse((bool) ($failResult['readiness']['ready'] ?? true));
        $this->assertSame([(int) $item->id], $failResult['failed_required_rule_ids'] ?? []);
    }

    public function test_missing_numeric_comparator_and_threshold_reports_validation_error(): void
    {
        $item = $this->createItemWithConfig('number', []);

        $result = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => 1,
            ]]
        );

        $this->assertFalse((bool) ($result['readiness']['ready'] ?? true));
        $this->assertSame([(int) $item->id], $result['failed_required_rule_ids'] ?? []);
        $reasons = $result['failed_rule_reasons'] ?? [];
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('comparator', strtolower((string) ($reasons[0]['reason'] ?? '')));
    }

    public function test_explicit_numeric_rule_schema_is_evaluated(): void
    {
        $item = $this->createItemWithConfig('number', [
            'rule' => [
                'type' => 'numeric',
                'operator' => '<=',
                'threshold' => 1.0,
                'required' => true,
            ],
        ]);

        $passResult = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => 0.9,
            ]]
        );
        $this->assertTrue((bool) ($passResult['readiness']['ready'] ?? false));

        $failResult = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => 1.5,
            ]]
        );
        $this->assertFalse((bool) ($failResult['readiness']['ready'] ?? true));
        $this->assertSame([(int) $item->id], $failResult['failed_required_rule_ids'] ?? []);
    }

    public function test_explicit_select_rule_not_in_operator_blocks_disallowed_values(): void
    {
        $item = $this->createItemWithConfig('dropdown', [
            'rule' => [
                'type' => 'select',
                'operator' => 'not_in',
                'threshold' => ['blocked'],
                'required' => true,
            ],
        ]);

        $passResult = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => 'allowed',
            ]]
        );
        $this->assertTrue((bool) ($passResult['readiness']['ready'] ?? false));

        $failResult = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => 'blocked',
            ]]
        );
        $this->assertFalse((bool) ($failResult['readiness']['ready'] ?? true));
        $this->assertSame([(int) $item->id], $failResult['failed_required_rule_ids'] ?? []);
    }

    public function test_explicit_auto_metric_rule_uses_metric_key(): void
    {
        $item = $this->createItemWithConfig('number', [
            'rule' => [
                'type' => 'auto_metric',
                'metric_key' => 'risk_percent',
                'operator' => '<=',
                'threshold' => 0.8,
                'required' => true,
            ],
        ]);

        $passResult = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => null,
            ]],
            true,
            ['risk_percent' => 0.5]
        );
        $this->assertTrue((bool) ($passResult['readiness']['ready'] ?? false));

        $failResult = $this->service->evaluateDraftReadiness(
            $item->checklist,
            [[
                'checklist_item_id' => (int) $item->id,
                'value' => null,
            ]],
            true,
            ['risk_percent' => 1.2]
        );
        $this->assertFalse((bool) ($failResult['readiness']['ready'] ?? true));
        $this->assertSame([(int) $item->id], $failResult['failed_required_rule_ids'] ?? []);
    }

    private function createItemWithConfig(string $type, array $config): ChecklistItem
    {
        $checklist = Checklist::query()->create([
            'user_id' => (int) $this->user->id,
            'name' => 'Evaluator Checklist',
            'scope' => 'global',
            'enforcement_mode' => 'strict',
            'is_active' => true,
        ]);

        return ChecklistItem::query()->create([
            'checklist_id' => (int) $checklist->id,
            'order_index' => 0,
            'title' => 'Rule under test',
            'type' => $type,
            'required' => true,
            'category' => 'Risk',
            'help_text' => null,
            'config' => $config,
            'is_active' => true,
        ])->fresh();
    }
}
