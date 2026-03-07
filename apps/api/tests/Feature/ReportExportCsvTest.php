<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Trade;
use App\Models\TradeTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportExportCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_export_sanitizes_formula_like_cells(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $trade = Trade::factory()->create([
            'account_id' => $account->id,
            'pair' => 'EURUSD',
            'notes' => '=HYPERLINK("https://attacker.example","click")',
            'date' => '2026-01-05 10:00:00',
        ]);

        $tag = TradeTag::query()->create([
            'name' => '@risk-tag',
            'slug' => 'risk-tag',
            'color' => '#ef4444',
            'is_active' => true,
        ]);
        $trade->tags()->attach($tag->id);

        $response = $this->get('/api/reports/export.csv?scope=trades&columns=notes,tags,pair');
        $response->assertOk();

        $content = (string) $response->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $this->assertIsArray($lines);
        $this->assertGreaterThanOrEqual(2, count($lines));

        $header = str_getcsv((string) $lines[0]);
        $row = str_getcsv((string) $lines[1]);

        $this->assertSame(['notes', 'tags', 'pair'], $header);
        $this->assertSame('\'=HYPERLINK("https://attacker.example","click")', $row[0] ?? null);
        $this->assertSame('\'@risk-tag', $row[1] ?? null);
        $this->assertSame('EURUSD', $row[2] ?? null);
    }
}
