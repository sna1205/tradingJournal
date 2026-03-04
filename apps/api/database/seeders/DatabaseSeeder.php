<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LocalDemoUserSeeder::class,
            InstrumentSeeder::class,
            FxRateSeeder::class,
            FxRateSnapshotSeeder::class,
            AccountSeeder::class,
            ChecklistSeeder::class,
            AccountRiskPolicySeeder::class,
            PropChallengeSeeder::class,
            TradeSeeder::class,
            TradeImageSeeder::class,
            MissedTradeSeeder::class,
            MissedTradeImageSeeder::class,
        ]);

        // Keep analytics cache fresh after reseeding demo/mock datasets.
        if (!Cache::has('analytics:version')) {
            Cache::forever('analytics:version', 1);
        }
        Cache::increment('analytics:version');
    }
}
