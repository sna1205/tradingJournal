<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Trade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_trade_creation_rejects_future_close_date(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'close_date' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date']);
    }

    public function test_trade_creation_rejects_buy_stop_loss_above_entry(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/trades', [
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
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/trades', [
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
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $trade = Trade::factory()->create([
            'account_id' => $account->id,
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0950,
            'take_profit' => 1.1200,
            'actual_exit_price' => 1.1100,
            'date' => now()->subDay(),
        ]);

        $response = $this->putJson("/api/trades/{$trade->id}", [
            'stop_loss' => 1.1010,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['stop_loss']);
    }

    /**
     * @return array<string, mixed>
     */
    private function tradePayload(int $accountId): array
    {
        return [
            'account_id' => $accountId,
            'symbol' => 'EURUSD',
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0950,
            'take_profit' => 1.1150,
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
}

