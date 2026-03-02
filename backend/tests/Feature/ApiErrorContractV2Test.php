<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Instrument;
use App\Models\User;
use App\Support\ApiErrorResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiErrorContractV2Test extends TestCase
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

    public function test_trade_conflict_response_uses_error_contract_v2(): void
    {
        $account = $this->createOwnedAccount();
        $create = $this
            ->withHeaders(['Idempotency-Key' => 'contract-v2-trade-conflict-create'])
            ->postJson('/api/trades', $this->tradePayload((int) $account->id));
        $create->assertCreated();

        $tradeId = (int) $create->json('id');

        $first = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trades/{$tradeId}", [
            'notes' => 'first write',
        ]);
        $first->assertOk();

        $conflict = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trades/{$tradeId}", [
            'notes' => 'stale write',
        ]);

        $conflict->assertStatus(409);
        $conflict->assertHeader('X-Error-Contract', 'v2');
        $conflict->assertJsonPath('error.version', ApiErrorResponder::CONTRACT_VERSION);
        $conflict->assertJsonPath('error.status', 409);
        $conflict->assertJsonPath('error.code', 'trade_revision_conflict');
        $conflict->assertJsonPath('error.details.0.field', 'revision');
        $conflict->assertJsonPath('current.revision', 2);
    }

    public function test_idempotency_errors_use_error_contract_v2(): void
    {
        $account = $this->createOwnedAccount();

        $missingKey = $this->postJson('/api/trades', $this->tradePayload((int) $account->id));
        $missingKey->assertStatus(422);
        $missingKey->assertHeader('X-Error-Contract', 'v2');
        $missingKey->assertJsonPath('error.version', ApiErrorResponder::CONTRACT_VERSION);
        $missingKey->assertJsonPath('error.status', 422);
        $missingKey->assertJsonPath('error.code', 'idempotency_key_required');
        $missingKey->assertJsonPath('error.details.0.field', 'Idempotency-Key');

        $first = $this
            ->withHeaders(['Idempotency-Key' => 'contract-v2-idem-key'])
            ->postJson('/api/trades', $this->tradePayload((int) $account->id));
        $first->assertCreated();

        $secondPayload = $this->tradePayload((int) $account->id, [
            'notes' => 'different payload for same key',
        ]);
        $payloadMismatch = $this
            ->withHeaders(['Idempotency-Key' => 'contract-v2-idem-key'])
            ->postJson('/api/trades', $secondPayload);

        $payloadMismatch->assertStatus(409);
        $payloadMismatch->assertHeader('X-Error-Contract', 'v2');
        $payloadMismatch->assertJsonPath('error.version', ApiErrorResponder::CONTRACT_VERSION);
        $payloadMismatch->assertJsonPath('error.status', 409);
        $payloadMismatch->assertJsonPath('error.code', 'idempotency_payload_mismatch');
    }

    private function createOwnedAccount(): Account
    {
        return Account::factory()->create([
            'user_id' => (int) $this->user->id,
            'currency' => 'USD',
            'starting_balance' => 10000,
            'current_balance' => 10000,
            'is_active' => true,
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function tradePayload(int $accountId, array $overrides = []): array
    {
        return array_merge([
            'account_id' => $accountId,
            'instrument_id' => $this->instrumentId,
            'symbol' => 'EURUSD',
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0990,
            'take_profit' => 1.1020,
            'actual_exit_price' => 1.1010,
            'position_size' => 0.10,
            'followed_rules' => true,
            'emotion' => 'calm',
            'close_date' => now()->subDay()->toIso8601String(),
            'session' => 'London',
            'strategy_model' => 'Breakout',
            'notes' => 'api error contract test payload',
        ], $overrides);
    }
}
