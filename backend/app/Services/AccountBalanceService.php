<?php

namespace App\Services;

use App\Domain\Instruments\InstrumentMath;
use App\Domain\Instruments\InstrumentSpec;
use App\Models\Account;
use App\Models\Trade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    public function __construct(
        private readonly TradeCalculationEngine $calculationEngine,
        private readonly CurrencyConversionService $currencyConversionService,
        private readonly InstrumentMath $instrumentMath
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
            $valuationContext = $this->resolveInstrumentValuationContext($trade, $account, $tradeDate);

            $calculated = $this->calculationEngine->calculate([
                'direction' => (string) $trade->direction,
                'entry_price' => (float) $trade->entry_price,
                'stop_loss' => (float) $trade->stop_loss,
                'take_profit' => (float) $trade->take_profit,
                'actual_exit_price' => (float) $trade->actual_exit_price,
                'lot_size' => (float) $trade->lot_size,
                'instrument_tick_size' => (float) ($trade->instrument?->tick_size ?? 0),
                'instrument_tick_value' => $valuationContext['tick_value_in_account_currency'],
                'instrument_contract_size' => (float) ($trade->instrument?->contract_size ?? 0),
                'instrument_quote_to_account_rate' => $valuationContext['quote_to_account_rate'],
                'instrument_quote_currency' => strtoupper(trim((string) ($trade->instrument?->quote_currency ?? ''))),
                'instrument_base_currency' => strtoupper(trim((string) ($trade->instrument?->base_currency ?? ''))),
                'instrument_rounding_policy' => 'half_up_6',
                'account_currency' => strtoupper(trim((string) ($account->currency ?? 'USD'))),
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
                    'fx_rate_quote_to_usd' => $valuationContext['fx_rate_quote_to_usd'],
                    'fx_symbol_used' => $valuationContext['fx_symbol_used'],
                    'fx_rate_timestamp' => $valuationContext['fx_rate_provenance_at'] ?? $tradeDate->toDateTimeString(),
                    'fx_rate_used' => $valuationContext['fx_rate_used'],
                    'fx_pair_used' => $valuationContext['fx_pair_used'],
                    'fx_rate_provenance_at' => $valuationContext['fx_rate_provenance_at'],
                    'profit_loss' => $calculated['profit_loss'],
                    'rr' => $calculated['rr'],
                    'r_multiple' => $calculated['r_multiple'],
                    'avg_entry_price' => $calculated['avg_entry_price'],
                    'avg_exit_price' => $calculated['avg_exit_price'],
                    'realized_r_multiple' => $calculated['realized_r_multiple'],
                    'actual_exit_price' => $calculated['avg_exit_price'],
                    'lot_size' => (float) ($trade->legs->where('leg_type', 'entry')->sum('quantity_lots') ?: $trade->lot_size),
                    'risk_percent' => $calculated['risk_percent'],
                    'risk_amount_account_currency' => $calculated['monetary_risk'],
                    'risk_currency' => strtoupper(trim((string) ($account->currency ?? 'USD'))),
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

    /**
     * @return array{
     *   tick_value_in_account_currency:float,
     *   quote_to_account_rate:float,
     *   fx_rate_quote_to_usd:float|null,
     *   fx_symbol_used:string|null,
     *   fx_rate_used:float,
     *   fx_pair_used:string,
     *   fx_rate_provenance_at:string|null
     * }
     */
    private function resolveInstrumentValuationContext(Trade $trade, Account $account, CarbonImmutable $tradeDate): array
    {
        $spec = InstrumentSpec::fromArray([
            'contract_size' => (float) ($trade->instrument?->contract_size ?? 0),
            'tick_size' => (float) ($trade->instrument?->tick_size ?? 0),
            'tick_value' => (float) ($trade->instrument?->tick_value ?? 0),
            'quote_currency' => (string) ($trade->instrument?->quote_currency ?? ''),
            'base_currency' => (string) ($trade->instrument?->base_currency ?? ''),
            'rounding_policy' => 'half_up_6',
        ]);

        $quoteCurrency = $spec->quoteCurrency();
        $accountCurrency = strtoupper(trim((string) ($account->currency ?? 'USD')));

        $quoteToAccountRate = 1.0;
        $fxRateUsed = 1.0;
        $fxPairUsed = $quoteCurrency.$accountCurrency;
        $fxProvenanceAt = $tradeDate->toIso8601String();

        if (
            $spec->isValid()
            && $quoteCurrency !== ''
            && $accountCurrency !== ''
            && $quoteCurrency !== $accountCurrency
        ) {
            if ($quoteCurrency !== 'USD' && $accountCurrency !== 'USD') {
                $quoteToUsd = $this->currencyConversionService->resolveRateWithProvenance($quoteCurrency, 'USD', $tradeDate);
                $usdToAccount = $this->currencyConversionService->resolveRateWithProvenance('USD', $accountCurrency, $tradeDate);
                if (! is_array($quoteToUsd) || ! is_array($usdToAccount)) {
                    throw new \RuntimeException(sprintf(
                        'Missing FX conversion path for %s to %s while rebuilding account state.',
                        $quoteCurrency,
                        $accountCurrency
                    ));
                }

                $quoteToAccountRate = (float) $quoteToUsd['rate'] * (float) $usdToAccount['rate'];
                $fxRateUsed = $quoteToAccountRate;
                $fxPairUsed = (string) $quoteToUsd['pair'].'>'.(string) $usdToAccount['pair'];
                $fxProvenanceAt = $this->oldestIsoTimestamp(
                    $quoteToUsd['rate_updated_at'] ?? null,
                    $usdToAccount['rate_updated_at'] ?? null
                ) ?? $tradeDate->toIso8601String();
            } else {
                $resolved = $this->currencyConversionService->resolveRateWithProvenance($quoteCurrency, $accountCurrency, $tradeDate);
                if (! is_array($resolved) || (float) ($resolved['rate'] ?? 0) <= 0) {
                    throw new \RuntimeException(sprintf(
                        'Missing FX conversion rate for %s to %s while rebuilding account state.',
                        $quoteCurrency,
                        $accountCurrency
                    ));
                }

                $quoteToAccountRate = (float) $resolved['rate'];
                $fxRateUsed = $quoteToAccountRate;
                $fxPairUsed = (string) $resolved['pair'];
                $fxProvenanceAt = (string) ($resolved['rate_updated_at'] ?? $tradeDate->toIso8601String());
            }
        }

        $quoteToUsd = $quoteCurrency !== ''
            ? $this->currencyConversionService->resolveRateWithProvenance($quoteCurrency, 'USD', $tradeDate)
            : null;
        $quoteToUsdRate = is_array($quoteToUsd) && (float) ($quoteToUsd['rate'] ?? 0) > 0
            ? (float) $quoteToUsd['rate']
            : null;
        $quoteToUsdPair = $quoteToUsdRate !== null
            ? (string) ($quoteToUsd['pair'] ?? $quoteCurrency.'USD')
            : null;

        $tickValueInAccount = $spec->isValid()
            ? $this->instrumentMath->tickValueInAccountCurrency($spec, $quoteToAccountRate)
            : (float) ($trade->instrument?->tick_value ?? 0) * $quoteToAccountRate;

        return [
            'tick_value_in_account_currency' => $tickValueInAccount,
            'quote_to_account_rate' => $quoteToAccountRate,
            'fx_rate_quote_to_usd' => $quoteToUsdRate,
            'fx_symbol_used' => $quoteToUsdPair,
            'fx_rate_used' => $fxRateUsed,
            'fx_pair_used' => $fxPairUsed,
            'fx_rate_provenance_at' => $fxProvenanceAt,
        ];
    }

    private function oldestIsoTimestamp(?string $first, ?string $second): ?string
    {
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }

        try {
            $a = CarbonImmutable::parse($first);
            $b = CarbonImmutable::parse($second);

            return $a->lessThanOrEqualTo($b)
                ? $a->toIso8601String()
                : $b->toIso8601String();
        } catch (\Throwable) {
            return $first;
        }
    }
}
