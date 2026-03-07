<?php

namespace Database\Factories;

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instrument>
 */
class InstrumentFactory extends Factory
{
    protected $model = Instrument::class;

    public function definition(): array
    {
        $symbol = fake()->randomElement(['EURUSD', 'GBPUSD', 'USDJPY', 'XAUUSD']);

        return [
            'symbol' => $symbol,
            'asset_class' => $symbol === 'XAUUSD' ? 'metal' : 'forex',
            'base_currency' => substr($symbol, 0, 3),
            'quote_currency' => substr($symbol, 3, 3),
            'contract_size' => $symbol === 'XAUUSD' ? 100 : 100000,
            'tick_size' => $symbol === 'USDJPY' ? 0.001 : ($symbol === 'XAUUSD' ? 0.01 : 0.00001),
            'tick_value' => 1.0,
            'pip_size' => $symbol === 'USDJPY' ? 0.01 : ($symbol === 'XAUUSD' ? 0.1 : 0.0001),
            'min_lot' => 0.01,
            'lot_step' => 0.01,
            'is_active' => true,
        ];
    }
}

