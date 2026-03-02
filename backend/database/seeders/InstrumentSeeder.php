<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InstrumentSeeder extends Seeder
{
    public function run(): void
    {
        $forex = static function (string $symbol, string $base, string $quote): array {
            $contractSize = 100000;
            $tickSize = $quote === 'JPY' ? 0.001 : 0.00001;

            return [
                'symbol' => $symbol,
                'asset_class' => 'forex',
                'base_currency' => $base,
                'quote_currency' => $quote,
                'contract_size' => $contractSize,
                'tick_size' => $tickSize,
                'tick_value' => $contractSize * $tickSize,
                'pip_size' => $quote === 'JPY' ? 0.01 : 0.0001,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ];
        };

        $crypto = static function (string $symbol, string $base, string $quote = 'USDT'): array {
            return [
                'symbol' => $symbol,
                'asset_class' => 'crypto',
                'base_currency' => $base,
                'quote_currency' => $quote,
                'contract_size' => 1,
                'tick_size' => 0.01,
                'tick_value' => 0.01,
                'pip_size' => 1.0,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ];
        };

        $stock = static function (string $symbol): array {
            return [
                'symbol' => $symbol,
                'asset_class' => 'stocks',
                'base_currency' => $symbol,
                'quote_currency' => 'USD',
                'contract_size' => 1,
                'tick_size' => 0.01,
                'tick_value' => 0.01,
                'pip_size' => 0.01,
                'min_lot' => 1,
                'lot_step' => 1,
                'is_active' => true,
            ];
        };

        $index = static function (string $symbol): array {
            return [
                'symbol' => $symbol,
                'asset_class' => 'indices',
                'base_currency' => $symbol,
                'quote_currency' => 'USD',
                'contract_size' => 1,
                'tick_size' => 0.1,
                'tick_value' => 0.1,
                'pip_size' => 1.0,
                'min_lot' => 1,
                'lot_step' => 1,
                'is_active' => true,
            ];
        };

        $future = static function (string $symbol): array {
            return [
                'symbol' => $symbol,
                'asset_class' => 'futures',
                'base_currency' => $symbol,
                'quote_currency' => 'USD',
                'contract_size' => 1,
                'tick_size' => 0.25,
                'tick_value' => 0.25,
                'pip_size' => 1.0,
                'min_lot' => 1,
                'lot_step' => 1,
                'is_active' => true,
            ];
        };

        $commodity = static function (string $symbol, string $base, string $quote = 'USD'): array {
            $contractSize = in_array($base, ['XAU', 'XAG'], true) ? 100 : 1;
            $tickSize = 0.01;

            return [
                'symbol' => $symbol,
                'asset_class' => 'commodities',
                'base_currency' => $base,
                'quote_currency' => $quote,
                'contract_size' => $contractSize,
                'tick_size' => $tickSize,
                'tick_value' => $contractSize * $tickSize,
                'pip_size' => 0.1,
                'min_lot' => 0.01,
                'lot_step' => 0.01,
                'is_active' => true,
            ];
        };

        $rows = [
            $forex('EURUSD', 'EUR', 'USD'),
            $forex('GBPUSD', 'GBP', 'USD'),
            $forex('USDJPY', 'USD', 'JPY'),
            $forex('AUDUSD', 'AUD', 'USD'),
            $forex('NZDUSD', 'NZD', 'USD'),
            $forex('USDCAD', 'USD', 'CAD'),
            $forex('USDCHF', 'USD', 'CHF'),
            $forex('EURGBP', 'EUR', 'GBP'),
            $forex('EURJPY', 'EUR', 'JPY'),
            $forex('GBPJPY', 'GBP', 'JPY'),
            $forex('AUDJPY', 'AUD', 'JPY'),
            $forex('CHFJPY', 'CHF', 'JPY'),
            $forex('EURAUD', 'EUR', 'AUD'),
            $forex('EURNZD', 'EUR', 'NZD'),
            $forex('GBPAUD', 'GBP', 'AUD'),
            $forex('GBPCHF', 'GBP', 'CHF'),

            $crypto('BTCUSDT', 'BTC'),
            $crypto('ETHUSDT', 'ETH'),
            $crypto('SOLUSDT', 'SOL'),
            $crypto('BNBUSDT', 'BNB'),
            $crypto('XRPUSDT', 'XRP'),
            $crypto('ADAUSDT', 'ADA'),
            $crypto('DOGEUSDT', 'DOGE'),
            $crypto('LTCUSDT', 'LTC'),

            $stock('AAPL'),
            $stock('MSFT'),
            $stock('NVDA'),
            $stock('TSLA'),
            $stock('AMZN'),
            $stock('META'),
            $stock('GOOGL'),
            $stock('NFLX'),

            $index('US30'),
            $index('NAS100'),
            $index('SPX500'),
            $index('DAX40'),
            $index('UK100'),
            $index('JP225'),
            $index('HK50'),

            $future('NQ'),
            $future('ES'),
            $future('YM'),
            $future('CL'),
            $future('GC'),
            $future('SI'),

            $commodity('XAUUSD', 'XAU'),
            $commodity('XAGUSD', 'XAG'),
            $commodity('WTI', 'WTI'),
            $commodity('BRENT', 'BRENT'),
            $commodity('NATGAS', 'NATGAS'),
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
