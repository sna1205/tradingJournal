<?php

namespace App\Services\PriceFeed;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpClientFactory;

class ExchangeRateApiPriceFeedService implements PriceFeedService
{
    /**
     * @var array<string,array{rates:array<string,float>,ts:int}|null>
     */
    private array $baseRatesInMemory = [];

    public function __construct(
        private readonly HttpClientFactory $http,
        private readonly CacheRepository $cache,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly float $requestTimeoutSeconds = 2.0,
        private readonly int $baseCacheTtlSeconds = 30,
        private readonly float $syntheticSpreadBps = 1.0
    ) {}

    public function getQuote(string $symbol): ?array
    {
        $parsed = $this->parseSymbol($symbol);
        if ($parsed === null) {
            return null;
        }

        [$base, $quote] = $parsed;
        if ($base === $quote) {
            $ts = (int) now()->valueOf();

            return [
                'bid' => 1.0,
                'ask' => 1.0,
                'mid' => 1.0,
                'ts' => $ts,
            ];
        }

        $payload = $this->getRatesPayloadByBase($base);
        if ($payload === null) {
            return null;
        }

        $mid = (float) ($payload['rates'][$quote] ?? 0);
        if ($mid <= 0) {
            return null;
        }

        $spread = max(0.0, $this->syntheticSpreadBps) / 10000.0;
        $bid = $mid * (1.0 - $spread);
        $ask = $mid * (1.0 + $spread);

        return [
            'bid' => $bid > 0 ? $bid : $mid,
            'ask' => $ask > 0 ? $ask : $mid,
            'mid' => $mid,
            'ts' => $payload['ts'],
        ];
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseSymbol(string $symbol): ?array
    {
        $normalized = strtoupper(trim($symbol));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, '/')) {
            $parts = explode('/', $normalized);
            if (count($parts) !== 2) {
                return null;
            }

            $base = trim($parts[0]);
            $quote = trim($parts[1]);
            if (! preg_match('/^[A-Z]{3}$/', $base) || ! preg_match('/^[A-Z]{3}$/', $quote)) {
                return null;
            }

            return [$base, $quote];
        }

        if (! preg_match('/^[A-Z]{6}$/', $normalized)) {
            return null;
        }

        return [substr($normalized, 0, 3), substr($normalized, 3, 3)];
    }

    /**
     * @return array{rates:array<string,float>,ts:int}|null
     */
    private function getRatesPayloadByBase(string $base): ?array
    {
        if (array_key_exists($base, $this->baseRatesInMemory)) {
            return $this->baseRatesInMemory[$base];
        }

        $cacheKey = "price_feed.exchangerate_api.base.{$base}";
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['rates']) && is_array($cached['rates'])) {
            $rates = [];
            foreach ($cached['rates'] as $currency => $value) {
                $normalizedCurrency = strtoupper(trim((string) $currency));
                $normalizedValue = (float) $value;
                if ($normalizedCurrency !== '' && $normalizedValue > 0) {
                    $rates[$normalizedCurrency] = $normalizedValue;
                }
            }

            if (count($rates) > 0) {
                $payload = [
                    'rates' => $rates,
                    'ts' => (int) ($cached['ts'] ?? now()->valueOf()),
                ];
                $this->baseRatesInMemory[$base] = $payload;

                return $payload;
            }
        }

        $fetched = $this->fetchRatesByBase($base);
        $this->baseRatesInMemory[$base] = $fetched;
        if ($fetched !== null) {
            $this->cache->put($cacheKey, $fetched, now()->addSeconds($this->baseCacheTtlSeconds));
        }

        return $fetched;
    }

    /**
     * @return array{rates:array<string,float>,ts:int}|null
     */
    private function fetchRatesByBase(string $base): ?array
    {
        $apiKey = trim((string) $this->apiKey);
        $baseUrl = rtrim($this->baseUrl, '/');
        $url = $apiKey !== ''
            ? $baseUrl.'/'.rawurlencode($apiKey).'/latest/'.rawurlencode($base)
            : (str_contains($baseUrl, '{base}')
                ? str_replace('{base}', rawurlencode($base), $baseUrl)
                : $baseUrl.'/'.rawurlencode($base));

        try {
            $response = $this->http
                ->acceptJson()
                ->timeout($this->requestTimeoutSeconds)
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['result'] ?? null) !== 'success') {
            return null;
        }

        $rawRates = $payload['conversion_rates'] ?? ($payload['rates'] ?? null);
        if (! is_array($rawRates)) {
            return null;
        }

        $rates = [];
        foreach ($rawRates as $currency => $value) {
            $normalizedCurrency = strtoupper(trim((string) $currency));
            $normalizedValue = (float) $value;
            if ($normalizedCurrency !== '' && $normalizedValue > 0) {
                $rates[$normalizedCurrency] = $normalizedValue;
            }
        }

        if (count($rates) === 0) {
            return null;
        }

        $tsSeconds = (int) ($payload['time_last_update_unix'] ?? 0);
        $ts = $tsSeconds > 0
            ? $tsSeconds * 1000
            : (int) now()->valueOf();

        return [
            'rates' => $rates,
            'ts' => $ts,
        ];
    }
}
