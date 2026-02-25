<?php

namespace Database\Seeders;

use App\Models\FxRate;
use App\Models\FxRateSnapshot;
use Illuminate\Database\Seeder;

class FxRateSnapshotSeeder extends Seeder
{
    public function run(): void
    {
        $today = now()->toDateString();
        $timestamp = now();

        FxRate::query()
            ->select(['from_currency', 'to_currency', 'rate'])
            ->get()
            ->each(function (FxRate $rate) use ($today, $timestamp): void {
                FxRateSnapshot::query()->updateOrCreate(
                    [
                        'from_currency' => strtoupper((string) $rate->from_currency),
                        'to_currency' => strtoupper((string) $rate->to_currency),
                        'snapshot_date' => $today,
                    ],
                    [
                        'rate' => (float) $rate->rate,
                        'updated_at' => $timestamp,
                        'created_at' => $timestamp,
                    ]
                );
            });
    }
}
