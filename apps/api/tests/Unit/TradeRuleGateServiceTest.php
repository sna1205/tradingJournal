<?php

namespace Tests\Unit;

use App\Domain\TradeExecution\TradeRuleGateService;
use App\Models\Account;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TradeRuleGateServiceTest extends TestCase
{
    use RefreshDatabase;

    private TradeRuleGateService $service;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TradeRuleGateService::class);
        $this->user = User::factory()->create();
        $this->account = Account::factory()->create([
            'user_id' => (int) $this->user->id,
            'is_active' => true,
        ]);
    }

    public function test_gate_returns_missing_required_rule_for_strict_checklist_when_no_response_is_provided(): void
    {
        $checklist = $this->createChecklistWithRule('checkbox', []);
        $item = $checklist->items()->firstOrFail();

        $result = $this->service->evaluateChecklistGateForPayload(
            (int) $this->user->id,
            [
                'account_id' => (int) $this->account->id,
            ],
            null,
            [],
            false
        );

        $this->assertSame((int) $checklist->id, (int) $result['checklist']->id);
        $this->assertFalse((bool) ($result['readiness']['ready'] ?? true));
        $this->assertTrue((bool) $result['checklist_incomplete']);
        $this->assertSame([(int) $item->id], $result['failed_required_rule_ids'] ?? []);
    }

    public function test_gate_uses_server_context_metrics_for_auto_metric_rules(): void
    {
        $checklist = $this->createChecklistWithRule('number', [
            'auto_metric' => 'risk_percent',
            'comparator' => '<=',
            'threshold' => 0.5,
        ]);
        $item = $checklist->items()->firstOrFail();

        $result = $this->service->evaluateChecklistGateForPayload(
            (int) $this->user->id,
            [
                'account_id' => (int) $this->account->id,
                'risk_percent' => 0.9,
            ],
            null,
            [
                'checklist_responses' => [
                    [
                        'checklist_item_id' => (int) $item->id,
                        'value' => null,
                    ],
                ],
            ],
            false
        );

        $this->assertFalse((bool) ($result['readiness']['ready'] ?? true));
        $this->assertSame([(int) $item->id], $result['failed_required_rule_ids'] ?? []);
        $reason = (string) (($result['failed_rule_reasons'][0]['reason'] ?? ''));
        $this->assertStringContainsString('0.5', $reason);
    }

    public function test_strict_enforcement_invokes_handler_with_failed_rule_ids(): void
    {
        $checklist = $this->createChecklistWithRule('checkbox', []);
        $item = $checklist->items()->firstOrFail();
        $capturedIds = [];

        try {
            $this->service->evaluateChecklistGateForPayload(
                (int) $this->user->id,
                [
                    'account_id' => (int) $this->account->id,
                ],
                null,
                [],
                true,
                function (
                    ?Checklist $resolvedChecklist,
                    array $failingRules,
                    ?string $enforcementMode,
                    ?int $checklistVersion,
                    array $failedRequiredRuleIds
                ) use (&$capturedIds, $checklist): void {
                    $this->assertSame((int) $checklist->id, (int) ($resolvedChecklist?->id ?? 0));
                    $this->assertSame('strict', $enforcementMode);
                    $this->assertGreaterThanOrEqual(1, (int) $checklistVersion);
                    $this->assertNotEmpty($failingRules);
                    $capturedIds = $failedRequiredRuleIds;
                    throw new RuntimeException('strict-gate-blocked');
                }
            );
            $this->fail('Expected strict gate handler to interrupt flow.');
        } catch (RuntimeException $exception) {
            $this->assertSame('strict-gate-blocked', $exception->getMessage());
        }

        $this->assertSame([(int) $item->id], $capturedIds);
    }

    public function test_frozen_snapshot_without_resolvable_checklist_marks_gate_as_not_ready(): void
    {
        $trade = Trade::factory()->create([
            'account_id' => (int) $this->account->id,
            'executed_checklist_id' => null,
            'executed_checklist_version' => 4,
            'executed_enforcement_mode' => 'strict',
            'checklist_incomplete' => true,
        ]);

        $result = $this->service->evaluateChecklistGateForPayload(
            (int) $this->user->id,
            [
                'account_id' => (int) $this->account->id,
            ],
            $trade,
            [],
            false
        );

        $this->assertTrue((bool) $result['snapshot_frozen']);
        $this->assertTrue((bool) $result['checklist_incomplete']);
        $this->assertSame([0], $result['failed_required_rule_ids'] ?? []);
    }

    private function createChecklistWithRule(string $type, array $config): Checklist
    {
        $checklist = Checklist::query()->create([
            'user_id' => (int) $this->user->id,
            'account_id' => (int) $this->account->id,
            'strategy_model_id' => null,
            'name' => 'Gate Service Checklist',
            'scope' => 'account',
            'enforcement_mode' => 'strict',
            'is_active' => true,
            'revision' => 1,
        ]);

        ChecklistItem::query()->create([
            'checklist_id' => (int) $checklist->id,
            'order_index' => 0,
            'title' => 'Rule 1',
            'type' => $type,
            'required' => true,
            'category' => 'Risk',
            'help_text' => null,
            'config' => $config,
            'is_active' => true,
        ]);

        return $checklist->fresh(['items']);
    }
}
