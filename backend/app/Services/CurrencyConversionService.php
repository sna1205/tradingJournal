<?php

namespace App\Services;

use App\Models\FxRate;
use App\Models\FxRateSnapshot;
use Carbon\CarbonInterface;

class CurrencyConversionService
{
    /**
     * @var array<string, float>
     */
    private array $rateCache = [];

    public function convert(float $amount, string $fromCurrency, string $toCurrency, ?CarbonInterface $asOf = null): float
    {
        $rate = $this->resolveRate($fromCurrency, $toCurrency, $asOf);

        return round($amount * $rate, 6);
    }

    public function resolveRate(string $fromCurrency, string $toCurrency, ?CarbonInterface $asOf = null): float
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        if ($from === '' || $to === '' || $from === $to) {
            return 1.0;
        }

        $dateKey = $asOf?->toDateString() ?? 'latest';
        $cacheKey = "{$from}:{$to}:{$dateKey}";
        if (array_key_exists($cacheKey, $this->rateCache)) {
            return $this->rateCache[$cacheKey];
        }

        $resolved = $this->lookupDirectRate($from, $to, $asOf);
        if ($resolved === null && $from !== 'USD' && $to !== 'USD') {
            $toUsd = $this->lookupDirectRate($from, 'USD', $asOf);
            $usdToTarget = $this->lookupDirectRate('USD', $to, $asOf);
            if ($toUsd !== null && $usdToTarget !== null) {
                $resolved = $toUsd * $usdToTarget;
            }
        }

        $rate = $resolved !== null && $resolved > 0 ? $resolved : 1.0;
        $this->rateCache[$cacheKey] = $rate;

        return $rate;
    }

    private function lookupDirectRate(string $from, string $to, ?CarbonInterface $asOf = null): ?float
    {
        $date = $asOf?->toDateString();

        $direct = $this->snapshotRate($from, $to, $date);
        if ($direct !== null && $direct > 0) {
            return $direct;
        }

        $inverse = $this->snapshotRate($to, $from, $date);
        if ($inverse !== null && $inverse > 0) {
            return 1 / $inverse;
        }

        $latestDirect = FxRate::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->value('rate');
        if ($latestDirect !== null && (float) $latestDirect > 0) {
            return (float) $latestDirect;
        }

        $latestInverse = FxRate::query()
            ->where('from_currency', $to)
            ->where('to_currency', $from)
            ->value('rate');
        if ($latestInverse !== null && (float) $latestInverse > 0) {
            return 1 / (float) $latestInverse;
        }

        return null;
    }

    private function snapshotRate(string $from, string $to, ?string $asOfDate): ?float
    {
        $query = FxRateSnapshot::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to);
        if ($asOfDate !== null) {
            $query->where('snapshot_date', '<=', $asOfDate);
        }

        $value = $query
            ->orderByDesc('snapshot_date')
            ->value('rate');

        if ($value === null) {
            return null;
        }

        $rate = (float) $value;
        return $rate > 0 ? $rate : null;
    }
}
