<?php

return [
    'retention' => [
        // Keep recent snapshots in the hot table for fast lookup.
        'snapshot_hot_days' => (int) env('FX_SNAPSHOT_HOT_DAYS', 90),
        // Keep archived snapshots for audit/backfill use before permanent prune.
        'snapshot_archive_days' => (int) env('FX_SNAPSHOT_ARCHIVE_DAYS', 1095),
        // Chunk size used by archival/prune job.
        'prune_batch_size' => (int) env('FX_SNAPSHOT_PRUNE_BATCH_SIZE', 1000),
    ],
];
