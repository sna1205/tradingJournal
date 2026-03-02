<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountRiskPolicy;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\Trade;
use App\Models\TradeLeg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TradeValidationTest extends TestCase
{
    use RefreshDatabase;

    private int $eurusdInstrumentId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('price_feed.provider', 'cache');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->eurusdInstrumentId = (int) Instrument::query()->create([
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

    public function test_trade_creation_rejects_future_close_date(): void
    {
        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'close_date' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date']);
    }

    public function test_trade_creation_rejects_buy_stop_loss_above_entry(): void
    {
        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.1010,
            'take_profit' => 1.1100,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['stop_loss']);
    }

    public function test_trade_creation_rejects_sell_take_profit_above_entry(): void
    {
        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'direction' => 'sell',
            'entry_price' => 1.1000,
            'stop_loss' => 1.1050,
            'take_profit' => 1.1010,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['take_profit']);
    }

    public function test_trade_update_rejects_directional_rule_break_on_partial_update(): void
    {
        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $trade = Trade::factory()->create([
            'account_id' => $account->id,
            'instrument_id' => $this->eurusdInstrumentId,
            'pair' => 'EURUSD',
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0950,
            'take_profit' => 1.1200,
            'actual_exit_price' => 1.1100,
            'date' => now()->subDay(),
        ]);

        $response = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trades/{$trade->id}", [
            'stop_loss' => 1.1010,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['stop_loss']);
    }

    public function test_trade_precheck_blocks_when_risk_exceeds_policy(): void
    {
        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);
        AccountRiskPolicy::factory()->create([
            'account_id' => $account->id,
            'max_risk_per_trade_pct' => 0.5,
            'max_open_risk_pct' => 0.5,
            'max_daily_loss_pct' => 2.0,
            'max_total_drawdown_pct' => 5.0,
            'enforce_hard_limits' => true,
            'allow_override' => false,
        ]);

        $response = $this->postJson('/api/trades/precheck', [
            ...$this->tradePayload((int) $account->id),
            'entry_price' => 1.1000,
            'stop_loss' => 1.0900,
            'position_size' => 1.0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('allowed', false);
        $response->assertJsonPath('requires_override_reason', false);
    }

    public function test_trade_creation_requires_override_reason_when_policy_allows_override(): void
    {
        $this->user->forceFill(['role' => 'admin'])->save();

        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);
        AccountRiskPolicy::factory()->create([
            'account_id' => $account->id,
            'max_risk_per_trade_pct' => 0.5,
            'max_open_risk_pct' => 0.5,
            'max_daily_loss_pct' => 2.0,
            'max_total_drawdown_pct' => 5.0,
            'enforce_hard_limits' => true,
            'allow_override' => true,
        ]);

        $blocked = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'entry_price' => 1.1000,
            'stop_loss' => 1.0900,
            'position_size' => 1.0,
        ]);

        $blocked->assertStatus(422);
        $blocked->assertJsonValidationErrors(['risk_override_reason']);

        $allowed = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'entry_price' => 1.1000,
            'stop_loss' => 1.0900,
            'position_size' => 1.0,
            'risk_override_reason' => 'High conviction setup after planned review.',
        ]);

        $allowed->assertCreated();
    }

    public function test_trade_creation_accepts_multi_leg_payload_and_persists_legs(): void
    {
        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $payload = $this->tradePayload((int) $account->id);
        unset($payload['position_size'], $payload['actual_exit_price']);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$payload,
            'legs' => [
                [
                    'leg_type' => 'entry',
                    'price' => 1.1000,
                    'quantity_lots' => 1.0,
                    'executed_at' => now()->subDay()->toIso8601String(),
                    'fees' => 0,
                ],
                [
                    'leg_type' => 'exit',
                    'price' => 1.1010,
                    'quantity_lots' => 0.5,
                    'executed_at' => now()->subDay()->addMinute()->toIso8601String(),
                    'fees' => 0,
                ],
                [
                    'leg_type' => 'exit',
                    'price' => 1.1020,
                    'quantity_lots' => 0.5,
                    'executed_at' => now()->subDay()->addMinutes(2)->toIso8601String(),
                    'fees' => 0,
                ],
            ],
        ]);

        $response->assertCreated();
        $tradeId = (int) $response->json('id');
        $this->assertGreaterThan(0, $tradeId);
        $this->assertSame(3, TradeLeg::query()->where('trade_id', $tradeId)->count());
        $this->assertEqualsWithDelta(1.1015, (float) $response->json('avg_exit_price'), 0.0001);
    }

    public function test_trade_creation_rejects_lot_size_not_aligned_to_instrument_step(): void
    {
        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'position_size' => 0.015,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lot_size']);
    }

    public function test_trade_creation_converts_quote_risk_into_account_currency_when_rate_exists(): void
    {
        FxRate::query()->create([
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'rate' => 1.1000000000,
            'rate_updated_at' => now(),
        ]);

        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'position_size' => 1.0,
            'actual_exit_price' => 1.1005,
        ]);

        $response->assertCreated();
        $trade = Trade::query()->findOrFail((int) $response->json('id'));

        $this->assertEqualsWithDelta(90.909091, (float) $trade->monetary_risk, 0.0002);
        $this->assertEqualsWithDelta(90.909091, (float) $trade->risk_amount_account_currency, 0.0002);
        $this->assertSame('EUR', (string) $trade->risk_currency);
        $this->assertEqualsWithDelta(0.9091, (float) $trade->risk_percent, 0.0002);
        $this->assertEqualsWithDelta(1.0, (float) ($trade->fx_rate_quote_to_usd ?? 0), 0.0000001);
        $this->assertEqualsWithDelta(0.9090909091, (float) ($trade->fx_rate_used ?? 0), 0.0000001);
        $this->assertSame('EURUSD', (string) ($trade->fx_pair_used ?? ''));
        $this->assertNotNull($trade->fx_rate_provenance_at);
    }

    public function test_trade_creation_rejects_when_quote_to_account_fx_rate_is_missing(): void
    {
        $account = $this->createOwnedAccount([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'currency' => 'JPY',
            'is_active' => true,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', $this->tradePayload((int) $account->id));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['instrument_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function tradePayload(int $accountId): array
    {
        return [
            'account_id' => $accountId,
            'instrument_id' => $this->eurusdInstrumentId,
            'symbol' => 'EURUSD',
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0995,
            'take_profit' => 1.1015,
            'position_size' => 1.0,
            'actual_exit_price' => 1.1100,
            'followed_rules' => true,
            'emotion' => 'calm',
            'close_date' => now()->subDay()->toIso8601String(),
            'session' => 'London',
            'strategy_model' => 'Breakout',
            'notes' => 'Validation test trade',
        ];
    }

    private function createOwnedAccount(array $attributes = []): Account
    {
        return Account::factory()->create([
            'user_id' => $this->user->id,
            ...$attributes,
        ]);
    }

    private function withTradeIdempotencyKey(): self
    {
        return $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }
}
