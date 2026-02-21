<?php

namespace Tests\Unit;

use App\Services\Analytics\BehavioralAnalyticsEngine;
use App\Services\Analytics\EquityEngine;
use App\Services\Analytics\StreakEngine;
use App\Services\Analytics\TradeMetricsEngine;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AnalyticsEnginesTest extends TestCase
{
    public function test_equity_engine_returns_expected_series_and_drawdown(): void
    {
        $trades = collect([
            $this->trade('2026-01-01 09:00:00', 100),
            $this->trade('2026-01-02 09:00:00', -50),
            $this->trade('2026-01-03 09:00:00', 30),
            $this->trade('2026-01-04 09:00:00', -90),
        ]);

        $engine = new EquityEngine();
        $result = $engine->build($trades, 1000);

        $this->assertSame([1100.0, 1050.0, 1080.0, 990.0], $result['equity_points']);
        $this->assertSame([100.0, 50.0, 80.0, -10.0], $result['cumulative_profit']);
        $this->assertSame(110.0, $result['max_drawdown']);
        $this->assertSame(10.0, round($result['max_drawdown_percent'], 4));
        $this->assertSame(110.0, $result['current_drawdown']);
    }

    public function test_streak_engine_tracks_longest_and_current_streaks(): void
    {
        $trades = collect([
            $this->trade('2026-01-01 09:00:00', 20),
            $this->trade('2026-01-02 09:00:00', 10),
            $this->trade('2026-01-03 09:00:00', -5),
            $this->trade('2026-01-04 09:00:00', -8),
            $this->trade('2026-01-05 09:00:00', -2),
        ]);

        $engine = new StreakEngine();
        $result = $engine->calculate($trades);

        $this->assertSame(2, $result['longest_win_streak']);
        $this->assertSame(3, $result['longest_loss_streak']);
        $this->assertSame('loss', $result['current_streak']['type']);
        $this->assertSame(3, $result['current_streak']['length']);
    }

    public function test_metrics_and_behavioral_analytics_are_computed_server_side(): void
    {
        $trades = collect([
            $this->trade('2026-01-01 09:00:00', 100, 2, true, 'calm', 10000),
            $this->trade('2026-01-02 09:00:00', -50, -1, false, 'fearful', 10100),
            $this->trade('2026-01-03 09:00:00', 200, 3, true, 'confident', 10050),
            $this->trade('2026-01-04 09:00:00', -100, -2, false, 'revenge', 10250),
        ]);

        $metricsEngine = new TradeMetricsEngine();
        $equityEngine = new EquityEngine();
        $drawdown = $equityEngine->build($trades, 10000);
        $metrics = $metricsEngine->calculate($trades, (float) $drawdown['max_drawdown']);

        $this->assertSame(4, $metrics['total_trades']);
        $this->assertSame(50.0, $metrics['win_rate']);
        $this->assertSame(150.0, $metrics['net_profit']);
        $this->assertSame(2.0, $metrics['profit_factor']);
        $this->assertSame(37.5, $metrics['expectancy']);

        $behavioral = new BehavioralAnalyticsEngine($metricsEngine, $equityEngine);
        $behavioralResult = $behavioral->calculate($trades, 10000);

        $this->assertArrayHasKey('discipline_comparison', $behavioralResult);
        $this->assertArrayHasKey('emotion_analytics', $behavioralResult);
        $this->assertSame('revenge', $behavioralResult['emotion_analytics']['most_costly_emotion']);
        $this->assertSame('confident', $behavioralResult['emotion_analytics']['most_profitable_mindset']);
    }

    private function trade(
        string $date,
        float $profitLoss,
        float $rMultiple = 0,
        bool $followedRules = true,
        string $emotion = 'neutral',
        float $balanceBefore = 10000
    ): object {
        return (object) [
            'date' => $date,
            'profit_loss' => $profitLoss,
            'r_multiple' => $rMultiple,
            'rr' => $rMultiple,
            'followed_rules' => $followedRules,
            'emotion' => $emotion,
            'account_balance_before_trade' => $balanceBefore,
        ];
    }
}
