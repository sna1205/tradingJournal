<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InstrumentSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'symbol' => 'EURUSD',
                'asset_class' => 'forex',
                'base_currency' => 'EUR',
                'quote_currency' => 'USD',
                'contract_size' => 100000,
                'tick_size' => 0.00001,
                'tick_value' => 1.0,
                'pip_size' => 0.0001,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ],
            [
                'symbol' => 'GBPUSD',
                'asset_class' => 'forex',
                'base_currency' => 'GBP',
                'quote_currency' => 'USD',
                'contract_size' => 100000,
                'tick_size' => 0.00001,
                'tick_value' => 1.0,
                'pip_size' => 0.0001,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ],
            [
                'symbol' => 'USDJPY',
                'asset_class' => 'forex',
                'base_currency' => 'USD',
                'quote_currency' => 'JPY',
                'contract_size' => 100000,
                'tick_size' => 0.001,
                'tick_value' => 1.0,
                'pip_size' => 0.01,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ],
            [
                'symbol' => 'XAUUSD',
                'asset_class' => 'metal',
                'base_currency' => 'XAU',
                'quote_currency' => 'USD',
                'contract_size' => 100,
                'tick_size' => 0.01,
                'tick_value' => 1.0,
                'pip_size' => 0.1,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ],
            [
                'symbol' => 'BTCUSD',
                'asset_class' => 'crypto',
                'base_currency' => 'BTC',
                'quote_currency' => 'USD',
                'contract_size' => 1,
                'tick_size' => 0.01,
                'tick_value' => 0.01,
                'pip_size' => 1.0,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ],
            [
                'symbol' => 'ETHUSD',
                'asset_class' => 'crypto',
                'base_currency' => 'ETH',
                'quote_currency' => 'USD',
                'contract_size' => 1,
                'tick_size' => 0.01,
                'tick_value' => 0.01,
                'pip_size' => 1.0,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ],
        ];

        $now = now();
        foreach ($rows as $row) {
            DB::table('instruments')->updateOrInsert(
                ['symbol' => $row['symbol']],
                [...$row, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }
}

