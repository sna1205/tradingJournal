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
            ->with('instrument')
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
                'instrument_tick_size' => (float) ($trade->instrument?->tick_size ?? 0),
                'instrument_tick_value' => (float) ($trade->instrument?->tick_value ?? 0),
                'commission' => (float) ($trade->commission ?? 0),
                'swap' => (float) ($trade->swap ?? 0),
                'spread_cost' => (float) ($trade->spread_cost ?? 0),
                'slippage_cost' => (float) ($trade->slippage_cost ?? 0),
                'account_balance_before_trade' => $runningBalance,
            ]);

            DB::table('trades')
                ->where('id', $trade->id)
                ->update([
                    'risk_per_unit' => $calculated['risk_per_unit'],
                    'reward_per_unit' => $calculated['reward_per_unit'],
                    'monetary_risk' => $calculated['monetary_risk'],
                    'monetary_reward' => $calculated['monetary_reward'],
                    'gross_profit_loss' => $calculated['gross_profit_loss'],
                    'costs_total' => $calculated['costs_total'],
                    'commission' => $calculated['commission'],
                    'swap' => $calculated['swap'],
                    'spread_cost' => $calculated['spread_cost'],
                    'slippage_cost' => $calculated['slippage_cost'],
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
