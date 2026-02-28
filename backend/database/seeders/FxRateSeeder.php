<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FxRateSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = [
            ['from_currency' => 'USD', 'to_currency' => 'JPY', 'rate' => 150.0000000000],
            ['from_currency' => 'GBP', 'to_currency' => 'USD', 'rate' => 1.2700000000],
            ['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.0800000000],
            ['from_currency' => 'USD', 'to_currency' => 'CHF', 'rate' => 0.8800000000],
            ['from_currency' => 'USD', 'to_currency' => 'CAD', 'rate' => 1.3500000000],
            ['from_currency' => 'AUD', 'to_currency' => 'USD', 'rate' => 0.6600000000],
            ['from_currency' => 'NZD', 'to_currency' => 'USD', 'rate' => 0.6100000000],
            ['from_currency' => 'EUR', 'to_currency' => 'GBP', 'rate' => 0.8500000000],
        ];

        foreach ($rows as $row) {
            DB::table('fx_rates')->updateOrInsert(
                [
                    'from_currency' => $row['from_currency'],
                    'to_currency' => $row['to_currency'],
                ],
                [
                    'rate' => $row['rate'],
                    'provider' => 'seed',
                    'source' => 'database/seeder/FxRateSeeder',
                    'bid' => $row['rate'],
                    'ask' => $row['rate'],
                    'mid' => $row['rate'],
                    'bid_provenance' => 'seed:mid',
                    'ask_provenance' => 'seed:mid',
                    'mid_provenance' => 'seed:direct',
                    'rate_updated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
