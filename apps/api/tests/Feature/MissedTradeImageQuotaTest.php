<?php

namespace Tests\Feature;

use App\Models\MissedTrade;
use App\Models\MissedTradeImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MissedTradeImageQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_missed_trade_image_quota_is_atomic_under_concurrency(): void
    {
        Storage::fake('public');
        config()->set('filesystems.trade_images_disk', 'public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $missedTrade = MissedTrade::factory()->create([
            'user_id' => $user->id,
        ]);

        for ($index = 1; $index <= 4; $index++) {
            MissedTradeImage::query()->create([
                'missed_trade_id' => $missedTrade->id,
                'image_url' => "missed-trades/{$missedTrade->id}/original/existing-{$index}.jpg",
                'thumbnail_url' => "missed-trades/{$missedTrade->id}/thumbs/existing-{$index}.jpg",
                'file_size' => 256_000,
                'file_type' => 'image/jpeg',
                'sort_order' => $index,
            ]);
        }

        $first = $this->uploadMissedTradeImage($missedTrade, 'first-concurrent-attempt');
        $first->assertCreated();

        $second = $this->uploadMissedTradeImage($missedTrade, 'second-concurrent-attempt');
        $second->assertStatus(422);
        $second->assertJsonValidationErrors(['image']);

        $this->assertSame(
            5,
            MissedTradeImage::query()->where('missed_trade_id', $missedTrade->id)->count()
        );
    }

    private function uploadMissedTradeImage(MissedTrade $missedTrade, string $name): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withHeaders([
                'Accept' => 'application/json',
                'Idempotency-Key' => (string) Str::uuid(),
            ])
            ->post(
                "/api/missed-trades/{$missedTrade->id}/images",
                [
                    'image' => UploadedFile::fake()->create("{$name}.jpg", 256, 'image/jpeg'),
                ]
            );
    }
}
