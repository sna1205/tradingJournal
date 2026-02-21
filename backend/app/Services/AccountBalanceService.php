<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Trade;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    public function __construct(
        private readonly TradeCalculationEngine $calculationEngine
    ) {
    }

    public function rebuildAccountState(int $accountId): void
    {
        $account = Account::query()
            ->lockForUpdate()
            ->find($accountId);

        if (!$account) {
            return;
        }

        $runningBalance = (float) $account->starting_balance;
        $trades = Trade::query()
            ->where('account_id', $accountId)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($trades as $trade) {
            $calculated = $this->calculationEngine->calculate([
                'direction' => (string) $trade->direction,
                'entry_price' => (float) $trade->entry_price,
                'stop_loss' => (float) $trade->stop_loss,
                'take_profit' => (float) $trade->take_profit,
                'actual_exit_price' => (float) $trade->actual_exit_price,
                'lot_size' => (float) $trade->lot_size,
                'account_balance_before_trade' => $runningBalance,
            ]);

            DB::table('trades')
                ->where('id', $trade->id)
                ->update([
                    'risk_per_unit' => $calculated['risk_per_unit'],
                    'reward_per_unit' => $calculated['reward_per_unit'],
                    'monetary_risk' => $calculated['monetary_risk'],
                    'monetary_reward' => $calculated['monetary_reward'],
                    'profit_loss' => $calculated['profit_loss'],
                    'rr' => $calculated['rr'],
                    'r_multiple' => $calculated['r_multiple'],
                    'risk_percent' => $calculated['risk_percent'],
                    'account_balance_before_trade' => round($runningBalance, 2),
                    'account_balance_after_trade' => $calculated['account_balance_after_trade'],
                    'updated_at' => now(),
                ]);

            $runningBalance = (float) $calculated['account_balance_after_trade'];
        }

        $account->update([
            'current_balance' => round($runningBalance, 2),
        ]);
    }

    /**
     * @param array<int, int|string|null> $accountIds
     */
    public function rebuildMany(array $accountIds): void
    {
        collect($accountIds)
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->each(fn (int $accountId) => $this->rebuildAccountState($accountId));
    }
}
