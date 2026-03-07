<?php

namespace App\Domain\Instruments;

final class InstrumentSpec
{
    public function __construct(
        private readonly float $contractSize,
        private readonly float $tickSize,
        private readonly float $tickValue,
        private readonly string $quoteCurrency,
        private readonly string $baseCurrency,
        private readonly string $roundingPolicy = 'half_up_6'
    ) {
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            (float) ($input['contract_size'] ?? 0),
            (float) ($input['tick_size'] ?? 0),
            (float) ($input['tick_value'] ?? 0),
            (string) ($input['quote_currency'] ?? 'USD'),
            (string) ($input['base_currency'] ?? ''),
            (string) ($input['rounding_policy'] ?? 'half_up_6')
        );
    }

    public function contractSize(): float
    {
        return $this->contractSize;
    }

    public function tickSize(): float
    {
        return $this->tickSize;
    }

    public function tickValue(): float
    {
        return $this->tickValue;
    }

    public function quoteCurrency(): string
    {
        return strtoupper(trim($this->quoteCurrency));
    }

    public function baseCurrency(): string
    {
        return strtoupper(trim($this->baseCurrency));
    }

    public function roundingPolicy(): string
    {
        return $this->roundingPolicy;
    }

    public function roundingScale(): int
    {
        if (preg_match('/_(\d+)$/', $this->roundingPolicy, $matches) === 1) {
            return max(0, min(8, (int) $matches[1]));
        }

        return 6;
    }

    public function isValid(): bool
    {
        return $this->contractSize > 0 && $this->tickSize > 0 && $this->quoteCurrency() !== '';
    }
}
