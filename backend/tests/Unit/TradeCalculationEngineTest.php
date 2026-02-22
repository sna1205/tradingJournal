<?php

namespace Tests\Unit;

use App\Services\TradeCalculationEngine;
use Tests\TestCase;

class TradeCalculationEngineTest extends TestCase
{
    public function test_it_calculates_buy_trade_metrics(): void
    {
        $engine = new TradeCalculationEngine();

        $result = $engine->calculate([
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0900,
            'take_profit' => 1.1300,
            'actual_exit_price' => 1.1200,
            'lot_size' => 2,
            'account_balance_before_trade' => 5000,
        ]);

        $this->assertSame(0.01, $result['risk_per_unit']);
        $this->assertSame(0.03, $result['reward_per_unit']);
        $this->assertSame(0.02, $result['monetary_risk']);
        $this->assertSame(0.06, $result['monetary_reward']);
        $this->assertSame(0.04, $result['profit_loss']);
        $this->assertSame(3.0, $result['rr']);
        $this->assertSame(2.0, $result['r_multiple']);
        $this->assertSame(0.0004, $result['risk_percent']);
        $this->assertSame(5000.04, $result['account_balance_after_trade']);
    }

    public function test_it_calculates_sell_trade_metrics(): void
    {
        $engine = new TradeCalculationEngine();

        $result = $engine->calculate([
            'direction' => 'sell',
            'entry_price' => 2000,
            'stop_loss' => 2010,
            'take_profit' => 1970,
            'actual_exit_price' => 2008,
            'lot_size' => 1.5,
            'account_balance_before_trade' => 10000,
        ]);

        $this->assertSame(10.0, $result['risk_per_unit']);
        $this->assertSame(30.0, $result['reward_per_unit']);
        $this->assertSame(15.0, $result['monetary_risk']);
        $this->assertSame(45.0, $result['monetary_reward']);
        $this->assertSame(-12.0, $result['profit_loss']);
        $this->assertSame(3.0, $result['rr']);
        $this->assertSame(-0.8, $result['r_multiple']);
        $this->assertSame(0.15, $result['risk_percent']);
        $this->assertSame(9988.0, $result['account_balance_after_trade']);
    }

    public function test_it_preserves_sub_cent_monetary_risk_precision(): void
    {
        $engine = new TradeCalculationEngine();

        $result = $engine->calculate([
            'direction' => 'buy',
            'entry_price' => 1.1000,
            'stop_loss' => 1.0997,
            'take_profit' => 1.1009,
            'actual_exit_price' => 1.1009,
            'lot_size' => 0.1,
            'account_balance_before_trade' => 10000,
        ]);

        $this->assertSame(0.00003, $result['monetary_risk']);
        $this->assertSame(0.00009, $result['monetary_reward']);
        $this->assertSame(0.0, $result['profit_loss']);
        $this->assertSame(3.0, $result['rr']);
        $this->assertSame(0.0, $result['risk_percent']);
    }
}
