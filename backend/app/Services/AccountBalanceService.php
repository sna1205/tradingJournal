<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Trade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    public function __construct(
        private readonly TradeCalculationEngine $calculationEngine,
        private readonly CurrencyConversionService $currencyConversionService
    ) {}

    public function rebuildAccountState(int $accountId): void
    {
        $account = Account::query()
            ->lockForUpdate()
            ->find($accountId);

        if (! $account) {
            return;
        }

        $runningBalance = (float) $account->starting_balance;
        $trades = Trade::query()
            ->where('account_id', $accountId)
            ->with(['instrument', 'legs'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($trades as $trade) {
            $tradeDate = $trade->date !== null
                ? CarbonImmutable::parse((string) $trade->date)
                : CarbonImmutable::now();
            $tickValueInAccountCurrency = (float) ($trade->instrument?->tick_value ?? 0);
            $quoteCurrency = strtoupper(trim((string) ($trade->instrument?->quote_currency ?? '')));
            $accountCurrency = strtoupper(trim((string) ($account->currency ?? 'USD')));
            if (
                $tickValueInAccountCurrency > 0
                && $quoteCurrency !== ''
                && $accountCurrency !== ''
                && $quoteCurrency !== $accountCurrency
            ) {
                $quoteToAccountRate = $this->currencyConversionService
                    ->resolveRateOrNull($quoteCurrency, $accountCurrency, $tradeDate);
                if ($quoteToAccountRate === null || $quoteToAccountRate <= 0) {
                    throw new \RuntimeException(sprintf(
                        'Missing FX conversion rate for %s to %s while rebuilding account state.',
                        $quoteCurrency,
                        $accountCurrency
                    ));
                }
                $tickValueInAccountCurrency *= $quoteToAccountRate;
            }

            $quoteToUsd = $quoteCurrency !== ''
                ? $this->currencyConversionService->resolveRateOrNull($quoteCurrency, 'USD', $tradeDate)
                : null;

            $calculated = $this->calculationEngine->calculate([
                'direction' => (string) $trade->direction,
                'entry_price' => (float) $trade->entry_price,
                'stop_loss' => (float) $trade->stop_loss,
                'take_profit' => (float) $trade->take_profit,
                'actual_exit_price' => (float) $trade->actual_exit_price,
                'lot_size' => (float) $trade->lot_size,
                'instrument_tick_size' => (float) ($trade->instrument?->tick_size ?? 0),
                'instrument_tick_value' => $tickValueInAccountCurrency,
                'commission' => (float) ($trade->commission ?? 0),
                'swap' => (float) ($trade->swap ?? 0),
                'spread_cost' => (float) ($trade->spread_cost ?? 0),
                'slippage_cost' => (float) ($trade->slippage_cost ?? 0),
                'legs' => $trade->legs
                    ->map(fn ($leg): array => [
                        'leg_type' => (string) $leg->leg_type,
                        'price' => (float) $leg->price,
                        'quantity_lots' => (float) $leg->quantity_lots,
                        'executed_at' => (string) $leg->executed_at,
                        'fees' => (float) ($leg->fees ?? 0),
                    ])
                    ->all(),
                'account_balance_before_trade' => $runningBalance,
            ]);

            DB::table('trades')
                ->where('id', $trade->id)
                ->update([
                    'entry_price' => $calculated['avg_entry_price'],
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
                    'fx_rate_quote_to_usd' => $quoteToUsd !== null && $quoteToUsd > 0 ? $quoteToUsd : null,
                    'fx_symbol_used' => $quoteCurrency !== '' && $quoteCurrency !== 'USD' ? "{$quoteCurrency}USD" : null,
                    'fx_rate_timestamp' => $tradeDate->toDateTimeString(),
                    'profit_loss' => $calculated['profit_loss'],
                    'rr' => $calculated['rr'],
                    'r_multiple' => $calculated['r_multiple'],
                    'avg_entry_price' => $calculated['avg_entry_price'],
                    'avg_exit_price' => $calculated['avg_exit_price'],
                    'realized_r_multiple' => $calculated['realized_r_multiple'],
                    'actual_exit_price' => $calculated['avg_exit_price'],
                    'lot_size' => (float) ($trade->legs->where('leg_type', 'entry')->sum('quantity_lots') ?: $trade->lot_size),
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
     * @param  array<int, int|string|null>  $accountIds
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
