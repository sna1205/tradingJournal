<?php

namespace App\Domain\Instruments;

use App\Domain\Money\Money;

final class InstrumentMath
{
    public function tickValueInQuoteCurrency(InstrumentSpec $spec): float
    {
        return $spec->contractSize() * $spec->tickSize();
    }

    public function tickValueInAccountCurrency(InstrumentSpec $spec, float $quoteToAccountRate): float
    {
        return $this->roundByPolicy(
            $this->tickValueInQuoteCurrency($spec) * $quoteToAccountRate,
            $spec->roundingScale()
        );
    }

    public function quoteValueFromPriceDistance(float $priceDistance, float $positionSizeLots, InstrumentSpec $spec): Money
    {
        $quoteValue = $priceDistance * $spec->contractSize() * $positionSizeLots;

        return Money::fromFloat(
            $this->roundByPolicy($quoteValue, $spec->roundingScale()),
            $spec->quoteCurrency(),
            $spec->roundingScale()
        );
    }

    public function accountValueFromPriceDistance(
        float $priceDistance,
        float $positionSizeLots,
        InstrumentSpec $spec,
        float $quoteToAccountRate,
        string $accountCurrency
    ): Money {
        $quoteMoney = $this->quoteValueFromPriceDistance($priceDistance, $positionSizeLots, $spec);

        return $quoteMoney->converted(
            $quoteToAccountRate,
            strtoupper(trim($accountCurrency)) !== '' ? strtoupper(trim($accountCurrency)) : 'USD'
        );
    }

    private function roundByPolicy(float $value, int $scale): float
    {
        return round($value, $scale, PHP_ROUND_HALF_UP);
    }
}
