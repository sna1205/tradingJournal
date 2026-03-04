<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountRiskPolicy;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstrumentMathGoldenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('price_feed.provider', 'cache');

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_eurusd_usd_account_golden_vector_uses_contract_size_not_seed_tick_value(): void
    {
        $account = $this->createAccount('USD');
        $instrument = $this->createInstrument([
            'symbol' => 'EURUSD',
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'contract_size' => 100000,
            'tick_size' => 0.00001,
            // Deliberately wrong: engine must use contract_size * tick_size path.
            'tick_value' => 9.99,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->baseTradePayload((int) $account->id, (int) $instrument->id, 'EURUSD'),
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1030,
            'actual_exit_price' => 1.1010,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('risk_currency', 'USD');

        $this->assertEqualsWithDelta(100.0, (float) $response->json('risk_amount_account_currency'), 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $response->json('monetary_risk'), 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $response->json('fx_rate_used'), 0.0000001);
        $this->assertSame('USDUSD', (string) $response->json('fx_pair_used'));
        $this->assertNotNull($response->json('fx_rate_provenance_at'));
    }

    public function test_usdjpy_usd_account_golden_vector(): void
    {
        FxRate::query()->create([
            'from_currency' => 'JPY',
            'to_currency' => 'USD',
            'rate' => 0.0066666667,
            'rate_updated_at' => now(),
        ]);

        $account = $this->createAccount('USD');
        $instrument = $this->createInstrument([
            'symbol' => 'USDJPY',
            'base_currency' => 'USD',
            'quote_currency' => 'JPY',
            'contract_size' => 100000,
            'tick_size' => 0.001,
            'tick_value' => 100,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->baseTradePayload((int) $account->id, (int) $instrument->id, 'USDJPY'),
            'entry_price' => 150.000,
            'stop_loss' => 149.800,
            'take_profit' => 150.600,
            'actual_exit_price' => 150.100,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('risk_currency', 'USD');

        $this->assertEqualsWithDelta(133.333334, (float) $response->json('risk_amount_account_currency'), 0.0002);
        $this->assertEqualsWithDelta(0.0066666667, (float) $response->json('fx_rate_used'), 0.0000001);
        $this->assertSame('JPYUSD', (string) $response->json('fx_pair_used'));
    }

    public function test_xauusd_golden_vector(): void
    {
        $account = $this->createAccount('USD');
        $instrument = $this->createInstrument([
            'symbol' => 'XAUUSD',
            'asset_class' => 'commodities',
            'base_currency' => 'XAU',
            'quote_currency' => 'USD',
            'contract_size' => 100,
            'tick_size' => 0.01,
            'tick_value' => 1,
            'pip_size' => 0.1,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->baseTradePayload((int) $account->id, (int) $instrument->id, 'XAUUSD'),
            'entry_price' => 2350.00,
            'stop_loss' => 2345.00,
            'take_profit' => 2360.00,
            'actual_exit_price' => 2353.00,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('risk_currency', 'USD');
        $this->assertEqualsWithDelta(500.0, (float) $response->json('risk_amount_account_currency'), 0.0001);
    }

    public function test_eurusd_jpy_account_golden_vector(): void
    {
        FxRate::query()->create([
            'from_currency' => 'USD',
            'to_currency' => 'JPY',
            'rate' => 150.0000000000,
            'rate_updated_at' => now(),
        ]);

        $account = $this->createAccount('JPY');
        $instrument = $this->createInstrument([
            'symbol' => 'EURUSD',
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'contract_size' => 100000,
            'tick_size' => 0.00001,
            'tick_value' => 1,
        ]);

        $response = $this->withTradeIdempotencyKey()->postJson('/api/trades', [
            ...$this->baseTradePayload((int) $account->id, (int) $instrument->id, 'EURUSD'),
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1030,
            'actual_exit_price' => 1.1010,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('risk_currency', 'JPY');

        $this->assertEqualsWithDelta(15000.0, (float) $response->json('risk_amount_account_currency'), 0.0001);
        $this->assertEqualsWithDelta(150.0, (float) $response->json('fx_rate_used'), 0.0000001);
        $this->assertSame('USDJPY', (string) $response->json('fx_pair_used'));
        $this->assertNotNull($response->json('fx_rate_provenance_at'));
    }

    /**
     * @return array<string,mixed>
     */
    private function baseTradePayload(int $accountId, int $instrumentId, string $symbol): array
    {
        return [
            'account_id' => $accountId,
            'instrument_id' => $instrumentId,
            'symbol' => $symbol,
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1030,
            'position_size' => 1.0,
            'actual_exit_price' => 1.1010,
            'followed_rules' => true,
            'emotion' => 'calm',
            'close_date' => now()->subDay()->toIso8601String(),
            'session' => 'London',
            'strategy_model' => 'Golden Vector',
            'notes' => 'instrument math golden vector',
        ];
    }

    private function createAccount(string $currency): Account
    {
        $account = Account::factory()->create([
            'user_id' => (int) $this->user->id,
            'starting_balance' => 10000,
            'current_balance' => 10000,
            'currency' => $currency,
            'is_active' => true,
        ]);

        AccountRiskPolicy::factory()->create([
            'account_id' => (int) $account->id,
            'max_risk_per_trade_pct' => 200.0,
            'max_open_risk_pct' => 200.0,
            'max_daily_loss_pct' => 200.0,
            'max_total_drawdown_pct' => 200.0,
            'enforce_hard_limits' => true,
            'allow_override' => false,
        ]);

        return $account;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function createInstrument(array $overrides): Instrument
    {
        return Instrument::query()->create([
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
            ...$overrides,
        ]);
    }

    private function withTradeIdempotencyKey(): self
    {
        return $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }
}
