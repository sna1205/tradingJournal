<?php

use App\Jobs\PruneFxRateSnapshotsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('idempotency:prune {--dry-run : Report prune count without deleting rows}', function () {
    $ttlMinutes = max(1, (int) config('idempotency.ttl_minutes', 1440));
    $now = now();
    $legacyCutoff = $now->copy()->subMinutes($ttlMinutes);

    $query = DB::table('idempotency_keys')
        ->where(function ($where) use ($now): void {
            $where->whereNotNull('expires_at')
                ->where('expires_at', '<', $now);
        })
        ->orWhere(function ($where) use ($legacyCutoff): void {
            $where->whereNull('expires_at')
                ->where('created_at', '<', $legacyCutoff);
        });

    $count = (int) (clone $query)->count();
    if ((bool) $this->option('dry-run')) {
        $this->info("Dry run: {$count} idempotency key(s) eligible for prune.");

        return 0;
    }

    $deleted = (int) $query->delete();
    $this->info("Pruned {$deleted} idempotency key(s).");

    return 0;
})->purpose('Delete expired idempotency keys.');

Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('02:10')
    ->withoutOverlapping();

Schedule::call(function (): void {
    if ((string) config('session.driver', 'file') !== 'database') {
        return;
    }

    $lifetimeMinutes = max(1, (int) config('session.lifetime', 120));
    $cutoffTimestamp = now()->subMinutes($lifetimeMinutes)->timestamp;

    DB::table((string) config('session.table', 'sessions'))
        ->where('last_activity', '<', $cutoffTimestamp)
        ->delete();
})
    ->name('auth:prune-expired-database-sessions')
    ->dailyAt('02:20')
    ->withoutOverlapping();

Schedule::call(function (): void {
    PruneFxRateSnapshotsJob::dispatchSync();
})
    ->name('fx:archive-and-prune-snapshots')
    ->dailyAt('02:30')
    ->withoutOverlapping();

Schedule::command('idempotency:prune')
    ->dailyAt('02:40')
    ->withoutOverlapping();
