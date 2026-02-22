<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Instrument;
use App\Models\Trade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private int $eurusdInstrumentId;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_trade_creation_requires_account_id(): void
    {
        $payload = $this->tradePayload(1);
        unset($payload['account_id']);

        $response = $this->postJson('/api/trades', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['account_id']);
    }

    public function test_trade_is_assigned_to_an_account_and_balance_is_synced(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/trades', $this->tradePayload((int) $account->id));
        $response->assertCreated();

        $trade = Trade::query()->findOrFail((int) $response->json('id'));
        $this->assertSame((int) $account->id, (int) $trade->account_id);

        $account->refresh();
        $expectedBalance = round(((float) $account->starting_balance) + ((float) $trade->profit_loss), 2);
        $this->assertSame($expectedBalance, (float) $account->current_balance);
        $this->assertSame(10000.0, (float) $trade->account_balance_before_trade);
    }

    public function test_trade_edit_and_delete_rebuild_account_balance_safely(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $tradeA = $this->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'close_date' => '2026-01-02T10:00:00Z',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1020,
            'actual_exit_price' => 1.1010,
            'position_size' => 0.2,
        ])->assertCreated()->json();

        $tradeB = $this->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'close_date' => '2026-01-03T10:00:00Z',
            'entry_price' => 1.2000,
            'stop_loss' => 1.1990,
            'take_profit' => 1.2020,
            'actual_exit_price' => 1.1995,
            'position_size' => 0.2,
        ])->assertCreated()->json();

        $this->putJson("/api/trades/{$tradeA['id']}", [
            'actual_exit_price' => 1.1020,
        ])->assertOk();

        $second = Trade::query()->findOrFail((int) $tradeB['id']);
        $this->assertSame(10040.0, (float) $second->account_balance_before_trade);

        $this->deleteJson("/api/trades/{$tradeB['id']}")->assertNoContent();

        $account->refresh();
        $this->assertSame(10040.0, (float) $account->current_balance);
    }

    public function test_portfolio_analytics_can_scope_to_a_single_account(): void
    {
        $accountA = Account::factory()->create([
            'starting_balance' => 10000,
            'current_balance' => 10000,
            'is_active' => true,
        ]);
        $accountB = Account::factory()->create([
            'starting_balance' => 5000,
            'current_balance' => 5000,
            'is_active' => true,
        ]);

        Trade::factory()->create([
            'account_id' => $accountA->id,
            'profit_loss' => 150.00,
            'date' => '2026-01-05 10:00:00',
        ]);
        Trade::factory()->create([
            'account_id' => $accountB->id,
            'profit_loss' => 700.00,
            'date' => '2026-01-05 11:00:00',
        ]);

        $response = $this->getJson("/api/portfolio/analytics?account_ids={$accountA->id}");
        $response->assertOk();

        $this->assertSame(10000.0, (float) $response->json('portfolio_equity.starting_balance'));
        $this->assertSame(10150.0, (float) $response->json('portfolio_equity.current_equity'));
        $this->assertSame(150.0, (float) $response->json('portfolio_equity.net_profit'));
    }

    public function test_accounts_analytics_returns_per_account_rows(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 12000,
            'current_balance' => 12000,
            'is_active' => true,
        ]);

        Trade::factory()->count(2)->create([
            'account_id' => $account->id,
            'profit_loss' => 100.0,
            'date' => '2026-01-10 09:00:00',
        ]);

        $response = $this->getJson("/api/analytics/accounts?account_ids={$account->id}");
        $response->assertOk();
        $response->assertJsonPath('accounts.0.account_id', $account->id);
        $response->assertJsonPath('accounts.0.total_trades', 2);
    }

    public function test_account_specific_analytics_endpoint_returns_key_metrics(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $this->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1020,
            'actual_exit_price' => 1.1010,
            'position_size' => 0.2,
            'close_date' => '2026-01-02T10:00:00Z',
        ])->assertCreated();

        $this->postJson('/api/trades', [
            ...$this->tradePayload((int) $account->id),
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1020,
            'actual_exit_price' => 1.0990,
            'position_size' => 0.2,
            'close_date' => '2026-01-03T10:00:00Z',
        ])->assertCreated();

        $response = $this->getJson("/api/accounts/{$account->id}/analytics");
        $response->assertOk();
        $response->assertJsonStructure([
            'account_id',
            'win_rate',
            'profit_factor',
            'expectancy',
            'max_drawdown',
            'recovery_factor',
            'average_r',
            'longest_streak' => ['type', 'length'],
            'longest_win_streak',
            'longest_loss_streak',
        ]);
    }

    public function test_account_challenge_endpoints_support_get_put_and_live_status(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $this->getJson("/api/accounts/{$account->id}/challenge")
            ->assertOk()
            ->assertJsonPath('account_id', $account->id)
            ->assertJsonPath('starting_balance', '10000.00');

        $this->putJson("/api/accounts/{$account->id}/challenge", [
            'provider' => 'FTMO',
            'phase' => 'Phase 1',
            'starting_balance' => 10000,
            'profit_target_pct' => 10,
            'max_daily_loss_pct' => 5,
            'max_total_drawdown_pct' => 10,
            'min_trading_days' => 2,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ])->assertOk();

        Trade::factory()->create([
            'account_id' => $account->id,
            'instrument_id' => $this->eurusdInstrumentId,
            'pair' => 'EURUSD',
            'profit_loss' => 400,
            'date' => '2026-01-02 10:00:00',
        ]);
        Trade::factory()->create([
            'account_id' => $account->id,
            'instrument_id' => $this->eurusdInstrumentId,
            'pair' => 'EURUSD',
            'profit_loss' => 300,
            'date' => '2026-01-03 10:00:00',
        ]);
        Trade::factory()->create([
            'account_id' => $account->id,
            'instrument_id' => $this->eurusdInstrumentId,
            'pair' => 'EURUSD',
            'profit_loss' => 350,
            'date' => '2026-01-04 10:00:00',
        ]);

        $statusResponse = $this->getJson("/api/accounts/{$account->id}/challenge-status");
        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('risk_state', 'pass');
        $statusResponse->assertJsonPath('status', 'passed');
        $statusResponse->assertJsonPath('target_progress.met', true);
        $statusResponse->assertJsonPath('min_days_progress.met', true);
    }

    public function test_account_challenge_status_marks_fail_on_daily_loss_breach(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $this->putJson("/api/accounts/{$account->id}/challenge", [
            'provider' => 'FTMO',
            'phase' => 'Phase 1',
            'starting_balance' => 10000,
            'profit_target_pct' => 10,
            'max_daily_loss_pct' => 5,
            'max_total_drawdown_pct' => 10,
            'min_trading_days' => 2,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ])->assertOk();

        Trade::factory()->create([
            'account_id' => $account->id,
            'instrument_id' => $this->eurusdInstrumentId,
            'pair' => 'EURUSD',
            'profit_loss' => -600,
            'date' => '2026-01-02 10:00:00',
        ]);

        $statusResponse = $this->getJson("/api/accounts/{$account->id}/challenge-status");
        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('risk_state', 'fail');
        $statusResponse->assertJsonPath('status', 'failed');
        $statusResponse->assertJsonPath('daily_loss_headroom.breached', true);
    }

    public function test_analytics_overview_and_metrics_contracts_are_truthful(): void
    {
        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        Trade::factory()->create([
            'account_id' => $account->id,
            'instrument_id' => $this->eurusdInstrumentId,
            'pair' => 'EURUSD',
            'profit_loss' => 0,
            'r_multiple' => null,
            'rr' => 3,
            'date' => '2026-01-02 10:00:00',
        ]);
        Trade::factory()->create([
            'account_id' => $account->id,
            'instrument_id' => $this->eurusdInstrumentId,
            'pair' => 'EURUSD',
            'profit_loss' => 100,
            'r_multiple' => 1,
            'rr' => 1,
            'date' => '2026-01-03 10:00:00',
        ]);

        $overview = $this->getJson("/api/analytics/overview?account_id={$account->id}");
        $overview->assertOk();
        $overview->assertJsonPath('return_on_equity_pct', 1);
        $overview->assertJsonMissingPath('returns_percent');

        $metrics = $this->getJson("/api/analytics/metrics?account_id={$account->id}");
        $metrics->assertOk();
        $metrics->assertJsonPath('expectancy_money', 50);
        $metrics->assertJsonPath('expectancy_r', 0.5);
        $metrics->assertJsonPath('avg_r_realized', 1);
        $metrics->assertJsonPath('avg_rr_planned', 2);
    }

    public function test_trade_image_upload_and_trade_details_include_images(): void
    {
        Storage::fake('public');

        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $trade = Trade::factory()->create([
            'account_id' => $account->id,
        ]);

        $file = UploadedFile::fake()->create('chart.jpg', 250, 'image/jpeg');

        $uploadResponse = $this->postJson("/api/trades/{$trade->id}/images", [
            'image' => $file,
        ]);
        $uploadResponse->assertCreated();
        $uploadResponse->assertJsonStructure([
            'id',
            'image_url',
            'thumbnail_url',
            'file_size',
            'file_type',
            'sort_order',
        ]);

        $detailsResponse = $this->getJson("/api/trades/{$trade->id}");
        $detailsResponse->assertOk();
        $detailsResponse->assertJsonStructure([
            'trade' => ['id', 'pair', 'account_id'],
            'images' => [[
                'id',
                'image_url',
                'thumbnail_url',
            ]],
        ]);
        $this->assertSame(1, count($detailsResponse->json('images')));
    }

    public function test_trade_image_delete_endpoint_removes_image(): void
    {
        Storage::fake('public');

        $account = Account::factory()->create([
            'starting_balance' => 10_000,
            'current_balance' => 10_000,
            'is_active' => true,
        ]);

        $trade = Trade::factory()->create([
            'account_id' => $account->id,
        ]);

        $uploadResponse = $this->postJson("/api/trades/{$trade->id}/images", [
            'image' => UploadedFile::fake()->create('delete-me.png', 220, 'image/png'),
        ])->assertCreated();

        $imageId = (int) $uploadResponse->json('id');
        $this->deleteJson("/api/trade-images/{$imageId}")->assertNoContent();

        $detailsResponse = $this->getJson("/api/trades/{$trade->id}");
        $detailsResponse->assertOk();
        $this->assertSame(0, count($detailsResponse->json('images')));
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
            'close_date' => '2026-01-02T10:00:00Z',
            'session' => 'London',
            'strategy_model' => 'Breakout',
            'notes' => 'Test trade',
        ];
    }
}
