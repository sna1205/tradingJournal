<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Instrument;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TradeRevisionPreconditionTest extends TestCase
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

    public function test_checklist_write_requires_if_match_and_stale_revision_returns_412(): void
    {
        $account = $this->createOwnedAccount();
        $trade = $this->createTrade((int) $account->id);

        $checklist = Checklist::query()->create([
            'user_id' => (int) $this->user->id,
            'account_id' => (int) $account->id,
            'strategy_model_id' => null,
            'name' => 'Revision Checklist',
            'scope' => 'account',
            'enforcement_mode' => 'soft',
            'is_active' => true,
        ]);
        $item = ChecklistItem::query()->create([
            'checklist_id' => (int) $checklist->id,
            'title' => 'Confirm setup quality',
            'type' => 'checkbox',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $missingHeader = $this->putJson("/api/trades/{$trade->id}/rule-responses", [
            'responses' => [
                [
                    'checklist_item_id' => (int) $item->id,
                    'value' => true,
                ],
            ],
        ]);
        $missingHeader->assertStatus(428);
        $missingHeader->assertJsonPath('error.code', 'trade_if_match_required');

        $stale = $this->withHeaders(['If-Match' => '99'])->putJson("/api/trades/{$trade->id}/rule-responses", [
            'responses' => [
                [
                    'checklist_item_id' => (int) $item->id,
                    'value' => true,
                ],
            ],
        ]);
        $stale->assertStatus(412);
        $stale->assertJsonPath('error.code', 'trade_precondition_failed');

        $fresh = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trades/{$trade->id}/rule-responses", [
            'responses' => [
                [
                    'checklist_item_id' => (int) $item->id,
                    'value' => true,
                ],
            ],
        ]);
        $fresh->assertOk();

        $trade->refresh();
        $this->assertSame(2, (int) $trade->revision);
    }

    public function test_psychology_write_with_correct_if_match_succeeds_and_increments_revision(): void
    {
        $account = $this->createOwnedAccount();
        $trade = $this->createTrade((int) $account->id);

        $stale = $this->withHeaders(['If-Match' => '99'])->putJson("/api/trades/{$trade->id}/psychology", [
            'pre_emotion' => 'calm',
        ]);
        $stale->assertStatus(412);
        $stale->assertJsonPath('error.code', 'trade_precondition_failed');

        $fresh = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trades/{$trade->id}/psychology", [
            'pre_emotion' => 'calm',
            'post_emotion' => 'focused',
            'confidence_score' => 8,
        ]);
        $fresh->assertOk();
        $fresh->assertJsonPath('trade_id', (int) $trade->id);
        $fresh->assertHeader('ETag');

        $trade->refresh();
        $this->assertSame(2, (int) $trade->revision);
    }

    public function test_image_metadata_write_rejects_stale_if_match_and_increments_revision_on_success(): void
    {
        Storage::fake('public');

        $account = $this->createOwnedAccount();
        $trade = $this->createTrade((int) $account->id);

        $upload = $this->withHeaders(['If-Match' => '1'])->postJson("/api/trades/{$trade->id}/images", [
            'image' => UploadedFile::fake()->create('chart.png', 200, 'image/png'),
        ]);
        $upload->assertCreated();
        $imageId = (int) $upload->json('id');
        $trade->refresh();
        $this->assertSame(2, (int) $trade->revision);

        $stale = $this->withHeaders(['If-Match' => '1'])->putJson("/api/trade-images/{$imageId}", [
            'annotation_notes' => 'stale-write',
        ]);
        $stale->assertStatus(412);
        $stale->assertJsonPath('error.code', 'trade_precondition_failed');

        $fresh = $this->withHeaders(['If-Match' => '2'])->putJson("/api/trade-images/{$imageId}", [
            'annotation_notes' => 'server-accepted-update',
        ]);
        $fresh->assertOk();
        $fresh->assertJsonPath('annotation_notes', 'server-accepted-update');
        $fresh->assertHeader('ETag');

        $trade->refresh();
        $this->assertSame(3, (int) $trade->revision);
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

    private function createTrade(int $accountId): Trade
    {
        return Trade::factory()->create([
            'account_id' => $accountId,
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
            'revision' => 1,
        ]);
    }
}
