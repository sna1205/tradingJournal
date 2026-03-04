<?php

namespace App\Services\PriceFeed;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CachePriceFeedService implements PriceFeedService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $cacheKeyPrefix = 'price_feed.quote.'
    ) {
    }

    public function getQuote(string $symbol): ?array
    {
        $normalized = strtoupper(trim($symbol));
        if ($normalized === '') {
            return null;
        }

        $raw = $this->cache->get($this->cacheKeyPrefix . $normalized);
        if (!is_array($raw)) {
            return null;
        }

        $bid = (float) ($raw['bid'] ?? 0);
        $ask = (float) ($raw['ask'] ?? 0);
        $mid = (float) ($raw['mid'] ?? 0);
        $ts = (int) ($raw['ts'] ?? 0);

        // Normalize partially populated feeds (mid-only or one-sided).
        if ($mid <= 0 && $bid > 0 && $ask > 0) {
            $mid = ($bid + $ask) / 2.0;
        }
        if ($bid <= 0 && $ask > 0) {
            $bid = $mid > 0 ? $mid : $ask;
        }
        if ($ask <= 0 && $bid > 0) {
            $ask = $mid > 0 ? $mid : $bid;
        }
        if ($mid <= 0 && $bid > 0 && $ask > 0) {
            $mid = ($bid + $ask) / 2.0;
        }

        if ($bid <= 0 || $ask <= 0 || $mid <= 0) {
            return null;
        }

        if ($ts <= 0) {
            $ts = (int) now()->valueOf();
        }

        return [
            'bid' => $bid,
            'ask' => $ask,
            'mid' => $mid,
            'ts' => $ts,
        ];
    }
}
