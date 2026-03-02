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
            ->select([
                'from_currency',
                'to_currency',
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
            ])
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
                        'rate_updated_at' => $rate->rate_updated_at ?? $timestamp,
                        'provider' => $rate->provider,
                        'source' => $rate->source,
                        'bid' => $rate->bid,
                        'ask' => $rate->ask,
                        'mid' => $rate->mid,
                        'bid_provenance' => $rate->bid_provenance,
                        'ask_provenance' => $rate->ask_provenance,
                        'mid_provenance' => $rate->mid_provenance,
                        'updated_at' => $timestamp,
                        'created_at' => $timestamp,
                    ]
                );
            });
    }
}
