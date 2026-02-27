<?php

namespace App\Services;

use App\Models\FxRate;
use App\Models\FxRateSnapshot;
use App\Services\PriceFeed\PriceFeedService;
use Carbon\CarbonInterface;

class CurrencyConversionService
{
    /**
     * @var array<string, float>
     */
    private array $rateCache = [];

    public function __construct(
        private readonly ?PriceFeedService $priceFeedService = null
    ) {}

    public function convert(float $amount, string $fromCurrency, string $toCurrency, ?CarbonInterface $asOf = null): float
    {
        $rate = $this->resolveRate($fromCurrency, $toCurrency, $asOf);

        return round($amount * $rate, 6);
    }

    public function resolveRate(string $fromCurrency, string $toCurrency, ?CarbonInterface $asOf = null): float
    {
        $resolved = $this->resolveRateOrNull($fromCurrency, $toCurrency, $asOf);

        return $resolved !== null && $resolved > 0 ? $resolved : 1.0;
    }

    public function resolveRateOrNull(string $fromCurrency, string $toCurrency, ?CarbonInterface $asOf = null): ?float
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

        $rate = $resolved !== null && $resolved > 0 ? $resolved : null;
        if ($rate !== null) {
            $this->rateCache[$cacheKey] = $rate;
        }

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

        $liveRate = $this->lookupLiveRate($from, $to);
        if ($liveRate !== null && $liveRate > 0) {
            return $liveRate;
        }

        return null;
    }

    private function snapshotRate(string $from, string $to, ?string $asOfDate): ?float
    {
        $query = FxRateSnapshot::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to);
        if ($asOfDate !== null) {
            $query->whereDate('snapshot_date', '<=', $asOfDate);
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

    private function lookupLiveRate(string $from, string $to): ?float
    {
        if ($this->priceFeedService === null) {
            return null;
        }

        $directCandidates = [
            "{$from}{$to}",
            "{$from}/{$to}",
        ];

        foreach ($directCandidates as $symbol) {
            try {
                $quote = $this->priceFeedService->getQuote($symbol);
            } catch (\Throwable) {
                $quote = null;
            }

            if (! is_array($quote)) {
                continue;
            }

            $mid = (float) ($quote['mid'] ?? 0);
            if ($mid > 0) {
                return $mid;
            }
        }

        $inverseCandidates = [
            "{$to}{$from}",
            "{$to}/{$from}",
        ];

        foreach ($inverseCandidates as $symbol) {
            try {
                $quote = $this->priceFeedService->getQuote($symbol);
            } catch (\Throwable) {
                $quote = null;
            }

            if (! is_array($quote)) {
                continue;
            }

            $mid = (float) ($quote['mid'] ?? 0);
            if ($mid > 0) {
                return 1 / $mid;
            }
        }

        return null;
    }
}
