<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountRiskPolicy;
use App\Models\Instrument;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TradeExecutionOrchestratorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $instrumentId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('price_feed.provider', 'cache');

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

    public function test_leg_update_that_increases_risk_is_allowed_and_persists_changes(): void
    {
        $account = $this->createOwnedAccount();

        AccountRiskPolicy::factory()->create([
            'account_id' => (int) $account->id,
            'max_risk_per_trade_pct' => 1.0,
            'max_open_risk_pct' => 1.0,
            'max_daily_loss_pct' => 5.0,
            'max_total_drawdown_pct' => 10.0,
            'enforce_hard_limits' => true,
            'allow_override' => true,
        ]);

        $create = $this->withTradeIdempotencyKey()->postJson('/api/trades', $this->tradePayload((int) $account->id));
        $create->assertCreated();

        $tradeId = (int) $create->json('id');
        $entryLegId = (int) $create->json('legs.0.id');

        /** @var Trade $tradeBefore */
        $tradeBefore = Trade::query()->findOrFail($tradeId);
        /** @var TradeLeg $legBefore */
        $legBefore = TradeLeg::query()->findOrFail($entryLegId);

        $response = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trade-legs/{$entryLegId}", [
            'leg_type' => 'entry',
            'price' => 1.1000,
            'quantity_lots' => 5.0,
            'executed_at' => now()->subDay()->toIso8601String(),
            'fees' => 0,
            'notes' => 'attempted bypass mutation',
        ]);

        $response->assertOk();

        $tradeAfter = Trade::query()->findOrFail($tradeId);
        $legAfter = TradeLeg::query()->findOrFail($entryLegId);

        $legBeforeSnapshot = [
            'trade_id' => (int) $legBefore->trade_id,
            'leg_type' => (string) $legBefore->leg_type,
            'price' => (float) $legBefore->price,
            'quantity_lots' => (float) $legBefore->quantity_lots,
            'executed_at' => (string) $legBefore->executed_at,
            'fees' => (float) ($legBefore->fees ?? 0),
            'notes' => $legBefore->notes,
        ];
        $legAfterSnapshot = [
            'trade_id' => (int) $legAfter->trade_id,
            'leg_type' => (string) $legAfter->leg_type,
            'price' => (float) $legAfter->price,
            'quantity_lots' => (float) $legAfter->quantity_lots,
            'executed_at' => (string) $legAfter->executed_at,
            'fees' => (float) ($legAfter->fees ?? 0),
            'notes' => $legAfter->notes,
        ];

        $this->assertSame((int) $tradeBefore->revision + 1, (int) $tradeAfter->revision);
        $this->assertNotSame($legBeforeSnapshot, $legAfterSnapshot);
        $this->assertGreaterThan((float) $tradeBefore->risk_percent, (float) $tradeAfter->risk_percent);
        $this->assertEqualsWithDelta(5.0, (float) $legAfter->quantity_lots, 0.0001);

        $this->assertSame(2, (int) DB::table('trade_rule_executions')->where('trade_id', $tradeId)->count());
    }

    public function test_trader_cannot_enable_override_but_admin_can(): void
    {
        $traderAccount = $this->createOwnedAccount();

        $traderDenied = $this->putJson("/api/accounts/{$traderAccount->id}/risk-policy", [
            'allow_override' => true,
        ]);

        $traderDenied->assertStatus(403);
        $this->assertDatabaseMissing('account_risk_policies', [
            'account_id' => (int) $traderAccount->id,
            'allow_override' => 1,
        ]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $adminAccount = Account::factory()->create([
            'user_id' => (int) $admin->id,
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $adminAllowed = $this->putJson("/api/accounts/{$adminAccount->id}/risk-policy", [
            'allow_override' => true,
            'enforce_hard_limits' => true,
        ]);

        $adminAllowed->assertOk();
        $adminAllowed->assertJsonPath('allow_override', true);
    }

    public function test_every_successful_trade_write_creates_rule_execution_artifact(): void
    {
        $account = $this->createOwnedAccount();

        $create = $this->withTradeIdempotencyKey()->postJson('/api/trades', $this->tradePayload((int) $account->id));
        $create->assertCreated();

        $tradeId = (int) $create->json('id');
        $entryLegId = (int) $create->json('legs.0.id');

        $this->assertDatabaseCount('trade_rule_executions', 1);
        $this->assertDatabaseHas('trade_rule_executions', [
            'trade_id' => $tradeId,
            'decision' => 'pass',
        ]);

        $update = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trades/{$tradeId}", [
            'notes' => 'updated via orchestrator',
        ]);
        $update->assertOk();

        $this->assertSame(2, (int) DB::table('trade_rule_executions')->where('trade_id', $tradeId)->count());

        $mutateLeg = $this->withHeaders(['If-Match' => '2'])->putJson("/api/trade-legs/{$entryLegId}", [
            'leg_type' => 'entry',
            'price' => 1.1000,
            'quantity_lots' => 0.50,
            'executed_at' => now()->subDay()->toIso8601String(),
            'fees' => 1.25,
            'notes' => 'fee adjustment',
        ]);
        $mutateLeg->assertOk();

        $this->assertSame(3, (int) DB::table('trade_rule_executions')->where('trade_id', $tradeId)->count());
    }

    public function test_same_idempotency_key_returns_same_trade_without_duplication(): void
    {
        $account = $this->createOwnedAccount();
        $payload = $this->tradePayload((int) $account->id);
        $key = 'trade-create-'.Str::uuid()->toString();

        $first = $this->withHeaders([
            'Idempotency-Key' => $key,
        ])->postJson('/api/trades', $payload);
        $first->assertCreated();

        $second = $this->withHeaders([
            'Idempotency-Key' => $key,
        ])->postJson('/api/trades', $payload);
        $second->assertCreated();
        $second->assertHeader('X-Idempotent-Replay', 'true');
        $second->assertStatus($first->getStatusCode());

        $this->assertSame((int) $first->json('id'), (int) $second->json('id'));
        $this->assertDatabaseCount('trades', 1);
    }

    public function test_two_updates_with_same_revision_one_succeeds_and_second_conflicts_with_409(): void
    {
        $account = $this->createOwnedAccount();

        $create = $this->withTradeIdempotencyKey()->postJson('/api/trades', $this->tradePayload((int) $account->id));
        $create->assertCreated();
        $tradeId = (int) $create->json('id');

        $first = $this->withHeaders([
            'If-Match' => '1',
        ])->putJson("/api/trades/{$tradeId}", [
            'notes' => 'first writer',
        ]);
        $first->assertOk();

        $second = $this->withHeaders([
            'If-Match' => '1',
        ])->putJson("/api/trades/{$tradeId}", [
            'notes' => 'second stale writer',
        ]);

        $second->assertStatus(409);
        $second->assertJsonPath('current.revision', 2);
    }

    public function test_leg_mutation_requires_matching_revision(): void
    {
        $account = $this->createOwnedAccount();

        $create = $this->withTradeIdempotencyKey()->postJson('/api/trades', $this->tradePayload((int) $account->id));
        $create->assertCreated();
        $entryLegId = (int) $create->json('legs.0.id');

        $missingHeader = $this->putJson("/api/trade-legs/{$entryLegId}", [
            'leg_type' => 'entry',
            'price' => 1.1000,
            'quantity_lots' => 0.50,
            'executed_at' => now()->subDay()->toIso8601String(),
            'fees' => 0,
            'notes' => 'missing if-match',
        ]);
        $missingHeader->assertStatus(409);

        $staleHeader = $this->withHeaders([
            'If-Match' => '99',
        ])->putJson("/api/trade-legs/{$entryLegId}", [
            'leg_type' => 'entry',
            'price' => 1.1000,
            'quantity_lots' => 0.50,
            'executed_at' => now()->subDay()->toIso8601String(),
            'fees' => 0,
            'notes' => 'stale if-match',
        ]);
        $staleHeader->assertStatus(409);
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
            'stop_loss' => 1.0995,
            'take_profit' => 1.1020,
            'followed_rules' => true,
            'emotion' => 'calm',
            'close_date' => now()->subDay()->toIso8601String(),
            'session' => 'London',
            'strategy_model' => 'Breakout',
            'notes' => 'orchestrator enforcement test',
            'legs' => [
                [
                    'leg_type' => 'entry',
                    'price' => 1.1000,
                    'quantity_lots' => 0.50,
                    'executed_at' => now()->subDay()->toIso8601String(),
                    'fees' => 0,
                ],
                [
                    'leg_type' => 'exit',
                    'price' => 1.1010,
                    'quantity_lots' => 0.50,
                    'executed_at' => now()->subDay()->addMinute()->toIso8601String(),
                    'fees' => 0,
                ],
            ],
        ];
    }

    private function createOwnedAccount(): Account
    {
        return Account::factory()->create([
            'user_id' => (int) $this->user->id,
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function withTradeIdempotencyKey(): self
    {
        return $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }
}
