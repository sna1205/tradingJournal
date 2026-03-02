<?php

namespace App\Services;

use App\Models\FxRate;
use App\Models\FxRateSnapshot;
use App\Services\PriceFeed\PriceFeedService;
use Carbon\CarbonImmutable;
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
        $resolved = $this->resolveRateWithProvenance($fromCurrency, $toCurrency, $asOf);

        return is_array($resolved) ? (float) ($resolved['rate'] ?? 0) : null;
    }

    /**
     * @return array{
     *   rate:float,
     *   pair:string,
     *   source:string,
     *   rate_updated_at:string|null
     * }|null
     */
    public function resolveRateWithProvenance(
        string $fromCurrency,
        string $toCurrency,
        ?CarbonInterface $asOf = null
    ): ?array {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        if ($from === '' || $to === '') {
            return null;
        }

        if ($from === $to) {
            return [
                'rate' => 1.0,
                'pair' => $from.$to,
                'source' => 'identity',
                'rate_updated_at' => $asOf?->toIso8601String(),
            ];
        }

        $dateKey = $asOf?->toDateString() ?? 'latest';
        $cacheKey = "{$from}:{$to}:{$dateKey}";
        if (array_key_exists($cacheKey, $this->rateCache)) {
            return [
                'rate' => $this->rateCache[$cacheKey],
                'pair' => $from.$to,
                'source' => 'cache',
                'rate_updated_at' => null,
            ];
        }

        $resolved = $this->lookupDirectRateWithProvenance($from, $to, $asOf);
        if ($resolved === null && $from !== 'USD' && $to !== 'USD') {
            $toUsd = $this->lookupDirectRateWithProvenance($from, 'USD', $asOf);
            $usdToTarget = $this->lookupDirectRateWithProvenance('USD', $to, $asOf);
            if ($toUsd !== null && $usdToTarget !== null) {
                $resolved = [
                    'rate' => (float) $toUsd['rate'] * (float) $usdToTarget['rate'],
                    'pair' => (string) $toUsd['pair'].'>'.(string) $usdToTarget['pair'],
                    'source' => (string) $toUsd['source'].'+'.(string) $usdToTarget['source'],
                    'rate_updated_at' => $this->oldestTimestampIso(
                        $toUsd['rate_updated_at'] ?? null,
                        $usdToTarget['rate_updated_at'] ?? null
                    ),
                ];
            }
        }

        if ($resolved === null || (float) ($resolved['rate'] ?? 0) <= 0) {
            return null;
        }

        $rate = (float) $resolved['rate'];
        $this->rateCache[$cacheKey] = $rate;

        return [
            ...$resolved,
            'rate' => $rate,
        ];
    }

    /**
     * @return array{
     *   rate:float,
     *   pair:string,
     *   source:string,
     *   rate_updated_at:string|null
     * }|null
     */
    private function lookupDirectRateWithProvenance(string $from, string $to, ?CarbonInterface $asOf = null): ?array
    {
        $date = $asOf?->toDateString();

        $directSnapshot = $this->snapshotRateWithMeta($from, $to, $date);
        if ($directSnapshot !== null && (float) ($directSnapshot['rate'] ?? 0) > 0) {
            return [
                'rate' => (float) $directSnapshot['rate'],
                'pair' => $from.$to,
                'source' => 'snapshot',
                'rate_updated_at' => $directSnapshot['rate_updated_at'],
            ];
        }

        $inverseSnapshot = $this->snapshotRateWithMeta($to, $from, $date);
        if ($inverseSnapshot !== null && (float) ($inverseSnapshot['rate'] ?? 0) > 0) {
            return [
                'rate' => 1 / (float) $inverseSnapshot['rate'],
                'pair' => $to.$from,
                'source' => 'snapshot_inverse',
                'rate_updated_at' => $inverseSnapshot['rate_updated_at'],
            ];
        }

        /** @var FxRate|null $latestDirect */
        $latestDirect = FxRate::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->first(['rate', 'rate_updated_at']);
        if ($latestDirect !== null && (float) $latestDirect->rate > 0) {
            return [
                'rate' => (float) $latestDirect->rate,
                'pair' => $from.$to,
                'source' => 'fx_rates',
                'rate_updated_at' => $latestDirect->rate_updated_at?->toIso8601String(),
            ];
        }

        /** @var FxRate|null $latestInverse */
        $latestInverse = FxRate::query()
            ->where('from_currency', $to)
            ->where('to_currency', $from)
            ->first(['rate', 'rate_updated_at']);
        if ($latestInverse !== null && (float) $latestInverse->rate > 0) {
            return [
                'rate' => 1 / (float) $latestInverse->rate,
                'pair' => $to.$from,
                'source' => 'fx_rates_inverse',
                'rate_updated_at' => $latestInverse->rate_updated_at?->toIso8601String(),
            ];
        }

        $liveRate = $this->lookupLiveRateWithProvenance($from, $to);
        if ($liveRate !== null && (float) ($liveRate['rate'] ?? 0) > 0) {
            return $liveRate;
        }

        return null;
    }

    /**
     * @return array{rate:float,rate_updated_at:string|null}|null
     */
    private function snapshotRateWithMeta(string $from, string $to, ?string $asOfDate): ?array
    {
        $query = FxRateSnapshot::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to);
        if ($asOfDate !== null) {
            $query->whereDate('snapshot_date', '<=', $asOfDate);
        }

        /** @var FxRateSnapshot|null $snapshot */
        $snapshot = $query
            ->orderByDesc('snapshot_date')
            ->first(['rate', 'rate_updated_at', 'snapshot_date']);

        if ($snapshot === null || $snapshot->rate === null) {
            return null;
        }

        $rate = (float) $snapshot->rate;
        if ($rate <= 0) {
            return null;
        }

        $snapshotDate = $snapshot->snapshot_date !== null
            ? CarbonImmutable::parse((string) $snapshot->snapshot_date)->startOfDay()->toIso8601String()
            : null;

        return [
            'rate' => $rate,
            'rate_updated_at' => $snapshot->rate_updated_at?->toIso8601String() ?? $snapshotDate,
        ];
    }

    /**
     * @return array{rate:float,pair:string,source:string,rate_updated_at:string|null}|null
     */
    private function lookupLiveRateWithProvenance(string $from, string $to): ?array
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
                return [
                    'rate' => $mid,
                    'pair' => str_replace('/', '', $symbol),
                    'source' => 'live',
                    'rate_updated_at' => isset($quote['ts']) && is_numeric($quote['ts'])
                        ? CarbonImmutable::createFromTimestampMs((int) $quote['ts'])->toIso8601String()
                        : null,
                ];
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
                return [
                    'rate' => 1 / $mid,
                    'pair' => str_replace('/', '', $symbol),
                    'source' => 'live_inverse',
                    'rate_updated_at' => isset($quote['ts']) && is_numeric($quote['ts'])
                        ? CarbonImmutable::createFromTimestampMs((int) $quote['ts'])->toIso8601String()
                        : null,
                ];
            }
        }

        return null;
    }

    private function oldestTimestampIso(?string $first, ?string $second): ?string
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
