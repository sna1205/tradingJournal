<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

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
