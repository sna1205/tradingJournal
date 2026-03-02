<?php

namespace App\Services;

use App\Domain\Instruments\InstrumentMath;
use App\Domain\Instruments\InstrumentSpec;

class TradeCalculationEngine
{
    private readonly InstrumentMath $instrumentMath;

    public function __construct(?InstrumentMath $instrumentMath = null)
    {
        $this->instrumentMath = $instrumentMath ?? new InstrumentMath();
    }

    /**
     * @param array{
     *   direction:string,
     *   entry_price:numeric-string|int|float,
     *   stop_loss:numeric-string|int|float,
     *   take_profit:numeric-string|int|float,
     *   actual_exit_price?:numeric-string|int|float|null,
     *   lot_size?:numeric-string|int|float|null,
     *   account_balance_before_trade:numeric-string|int|float,
     *   instrument_tick_size?:numeric-string|int|float|null,
     *   instrument_tick_value?:numeric-string|int|float|null,
     *   instrument_contract_size?:numeric-string|int|float|null,
     *   instrument_quote_to_account_rate?:numeric-string|int|float|null,
     *   instrument_quote_currency?:string|null,
     *   instrument_base_currency?:string|null,
     *   instrument_rounding_policy?:string|null,
     *   account_currency?:string|null,
     *   commission?:numeric-string|int|float|null,
     *   swap?:numeric-string|int|float|null,
     *   spread_cost?:numeric-string|int|float|null,
     *   slippage_cost?:numeric-string|int|float|null,
     *   legs?:array<int, array<string,mixed>|object>|null
     * } $input
     * @return array{
     *   risk_per_unit:float,
     *   reward_per_unit:float,
     *   monetary_risk:float,
     *   monetary_reward:float,
     *   gross_profit_loss:float,
     *   costs_total:float,
     *   commission:float,
     *   swap:float,
     *   spread_cost:float,
     *   slippage_cost:float,
     *   profit_loss:float,
     *   rr:float,
     *   r_multiple:float,
     *   avg_entry_price:float,
     *   avg_exit_price:float,
     *   realized_r_multiple:float,
     *   risk_percent:float,
     *   account_balance_after_trade:float
     * }
     */
    public function calculate(array $input): array
    {
        $legs = $this->normalizeLegs($input['legs'] ?? null);
        if (count($legs) > 0) {
            return $this->calculateWithLegs($input, $legs);
        }

        return $this->calculateSingleLeg($input);
    }

    /**
     * @param array{
     *   direction:string,
     *   entry_price:numeric-string|int|float,
     *   stop_loss:numeric-string|int|float,
     *   take_profit:numeric-string|int|float,
     *   actual_exit_price?:numeric-string|int|float|null,
     *   lot_size?:numeric-string|int|float|null,
     *   account_balance_before_trade:numeric-string|int|float,
     *   instrument_tick_size?:numeric-string|int|float|null,
     *   instrument_tick_value?:numeric-string|int|float|null,
     *   instrument_contract_size?:numeric-string|int|float|null,
     *   instrument_quote_to_account_rate?:numeric-string|int|float|null,
     *   instrument_quote_currency?:string|null,
     *   instrument_base_currency?:string|null,
     *   instrument_rounding_policy?:string|null,
     *   account_currency?:string|null,
     *   commission?:numeric-string|int|float|null,
     *   swap?:numeric-string|int|float|null,
     *   spread_cost?:numeric-string|int|float|null,
     *   slippage_cost?:numeric-string|int|float|null
     * } $input
     */
    private function calculateSingleLeg(array $input): array
    {
        $direction = strtolower((string) $input['direction']);
        $entryPrice = (float) $input['entry_price'];
        $stopLoss = (float) $input['stop_loss'];
        $takeProfit = (float) $input['take_profit'];
        $actualExitPrice = (float) ($input['actual_exit_price'] ?? $entryPrice);
        $positionSize = (float) ($input['lot_size'] ?? 0);
        $accountBalanceBeforeTrade = (float) $input['account_balance_before_trade'];
        $instrumentContext = $this->resolveInstrumentContext($input);

        [$commission, $swap, $spreadCost, $slippageCost] = $this->extractCosts($input);
        $costsTotal = $commission + $swap + $spreadCost + $slippageCost;

        $riskPerUnit = abs($entryPrice - $stopLoss);
        $rewardPerUnit = abs($takeProfit - $entryPrice);
        $monetaryRisk = $this->valueFromPriceDistance($riskPerUnit, $positionSize, $instrumentContext);
        $monetaryReward = $this->valueFromPriceDistance($rewardPerUnit, $positionSize, $instrumentContext);

        $profitPerUnit = $direction === 'sell'
            ? ($entryPrice - $actualExitPrice)
            : ($actualExitPrice - $entryPrice);
        $grossProfitLoss = $this->valueFromPriceDistance($profitPerUnit, $positionSize, $instrumentContext);
        $profitLoss = $grossProfitLoss - $costsTotal;

        $rr = $monetaryRisk > 0
            ? $monetaryReward / $monetaryRisk
            : 0.0;
        $rMultiple = $monetaryRisk > 0
            ? $profitLoss / $monetaryRisk
            : 0.0;
        $riskPercent = $accountBalanceBeforeTrade > 0
            ? ($monetaryRisk / $accountBalanceBeforeTrade) * 100
            : 0.0;
        $accountBalanceAfterTrade = $accountBalanceBeforeTrade + $profitLoss;

        return [
            'risk_per_unit' => round($riskPerUnit, 6),
            'reward_per_unit' => round($rewardPerUnit, 6),
            'monetary_risk' => round($monetaryRisk, 6),
            'monetary_reward' => round($monetaryReward, 6),
            'gross_profit_loss' => round($grossProfitLoss, 6),
            'costs_total' => round($costsTotal, 6),
            'commission' => round($commission, 6),
            'swap' => round($swap, 6),
            'spread_cost' => round($spreadCost, 6),
            'slippage_cost' => round($slippageCost, 6),
            'profit_loss' => round($profitLoss, 2),
            'rr' => round($rr, 2),
            'r_multiple' => round($rMultiple, 4),
            'avg_entry_price' => round($entryPrice, 6),
            'avg_exit_price' => round($actualExitPrice, 6),
            'realized_r_multiple' => round($rMultiple, 4),
            'risk_percent' => round($riskPercent, 4),
            'account_balance_after_trade' => round($accountBalanceAfterTrade, 2),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float
     * }> $legs
     */
    private function calculateWithLegs(array $input, array $legs): array
    {
        $direction = strtolower((string) ($input['direction'] ?? 'buy'));
        $entryPriceFallback = (float) ($input['entry_price'] ?? 0);
        $stopLoss = (float) ($input['stop_loss'] ?? 0);
        $takeProfit = (float) ($input['take_profit'] ?? 0);
        $actualExitFallback = (float) ($input['actual_exit_price'] ?? $entryPriceFallback);
        $positionSizeFallback = (float) ($input['lot_size'] ?? 0);
        $accountBalanceBeforeTrade = (float) ($input['account_balance_before_trade'] ?? 0);
        $instrumentContext = $this->resolveInstrumentContext($input);

        [$commission, $swap, $spreadCost, $slippageCost] = $this->extractCosts($input);
        $legFeesTotal = array_sum(array_column($legs, 'fees'));

        $entryLegs = array_values(array_filter($legs, fn (array $leg): bool => $leg['leg_type'] === 'entry'));
        $exitLegs = array_values(array_filter($legs, fn (array $leg): bool => $leg['leg_type'] === 'exit'));

        $totalEntryQty = array_sum(array_column($entryLegs, 'quantity_lots'));
        $effectivePositionSize = $totalEntryQty > 0 ? $totalEntryQty : $positionSizeFallback;

        $avgEntryPrice = $this->weightedAveragePrice($entryLegs, $entryPriceFallback);
        $avgExitPrice = $this->weightedAveragePrice($exitLegs, $actualExitFallback);

        $riskPerUnit = abs($avgEntryPrice - $stopLoss);
        $rewardPerUnit = abs($takeProfit - $avgEntryPrice);
        $monetaryRisk = $this->valueFromPriceDistance($riskPerUnit, $effectivePositionSize, $instrumentContext);
        $monetaryReward = $this->valueFromPriceDistance($rewardPerUnit, $effectivePositionSize, $instrumentContext);

        $grossProfitLoss = $this->realizedGrossFromLegs(
            $legs,
            $direction,
            $instrumentContext,
            $avgEntryPrice,
            $effectivePositionSize
        );

        $costsTotal = $commission + $swap + $spreadCost + $slippageCost + $legFeesTotal;
        $profitLoss = $grossProfitLoss - $costsTotal;

        $rr = $monetaryRisk > 0
            ? $monetaryReward / $monetaryRisk
            : 0.0;
        $rMultiple = $monetaryRisk > 0
            ? $profitLoss / $monetaryRisk
            : 0.0;
        $riskPercent = $accountBalanceBeforeTrade > 0
            ? ($monetaryRisk / $accountBalanceBeforeTrade) * 100
            : 0.0;
        $accountBalanceAfterTrade = $accountBalanceBeforeTrade + $profitLoss;

        return [
            'risk_per_unit' => round($riskPerUnit, 6),
            'reward_per_unit' => round($rewardPerUnit, 6),
            'monetary_risk' => round($monetaryRisk, 6),
            'monetary_reward' => round($monetaryReward, 6),
            'gross_profit_loss' => round($grossProfitLoss, 6),
            'costs_total' => round($costsTotal, 6),
            'commission' => round($commission, 6),
            'swap' => round($swap, 6),
            'spread_cost' => round($spreadCost, 6),
            'slippage_cost' => round($slippageCost, 6),
            'profit_loss' => round($profitLoss, 2),
            'rr' => round($rr, 2),
            'r_multiple' => round($rMultiple, 4),
            'avg_entry_price' => round($avgEntryPrice, 6),
            'avg_exit_price' => round($avgExitPrice, 6),
            'realized_r_multiple' => round($rMultiple, 4),
            'risk_percent' => round($riskPercent, 4),
            'account_balance_after_trade' => round($accountBalanceAfterTrade, 2),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:float,1:float,2:float,3:float}
     */
    private function extractCosts(array $input): array
    {
        $commission = (float) ($input['commission'] ?? 0);
        $swap = (float) ($input['swap'] ?? 0);
        $spreadCost = (float) ($input['spread_cost'] ?? 0);
        $slippageCost = (float) ($input['slippage_cost'] ?? 0);

        return [$commission, $swap, $spreadCost, $slippageCost];
    }

    /**
     * @param mixed $legsInput
     * @return array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float
     * }>
     */
    private function normalizeLegs(mixed $legsInput): array
    {
        if (!is_array($legsInput)) {
            return [];
        }

        $normalized = [];
        foreach ($legsInput as $index => $leg) {
            $row = is_array($leg)
                ? $leg
                : (is_object($leg) ? (array) $leg : null);
            if ($row === null) {
                continue;
            }

            $legType = strtolower((string) ($row['leg_type'] ?? ''));
            if (!in_array($legType, ['entry', 'exit'], true)) {
                continue;
            }

            $price = (float) ($row['price'] ?? 0);
            $quantityLots = (float) ($row['quantity_lots'] ?? 0);
            if ($price <= 0 || $quantityLots <= 0) {
                continue;
            }

            $executedAtRaw = (string) ($row['executed_at'] ?? '');
            $timestamp = strtotime($executedAtRaw);
            if ($timestamp === false) {
                $timestamp = time() + (int) $index;
            }
            $executedAt = date('c', $timestamp);

            $normalized[] = [
                'leg_type' => $legType,
                'price' => $price,
                'quantity_lots' => $quantityLots,
                'executed_at' => $executedAt,
                'fees' => (float) ($row['fees'] ?? 0),
            ];
        }

        usort(
            $normalized,
            fn (array $left, array $right): int => strcmp($left['executed_at'], $right['executed_at'])
        );

        return $normalized;
    }

    /**
     * @param array<int,array{price:float,quantity_lots:float}> $legs
     */
    private function weightedAveragePrice(array $legs, float $fallback): float
    {
        $totalQty = array_sum(array_column($legs, 'quantity_lots'));
        if ($totalQty <= 0) {
            return $fallback;
        }

        $weighted = array_reduce(
            $legs,
            fn (float $sum, array $leg): float => $sum + ($leg['price'] * $leg['quantity_lots']),
            0.0
        );

        return $weighted / $totalQty;
    }

    /**
     * @param array<int,array{
     *   leg_type:string,
     *   price:float,
     *   quantity_lots:float,
     *   executed_at:string,
     *   fees:float
     * }> $legs
     */
    private function realizedGrossFromLegs(
        array $legs,
        string $direction,
        array $instrumentContext,
        float $entryPriceFallback,
        float $positionSizeFallback
    ): float {
        $hasEntryLegs = array_reduce(
            $legs,
            fn (bool $carry, array $leg): bool => $carry || $leg['leg_type'] === 'entry',
            false
        );

        $openQty = $hasEntryLegs ? 0.0 : max(0.0, $positionSizeFallback);
        $openAveragePrice = $entryPriceFallback;
        $realized = 0.0;

        foreach ($legs as $leg) {
            $qty = (float) $leg['quantity_lots'];
            if ($qty <= 0) {
                continue;
            }

            if ($leg['leg_type'] === 'entry') {
                $combinedQty = $openQty + $qty;
                if ($combinedQty > 0) {
                    $openAveragePrice = (($openAveragePrice * $openQty) + ($leg['price'] * $qty)) / $combinedQty;
                }
                $openQty = $combinedQty;
                continue;
            }

            $closeQty = min($qty, $openQty);
            if ($closeQty <= 0) {
                continue;
            }

            $profitPerUnit = $direction === 'sell'
                ? ($openAveragePrice - $leg['price'])
                : ($leg['price'] - $openAveragePrice);
            $realized += $this->valueFromPriceDistance($profitPerUnit, $closeQty, $instrumentContext);

            $openQty -= $closeQty;
            if ($openQty <= 0.0000001) {
                $openQty = 0.0;
                $openAveragePrice = $entryPriceFallback;
            }
        }

        return $realized;
    }

    private function valueFromPriceDistance(
        float $priceDistance,
        float $positionSize,
        array $instrumentContext
    ): float {
        /** @var InstrumentSpec|null $spec */
        $spec = $instrumentContext['spec'] ?? null;
        $quoteToAccountRate = (float) ($instrumentContext['quote_to_account_rate'] ?? 1.0);
        $accountCurrency = (string) ($instrumentContext['account_currency'] ?? 'USD');
        if ($spec instanceof InstrumentSpec) {
            return $this->instrumentMath->accountValueFromPriceDistance(
                $priceDistance,
                $positionSize,
                $spec,
                $quoteToAccountRate,
                $accountCurrency
            )->toFloat();
        }

        $tickSize = (float) ($instrumentContext['tick_size'] ?? 0);
        $tickValue = (float) ($instrumentContext['tick_value'] ?? 0);
        if ($tickSize > 0 && $tickValue > 0) {
            $ticks = $priceDistance / $tickSize;
            return $ticks * $tickValue * $positionSize;
        }

        // Legacy fallback for historical rows without instrument specs.
        return $priceDistance * $positionSize;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   spec:InstrumentSpec|null,
     *   quote_to_account_rate:float,
     *   account_currency:string,
     *   tick_size:float,
     *   tick_value:float
     * }
     */
    private function resolveInstrumentContext(array $input): array
    {
        $spec = InstrumentSpec::fromArray([
            'contract_size' => (float) ($input['instrument_contract_size'] ?? 0),
            'tick_size' => (float) ($input['instrument_tick_size'] ?? 0),
            'tick_value' => (float) ($input['instrument_tick_value'] ?? 0),
            'quote_currency' => (string) ($input['instrument_quote_currency'] ?? ''),
            'base_currency' => (string) ($input['instrument_base_currency'] ?? ''),
            'rounding_policy' => (string) ($input['instrument_rounding_policy'] ?? 'half_up_6'),
        ]);

        $quoteToAccountRate = (float) ($input['instrument_quote_to_account_rate'] ?? 1.0);
        if ($quoteToAccountRate <= 0) {
            $quoteToAccountRate = 1.0;
        }

        return [
            'spec' => $spec->isValid() ? $spec : null,
            'quote_to_account_rate' => $quoteToAccountRate,
            'account_currency' => strtoupper(trim((string) ($input['account_currency'] ?? 'USD'))),
            'tick_size' => (float) ($input['instrument_tick_size'] ?? 0),
            'tick_value' => (float) ($input['instrument_tick_value'] ?? 0),
        ];
    }
}
