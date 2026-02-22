<?php

namespace Tests\Unit;

use App\Services\TradeCalculationEngine;
use Tests\TestCase;

class TradeCalculationEngineTest extends TestCase
{
    public function test_it_calculates_eurusd_trade_with_broker_style_tick_math_and_costs(): void
    {
        $engine = new TradeCalculationEngine();

        $result = $engine->calculate([
            'direction' => 'buy',
            'entry_price' => 1.10000,
            'stop_loss' => 1.09900,
            'take_profit' => 1.10200,
            'actual_exit_price' => 1.10150,
            'lot_size' => 1.0,
            'instrument_tick_size' => 0.00001,
            'instrument_tick_value' => 1.0,
            'commission' => 7.0,
            'swap' => -0.5,
            'spread_cost' => 1.5,
            'slippage_cost' => 0.5,
            'account_balance_before_trade' => 10000,
        ]);

        $this->assertEqualsWithDelta(150.0, $result['gross_profit_loss'], 0.0001);
        $this->assertEqualsWithDelta(8.5, $result['costs_total'], 0.0001);
        $this->assertEqualsWithDelta(141.5, $result['profit_loss'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['monetary_risk'], 0.0001);
        $this->assertEqualsWithDelta(200.0, $result['monetary_reward'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $result['rr'], 0.0001);
        $this->assertEqualsWithDelta(1.415, $result['r_multiple'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $result['risk_percent'], 0.0001);
        $this->assertEqualsWithDelta(10141.5, $result['account_balance_after_trade'], 0.01);
    }

    public function test_it_calculates_xauusd_trade_with_tick_math_and_costs(): void
    {
        $engine = new TradeCalculationEngine();

        $result = $engine->calculate([
            'direction' => 'sell',
            'entry_price' => 2350.00,
            'stop_loss' => 2360.00,
            'take_profit' => 2330.00,
            'actual_exit_price' => 2342.00,
            'lot_size' => 2.0,
            'instrument_tick_size' => 0.01,
            'instrument_tick_value' => 1.0,
            'commission' => 12.0,
            'swap' => 1.0,
            'spread_cost' => 4.0,
            'slippage_cost' => 3.0,
            'account_balance_before_trade' => 50000,
        ]);

        $this->assertEqualsWithDelta(1600.0, $result['gross_profit_loss'], 0.0001);
        $this->assertEqualsWithDelta(20.0, $result['costs_total'], 0.0001);
        $this->assertEqualsWithDelta(1580.0, $result['profit_loss'], 0.01);
        $this->assertEqualsWithDelta(2000.0, $result['monetary_risk'], 0.0001);
        $this->assertEqualsWithDelta(4000.0, $result['monetary_reward'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $result['rr'], 0.0001);
        $this->assertEqualsWithDelta(0.79, $result['r_multiple'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $result['risk_percent'], 0.0001);
        $this->assertEqualsWithDelta(51580.0, $result['account_balance_after_trade'], 0.01);
    }

    public function test_it_preserves_legacy_fallback_calculation_without_instrument_specs(): void
    {
        $engine = new TradeCalculationEngine();

        $result = $engine->calculate([
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0997,
            'take_profit' => 1.1009,
            'actual_exit_price' => 1.1009,
            'lot_size' => 0.1,
            'commission' => 0.0001,
            'account_balance_before_trade' => 10000,
        ]);

        $this->assertSame(0.00003, $result['monetary_risk']);
        $this->assertSame(0.00009, $result['monetary_reward']);
        $this->assertEqualsWithDelta(0.0, $result['profit_loss'], 0.01);
        $this->assertSame(3.0, $result['rr']);
        $this->assertSame(0.0, $result['risk_percent']);
    }
}
