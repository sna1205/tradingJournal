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

        $confidenceBuckets = $this->bucketPsychologyByScore($trades, 'confidence_score');
        $stressBuckets = $this->bucketPsychologyByScore($trades, 'stress_score');
        $psychologyFlags = $this->psychologyFlagsImpact($trades);

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
            'psychology_correlations' => [
                'confidence_buckets' => $confidenceBuckets,
                'stress_buckets' => $stressBuckets,
                'flags' => $psychologyFlags,
            ],
        ];
    }

    /**
     * @param Collection<int, object> $trades
     * @return array<int,array{
     *   bucket:string,
     *   total_trades:int,
     *   expectancy_money:float,
     *   expectancy_r:float,
     *   win_rate:float,
     *   rule_break_rate:float
     * }>
     */
    private function bucketPsychologyByScore(Collection $trades, string $scoreField): array
    {
        $ranges = [
            ['label' => '1-3', 'min' => 1, 'max' => 3],
            ['label' => '4-6', 'min' => 4, 'max' => 6],
            ['label' => '7-8', 'min' => 7, 'max' => 8],
            ['label' => '9-10', 'min' => 9, 'max' => 10],
        ];

        $rows = [];
        foreach ($ranges as $range) {
            $subset = $trades
                ->filter(function ($trade) use ($scoreField, $range): bool {
                    $value = (int) (data_get($trade, "psychology.$scoreField") ?? 0);
                    return $value >= $range['min'] && $value <= $range['max'];
                })
                ->values();

            $totalTrades = $subset->count();
            $metrics = $this->metricsEngine->calculate($subset);
            $ruleBreaks = $subset->filter(fn ($trade): bool => !((bool) (data_get($trade, 'followed_rules') ?? false)))->count();

            $rows[] = [
                'bucket' => (string) $range['label'],
                'total_trades' => $totalTrades,
                'expectancy_money' => (float) ($metrics['expectancy_money'] ?? 0),
                'expectancy_r' => (float) ($metrics['expectancy_r'] ?? 0),
                'win_rate' => (float) ($metrics['win_rate'] ?? 0),
                'rule_break_rate' => $totalTrades > 0
                    ? round(($ruleBreaks / $totalTrades) * 100, 2)
                    : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param Collection<int, object> $trades
     * @return array<string,array{
     *   total_trades:int,
     *   expectancy_money:float,
     *   expectancy_r:float,
     *   rule_break_rate:float
     * }>
     */
    private function psychologyFlagsImpact(Collection $trades): array
    {
        $result = [];
        foreach (['impulse_flag', 'fomo_flag', 'revenge_flag'] as $flagField) {
            $subset = $trades
                ->filter(fn ($trade): bool => (bool) (data_get($trade, "psychology.$flagField") ?? false))
                ->values();
            $totalTrades = $subset->count();
            $metrics = $this->metricsEngine->calculate($subset);
            $ruleBreaks = $subset->filter(fn ($trade): bool => !((bool) (data_get($trade, 'followed_rules') ?? false)))->count();

            $result[$flagField] = [
                'total_trades' => $totalTrades,
                'expectancy_money' => (float) ($metrics['expectancy_money'] ?? 0),
                'expectancy_r' => (float) ($metrics['expectancy_r'] ?? 0),
                'rule_break_rate' => $totalTrades > 0
                    ? round(($ruleBreaks / $totalTrades) * 100, 2)
                    : 0.0,
            ];
        }

        return $result;
    }
}
