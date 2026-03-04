<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PruneFxRateSnapshotsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    /**
     * @return array{archived:int,pruned_archive:int}
     */
    public function handle(): array
    {
        $now = now();
        $hotRetentionDays = max(1, (int) config('fx.retention.snapshot_hot_days', 90));
        $archiveRetentionDays = max(1, (int) config('fx.retention.snapshot_archive_days', 1095));
        $batchSize = max(1, (int) config('fx.retention.prune_batch_size', 1000));
        $archiveBeforeDate = $now->copy()->subDays($hotRetentionDays)->toDateString();
        $snapshotTable = 'fx_rate_snapshots';
        $archiveTable = 'fx_rate_snapshot_archives';

        $archivedCount = 0;
        DB::table($snapshotTable)
            ->whereDate('snapshot_date', '<', $archiveBeforeDate)
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use (
                $snapshotTable,
                $archiveTable,
                $now,
                &$archivedCount
            ): void {
                if ($rows->isEmpty()) {
                    return;
                }

                $ids = [];
                $archiveRows = [];
                foreach ($rows as $row) {
                    $ids[] = (int) $row->id;
                    $archiveRows[] = [
                        'from_currency' => (string) $row->from_currency,
                        'to_currency' => (string) $row->to_currency,
                        'snapshot_date' => (string) $row->snapshot_date,
                        'rate' => (float) $row->rate,
                        'rate_updated_at' => $row->rate_updated_at,
                        'provider' => $row->provider,
                        'source' => $row->source,
                        'bid' => $row->bid,
                        'ask' => $row->ask,
                        'mid' => $row->mid,
                        'bid_provenance' => $row->bid_provenance,
                        'ask_provenance' => $row->ask_provenance,
                        'mid_provenance' => $row->mid_provenance,
                        'archived_at' => $now,
                        'created_at' => $row->created_at ?? $now,
                        'updated_at' => $row->updated_at ?? $now,
                    ];
                }

                DB::transaction(function () use ($archiveTable, $snapshotTable, $archiveRows, $ids): void {
                    DB::table($archiveTable)->upsert(
                        $archiveRows,
                        ['from_currency', 'to_currency', 'snapshot_date'],
                        [
                            'rate',
                            'rate_updated_at',
                            'provider',
                            'source',
                            'bid',
                            'ask',
                            'mid',
                            'bid_provenance',
                            'ask_provenance',
                            'mid_provenance',
                            'archived_at',
                            'updated_at',
                        ]
                    );

                    DB::table($snapshotTable)
                        ->whereIn('id', $ids)
                        ->delete();
                });

                $archivedCount += count($ids);
            });

        $pruneBeforeDate = $now->copy()->subDays($archiveRetentionDays)->toDateString();
        $prunedArchiveCount = DB::table($archiveTable)
            ->whereDate('snapshot_date', '<', $pruneBeforeDate)
            ->delete();

        return [
            'archived' => $archivedCount,
            'pruned_archive' => (int) $prunedArchiveCount,
        ];
    }
}
