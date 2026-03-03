<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Instrument;
use App\Models\StrategyModel;
use App\Models\Trade;
use App\Models\User;
use App\Services\ChecklistService;
use App\Services\TradeChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class TradeChecklistEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $instrumentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->instrumentId = (int) Instrument::query()->create([
            'symbol' => 'EURUSD',
            'asset_class' => 'forex',
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'contract_size' => 100000,
            'tick_size' => 0.00001,
            'tick_value' => 1,
            'pip_size' => 0.0001,
            'min_lot' => 0.01,
            'lot_step' => 0.01,
            'is_active' => true,
        ])->id;
    }

    public function test_trade_store_returns_422_when_strict_required_rules_are_missing(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule('global', null, null);
        $requiredItem = $checklist->items()->firstOrFail();

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', $this->tradePayload((int) $account->id));

        $response->assertStatus(422);
        $response->assertJsonPath('checklist.id', (int) $checklist->id);
        $response->assertJsonPath('failing_rules.0.checklist_item_id', (int) $requiredItem->id);
        $this->assertDatabaseCount('trades', 0);
    }

    public function test_precheck_and_store_share_same_strict_gate_decision_for_missing_required_rule(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule('account', (int) $account->id, null);
        $requiredItem = $checklist->items()->firstOrFail();
        $payload = $this->tradePayload((int) $account->id);

        $precheck = $this->postJson('/api/trades/precheck', $payload);
        $precheck->assertOk();
        $precheck->assertJsonPath('checklist_gate.checklist_incomplete', true);
        $precheck->assertJsonPath('checklist_gate.failed_required_rule_ids.0', (int) $requiredItem->id);

        $store = $this->withTradeIdempotencyKey()->postJson('/api/trades', $payload);
        $store->assertStatus(422);
        $store->assertJsonPath('failed_required_rule_ids.0', (int) $requiredItem->id);
        $this->assertDatabaseCount('trades', 0);
    }

    public function test_trade_update_returns_422_when_strict_required_rules_are_missing(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule('account', (int) $account->id, null);
        $requiredItem = $checklist->items()->firstOrFail();

        $trade = Trade::factory()->create([
            'account_id' => (int) $account->id,
            'instrument_id' => $this->instrumentId,
            'pair' => 'EURUSD',
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0998,
            'take_profit' => 1.1020,
            'actual_exit_price' => 1.1010,
            'lot_size' => 0.10,
            'date' => now()->subDay(),
            'followed_rules' => true,
            'emotion' => 'calm',
        ]);

        $response = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trades/{$trade->id}", [
            'notes' => 'Updated note',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('checklist.id', (int) $checklist->id);
        $response->assertJsonPath('failing_rules.0.checklist_item_id', (int) $requiredItem->id);
        $trade->refresh();
        $this->assertNotSame('Updated note', $trade->notes);
    }

    public function test_strict_mode_with_valid_responses_persists_trade_and_responses_together(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule('account', (int) $account->id, null);
        $requiredItem = $checklist->items()->firstOrFail();

        $payload = $this->tradePayload((int) $account->id);
        $payload['checklist_responses'] = [
            ['item_id' => (int) $requiredItem->id, 'value' => true],
        ];

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', $payload);
        $response->assertCreated();

        $tradeId = (int) $response->json('id');
        $this->assertGreaterThan(0, $tradeId);
        $this->assertDatabaseHas('trades', ['id' => $tradeId]);
        $this->assertDatabaseHas('trades', [
            'id' => $tradeId,
            'executed_checklist_id' => (int) $checklist->id,
            'executed_checklist_version' => (int) $checklist->revision,
            'executed_enforcement_mode' => 'strict',
        ]);
        $this->assertDatabaseHas('trade_checklist_responses', [
            'trade_id' => $tradeId,
            'checklist_item_id' => (int) $requiredItem->id,
            'checklist_id' => (int) $checklist->id,
            'is_completed' => 1,
        ]);
    }

    public function test_historical_trade_read_uses_frozen_snapshot_after_checklist_revision_changes(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule('account', (int) $account->id, null);
        $requiredItem = $checklist->items()->firstOrFail();

        $payload = $this->tradePayload((int) $account->id);
        $payload['checklist_responses'] = [
            ['checklist_item_id' => (int) $requiredItem->id, 'value' => true],
        ];

        $created = $this->withTradeIdempotencyKey()->postJson('/api/trades', $payload);
        $created->assertCreated();
        $tradeId = (int) $created->json('id');

        $trade = Trade::query()->findOrFail($tradeId);
        $this->assertNotNull($trade->executed_checklist_version);
        $frozenVersion = (int) $trade->executed_checklist_version;

        $service = app(ChecklistService::class);
        $service->updateChecklist($checklist->fresh(), [
            'name' => 'Checklist v2',
            'scope' => 'account',
            'account_id' => (int) $account->id,
            'enforcement_mode' => 'strict',
            'is_active' => true,
        ]);

        $freshChecklist = Checklist::query()->findOrFail((int) $checklist->id);
        $this->assertGreaterThan($frozenVersion, (int) $freshChecklist->revision);

        $read = $this->getJson("/api/trades/{$tradeId}/checklist-responses");
        $read->assertOk();
        $read->assertJsonPath('execution_snapshot.frozen', true);
        $read->assertJsonPath('execution_snapshot.legacy_unfrozen', false);
        $read->assertJsonPath('execution_snapshot.executed_checklist_id', (int) $checklist->id);
        $read->assertJsonPath('execution_snapshot.executed_checklist_version', $frozenVersion);
        $read->assertJsonPath('responses.checklist.id', (int) $checklist->id);
        $read->assertJsonPath('responses.checklist.revision', $frozenVersion);
    }

    public function test_trade_create_rolls_back_when_checklist_response_upsert_fails(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule('account', (int) $account->id, null);
        $requiredItem = $checklist->items()->firstOrFail();

        $mock = Mockery::mock(TradeChecklistService::class)->makePartial();
        $mock->shouldReceive('evaluateDraftReadiness')
            ->once()
            ->andReturn([
                'readiness' => [
                    'status' => 'ready',
                    'completed_required' => 1,
                    'total_required' => 1,
                    'missing_required' => [],
                    'ready' => true,
                ],
                'failing_rules' => [],
            ]);
        $mock->shouldReceive('upsertResponses')
            ->once()
            ->andThrow(new \RuntimeException('Simulated checklist response insert failure'));
        $this->app->instance(TradeChecklistService::class, $mock);

        $payload = $this->tradePayload((int) $account->id);
        $payload['checklist_responses'] = [
            ['checklist_item_id' => (int) $requiredItem->id, 'value' => true],
        ];

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', $payload);
        $response->assertStatus(500);
        $this->assertDatabaseCount('trades', 0);
        $this->assertDatabaseCount('trade_checklist_responses', 0);
    }

    public function test_legacy_trade_read_is_marked_unfrozen_when_execution_snapshot_is_missing(): void
    {
        $account = $this->createOwnedAccount();
        $trade = Trade::factory()->create([
            'account_id' => (int) $account->id,
            'instrument_id' => $this->instrumentId,
            'pair' => 'EURUSD',
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1020,
            'actual_exit_price' => 1.1010,
            'lot_size' => 0.10,
            'date' => now()->subDay(),
            'followed_rules' => true,
            'emotion' => 'calm',
            'executed_checklist_id' => null,
            'executed_checklist_version' => null,
            'executed_enforcement_mode' => null,
            'failed_rule_ids' => null,
            'failed_rule_titles' => null,
            'check_evaluated_at' => null,
        ]);

        $response = $this->getJson("/api/trades/{$trade->id}/checklist-responses");
        $response->assertOk();
        $response->assertJsonPath('execution_snapshot.frozen', false);
        $response->assertJsonPath('execution_snapshot.legacy_unfrozen', true);
    }

    public function test_strategy_scope_resolver_wins_over_account_and_global_with_deterministic_selection(): void
    {
        $account = $this->createOwnedAccount();
        $strategyModelId = (int) StrategyModel::query()->value('id');
        $this->assertGreaterThan(0, $strategyModelId);

        $this->createChecklistWithRequiredRule('global', null, null);
        $this->createChecklistWithRequiredRule('account', (int) $account->id, null);
        $firstStrategyChecklist = $this->createChecklistWithRequiredRule('strategy', null, $strategyModelId);
        $firstStrategyChecklist->forceFill(['is_active' => false])->save();
        $secondStrategyChecklist = $this->createChecklistWithRequiredRule('strategy', null, $strategyModelId);

        $response = $this->getJson('/api/trade-rules/resolve?'.http_build_query([
            'account_id' => (int) $account->id,
            'strategy_model_id' => $strategyModelId,
        ]));

        $response->assertOk();
        $response->assertJsonPath('context.resolved_scope', 'strategy');
        $response->assertJsonPath('responses.checklist.id', (int) $secondStrategyChecklist->id);
        $response->assertJsonPath('context.resolved_checklist_id', (int) $secondStrategyChecklist->id);
    }

    public function test_strict_auto_metric_rule_failure_returns_422_and_does_not_create_trade(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule(
            'account',
            (int) $account->id,
            null,
            'strict',
            'number',
            [
                'auto_metric' => 'risk_percent',
                'comparator' => '<=',
                'threshold' => 0.0001,
            ]
        );
        $requiredItem = $checklist->items()->firstOrFail();

        $payload = $this->tradePayload((int) $account->id);
        $payload['checklist_responses'] = [
            ['checklist_item_id' => (int) $requiredItem->id, 'value' => null],
        ];

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', $payload);
        $response->assertStatus(422);
        $response->assertJsonPath('failed_required_rule_ids.0', (int) $requiredItem->id);
        $response->assertJsonPath('failed_rule_reasons.0.checklist_item_id', (int) $requiredItem->id);
        $this->assertDatabaseCount('trades', 0);
    }

    public function test_strict_auto_metric_rule_cannot_be_bypassed_by_precheck_snapshot_metrics(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule(
            'account',
            (int) $account->id,
            null,
            'strict',
            'number',
            [
                'auto_metric' => 'risk_percent',
                'comparator' => '<=',
                'threshold' => 0.0001,
            ]
        );
        $requiredItem = $checklist->items()->firstOrFail();

        $payload = $this->tradePayload((int) $account->id);
        $payload['checklist_responses'] = [
            ['checklist_item_id' => (int) $requiredItem->id, 'value' => null],
        ];
        $payload['precheck_snapshot'] = [
            'risk_percent' => 0.0,
            'monetary_risk' => 0.0,
            'lot_size' => 0.0001,
            'pip_value' => 0.0,
        ];

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', $payload);
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Checklist strict validation failed.');
        $response->assertJsonPath('failed_required_rule_ids.0', (int) $requiredItem->id);
        $response->assertJsonPath('failed_rule_reasons.0.checklist_item_id', (int) $requiredItem->id);
        $this->assertStringContainsString('0.0001', (string) $response->json('failed_rule_reasons.0.reason'));
        $this->assertDatabaseCount('trades', 0);
    }

    public function test_soft_auto_metric_rule_failure_allows_save_and_returns_failed_rule_payload(): void
    {
        $account = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule(
            'account',
            (int) $account->id,
            null,
            'soft',
            'number',
            [
                'auto_metric' => 'risk_percent',
                'comparator' => '<=',
                'threshold' => 0.0001,
            ]
        );
        $requiredItem = $checklist->items()->firstOrFail();

        $payload = $this->tradePayload((int) $account->id);
        $payload['checklist_responses'] = [
            ['checklist_item_id' => (int) $requiredItem->id, 'value' => null],
        ];

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', $payload);
        $response->assertCreated();
        $tradeId = (int) $response->json('id');
        $this->assertGreaterThan(0, $tradeId);

        $response->assertJsonPath('checklist_evaluation.failed_required_rule_ids.0', (int) $requiredItem->id);
        $response->assertJsonPath('checklist_evaluation.failed_rule_reasons.0.checklist_item_id', (int) $requiredItem->id);
        $this->assertDatabaseHas('trades', [
            'id' => $tradeId,
            'checklist_incomplete' => 1,
            'executed_enforcement_mode' => 'soft',
        ]);
    }

    public function test_trade_checklist_response_upsert_rejects_account_context_mismatch(): void
    {
        $accountA = $this->createOwnedAccount();
        $accountB = $this->createOwnedAccount();
        $checklist = $this->createChecklistWithRequiredRule('account', (int) $accountA->id, null);
        $requiredItem = $checklist->items()->firstOrFail();

        $trade = Trade::factory()->create([
            'account_id' => (int) $accountA->id,
            'instrument_id' => $this->instrumentId,
            'pair' => 'EURUSD',
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1020,
            'actual_exit_price' => 1.1010,
            'lot_size' => 0.10,
            'date' => now()->subDay(),
            'followed_rules' => true,
            'emotion' => 'calm',
        ]);

        $response = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trades/{$trade->id}/checklist-responses", [
            'account_id' => (int) $accountB->id,
            'responses' => [
                [
                    'checklist_item_id' => (int) $requiredItem->id,
                    'value' => true,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['account_id']);
    }

    private function createOwnedAccount(): Account
    {
        return Account::factory()->create([
            'user_id' => $this->user->id,
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);
    }

    private function withTradeIdempotencyKey(): self
    {
        return $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    private function createChecklistWithRequiredRule(
        string $scope,
        ?int $accountId,
        ?int $strategyModelId,
        string $enforcementMode = 'strict',
        string $type = 'checkbox',
        array $config = []
    ): Checklist {
        $checklist = Checklist::query()->create([
            'user_id' => $this->user->id,
            'account_id' => $scope === 'account' ? $accountId : null,
            'strategy_model_id' => $scope === 'strategy' ? $strategyModelId : null,
            'name' => ucfirst($scope).' Checklist '.Str::uuid()->toString(),
            'scope' => $scope,
            'enforcement_mode' => $enforcementMode,
            'is_active' => true,
        ]);

        ChecklistItem::query()->create([
            'checklist_id' => (int) $checklist->id,
            'order_index' => 0,
            'title' => 'Required rule',
            'type' => $type,
            'required' => true,
            'category' => 'Risk',
            'help_text' => null,
            'config' => $config,
            'is_active' => true,
        ]);

        return $checklist->fresh(['items']);
    }

    /**
     * @return array<string,mixed>
     */
    private function tradePayload(int $accountId): array
    {
        return [
            'account_id' => $accountId,
            'instrument_id' => $this->instrumentId,
            'symbol' => 'EURUSD',
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1020,
            'position_size' => 0.10,
            'actual_exit_price' => 1.1010,
            'followed_rules' => true,
            'emotion' => 'calm',
            'close_date' => now()->subDay()->toIso8601String(),
            'session' => 'London',
            'strategy_model' => 'General',
            'notes' => 'Checklist gate test trade',
        ];
    }
}
