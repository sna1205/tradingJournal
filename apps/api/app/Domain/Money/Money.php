<?php

namespace App\Domain\Money;

final class Money
{
    public function __construct(
        private readonly int $minorUnits,
        private readonly string $currency,
        private readonly int $scale = 6
    ) {
    }

    public static function fromFloat(float $amount, string $currency, int $scale = 6): self
    {
        $factor = 10 ** $scale;

        return new self(
            (int) round($amount * $factor, 0, PHP_ROUND_HALF_UP),
            strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'USD',
            $scale
        );
    }

    public function toFloat(): float
    {
        $factor = 10 ** $this->scale;

        return $this->minorUnits / $factor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function converted(float $rate, string $targetCurrency): self
    {
        return self::fromFloat(
            $this->toFloat() * $rate,
            $targetCurrency,
            $this->scale
        );
    }
}
