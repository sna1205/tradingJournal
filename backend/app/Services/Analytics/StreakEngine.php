<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

class StreakEngine
{
    /**
     * @param Collection<int, object> $trades
     * @return array{
     *   longest_win_streak:int,
     *   longest_loss_streak:int,
     *   current_win_streak:int,
     *   current_loss_streak:int,
     *   current_streak:array{type:string,length:int}
     * }
     */
    public function calculate(Collection $trades): array
    {
        $currentWinStreak = 0;
        $currentLossStreak = 0;
        $longestWinStreak = 0;
        $longestLossStreak = 0;

        $currentType = 'flat';
        $currentLength = 0;

        foreach ($trades as $trade) {
            $profitLoss = (float) ($trade->profit_loss ?? 0);

            if ($profitLoss > 0) {
                $currentWinStreak++;
                $currentLossStreak = 0;
                $longestWinStreak = max($longestWinStreak, $currentWinStreak);
                $currentType = 'win';
                $currentLength = $currentWinStreak;
                continue;
            }

            if ($profitLoss < 0) {
                $currentLossStreak++;
                $currentWinStreak = 0;
                $longestLossStreak = max($longestLossStreak, $currentLossStreak);
                $currentType = 'loss';
                $currentLength = $currentLossStreak;
                continue;
            }

            $currentWinStreak = 0;
            $currentLossStreak = 0;
            $currentType = 'flat';
            $currentLength = 0;
        }

        return [
            'longest_win_streak' => $longestWinStreak,
            'longest_loss_streak' => $longestLossStreak,
            'current_win_streak' => $currentWinStreak,
            'current_loss_streak' => $currentLossStreak,
            'current_streak' => [
                'type' => $currentType,
                'length' => $currentLength,
            ],
        ];
    }
}

