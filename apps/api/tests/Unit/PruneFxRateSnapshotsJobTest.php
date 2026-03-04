<?php

namespace Tests\Unit;

use App\Jobs\PruneFxRateSnapshotsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneFxRateSnapshotsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_archives_stale_snapshots_and_keeps_recent_rows_hot(): void
    {
        config()->set('fx.retention.snapshot_hot_days', 30);
        config()->set('fx.retention.snapshot_archive_days', 365);
        config()->set('fx.retention.prune_batch_size', 50);

        $now = now();
        $oldSnapshotDate = $now->copy()->subDays(45)->toDateString();
        $recentSnapshotDate = $now->copy()->subDays(10)->toDateString();

        $oldId = DB::table('fx_rate_snapshots')->insertGetId([
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'snapshot_date' => $oldSnapshotDate,
            'rate' => 1.0800000000,
            'rate_updated_at' => $now->copy()->subDays(45),
            'provider' => 'exchange_rate_api',
            'source' => 'unit-test',
            'bid' => 1.0799000000,
            'ask' => 1.0801000000,
            'mid' => 1.0800000000,
            'bid_provenance' => 'api:bid',
            'ask_provenance' => 'api:ask',
            'mid_provenance' => 'derived:(bid+ask)/2',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $recentId = DB::table('fx_rate_snapshots')->insertGetId([
            'from_currency' => 'GBP',
            'to_currency' => 'USD',
            'snapshot_date' => $recentSnapshotDate,
            'rate' => 1.2700000000,
            'rate_updated_at' => $now->copy()->subDays(10),
            'provider' => 'exchange_rate_api',
            'source' => 'unit-test',
            'bid' => 1.2699000000,
            'ask' => 1.2701000000,
            'mid' => 1.2700000000,
            'bid_provenance' => 'api:bid',
            'ask_provenance' => 'api:ask',
            'mid_provenance' => 'derived:(bid+ask)/2',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $result = (new PruneFxRateSnapshotsJob())->handle();

        $this->assertSame(1, $result['archived']);
        $this->assertSame(0, $result['pruned_archive']);
        $this->assertDatabaseMissing('fx_rate_snapshots', ['id' => $oldId]);
        $this->assertDatabaseHas('fx_rate_snapshots', ['id' => $recentId]);
        $this->assertDatabaseHas('fx_rate_snapshot_archives', [
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'snapshot_date' => $oldSnapshotDate,
            'provider' => 'exchange_rate_api',
            'source' => 'unit-test',
            'mid_provenance' => 'derived:(bid+ask)/2',
        ]);
    }

    public function test_it_prunes_archived_snapshots_beyond_retention_window(): void
    {
        config()->set('fx.retention.snapshot_hot_days', 30);
        config()->set('fx.retention.snapshot_archive_days', 120);
        config()->set('fx.retention.prune_batch_size', 50);

        $now = now();
        $expiredArchiveDate = $now->copy()->subDays(180)->toDateString();
        $retainedArchiveDate = $now->copy()->subDays(60)->toDateString();

        DB::table('fx_rate_snapshot_archives')->insert([
            [
                'from_currency' => 'AUD',
                'to_currency' => 'USD',
                'snapshot_date' => $expiredArchiveDate,
                'rate' => 0.6600000000,
                'rate_updated_at' => $now->copy()->subDays(180),
                'provider' => 'seed',
                'source' => 'unit-test',
                'bid' => 0.6599000000,
                'ask' => 0.6601000000,
                'mid' => 0.6600000000,
                'bid_provenance' => 'seed:mid',
                'ask_provenance' => 'seed:mid',
                'mid_provenance' => 'seed:direct',
                'archived_at' => $now->copy()->subDays(170),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'from_currency' => 'NZD',
                'to_currency' => 'USD',
                'snapshot_date' => $retainedArchiveDate,
                'rate' => 0.6100000000,
                'rate_updated_at' => $now->copy()->subDays(60),
                'provider' => 'seed',
                'source' => 'unit-test',
                'bid' => 0.6099000000,
                'ask' => 0.6101000000,
                'mid' => 0.6100000000,
                'bid_provenance' => 'seed:mid',
                'ask_provenance' => 'seed:mid',
                'mid_provenance' => 'seed:direct',
                'archived_at' => $now->copy()->subDays(55),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $result = (new PruneFxRateSnapshotsJob())->handle();

        $this->assertSame(0, $result['archived']);
        $this->assertSame(1, $result['pruned_archive']);
        $this->assertDatabaseMissing('fx_rate_snapshot_archives', [
            'from_currency' => 'AUD',
            'to_currency' => 'USD',
            'snapshot_date' => $expiredArchiveDate,
        ]);
        $this->assertDatabaseHas('fx_rate_snapshot_archives', [
            'from_currency' => 'NZD',
            'to_currency' => 'USD',
            'snapshot_date' => $retainedArchiveDate,
        ]);
    }
}
