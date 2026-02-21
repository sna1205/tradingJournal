<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

class BehavioralAnalyticsEngine
{
    public function __construct(
        private readonly TradeMetricsEngine $metricsEngine,
        private readonly EquityEngine $equityEngine
    ) {
    }

    /**
     * @param Collection<int, object> $trades
     * @return array<string, mixed>
     */
    public function calculate(Collection $trades, float $startingBalance): array
    {
        $followedRulesTrades = $trades
            ->filter(fn ($trade) => (bool) (data_get($trade, 'followed_rules') ?? false))
            ->values();
        $brokeRulesTrades = $trades
            ->filter(fn ($trade) => !((bool) (data_get($trade, 'followed_rules') ?? false)))
            ->values();

        $followedRulesDrawdown = $this->equityEngine->build($followedRulesTrades, $startingBalance);
        $brokeRulesDrawdown = $this->equityEngine->build($brokeRulesTrades, $startingBalance);

        $followedRulesMetrics = [
            ...$this->metricsEngine->calculate($followedRulesTrades, (float) $followedRulesDrawdown['max_drawdown']),
            'max_drawdown' => $followedRulesDrawdown['max_drawdown'],
            'max_drawdown_percent' => $followedRulesDrawdown['max_drawdown_percent'],
        ];
        $brokeRulesMetrics = [
            ...$this->metricsEngine->calculate($brokeRulesTrades, (float) $brokeRulesDrawdown['max_drawdown']),
            'max_drawdown' => $brokeRulesDrawdown['max_drawdown'],
            'max_drawdown_percent' => $brokeRulesDrawdown['max_drawdown_percent'],
        ];

        $followedExpectancy = (float) ($followedRulesMetrics['expectancy'] ?? 0);
        $brokeExpectancy = (float) ($brokeRulesMetrics['expectancy'] ?? 0);

        $emotionBreakdown = $trades
            ->groupBy(fn ($trade) => (string) (data_get($trade, 'emotion') ?: 'unknown'))
            ->map(function (Collection $emotionTrades, string $emotion) use ($startingBalance): array {
                $drawdown = $this->equityEngine->build($emotionTrades->values(), $startingBalance);
                $metrics = $this->metricsEngine->calculate($emotionTrades->values(), (float) $drawdown['max_drawdown']);

                return [
                    'emotion' => $emotion,
                    ...$metrics,
                    'total_profit' => $metrics['net_profit'],
                    'max_drawdown' => $drawdown['max_drawdown'],
                    'max_drawdown_percent' => $drawdown['max_drawdown_percent'],
                ];
            })
            ->values();

        $mostCostlyEmotion = $emotionBreakdown
            ->sortBy('total_profit')
            ->first();
        $mostProfitableMindset = $emotionBreakdown
            ->sortByDesc('total_profit')
            ->first();

        return [
            'discipline_comparison' => [
                'followed_rules' => $followedRulesMetrics,
                'broke_rules' => $brokeRulesMetrics,
                'insight' => [
                    'when_follow_rules' => sprintf(
                        'When you follow rules, expectancy = %.4f',
                        $followedExpectancy
                    ),
                    'when_break_rules' => sprintf(
                        'When you break rules, expectancy = %.4f',
                        $brokeExpectancy
                    ),
                ],
            ],
            'emotion_analytics' => [
                'breakdown' => $emotionBreakdown->all(),
                'most_costly_emotion' => data_get($mostCostlyEmotion, 'emotion'),
                'most_profitable_mindset' => data_get($mostProfitableMindset, 'emotion'),
            ],
        ];
    }
}
