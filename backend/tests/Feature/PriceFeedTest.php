<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PriceFeedTest extends TestCase
{
    public function test_price_feed_quotes_endpoint_returns_live_cache_quotes(): void
    {
        Cache::put('price_feed.quote.USDJPY', [
            'bid' => 149.9,
            'ask' => 150.1,
            'ts' => 1700000000000,
        ]);

        $response = $this->getJson('/api/price-feed/quotes?symbols=USDJPY,GBPUSD');

        $response->assertOk();
        $response->assertJsonPath('quotes.USDJPY.bid', 149.9);
        $response->assertJsonPath('quotes.USDJPY.ask', 150.1);
        $response->assertJsonPath('quotes.USDJPY.mid', 150);
        $response->assertJsonPath('quotes.USDJPY.ts', 1700000000000);
        $response->assertJsonPath('missing.0', 'GBPUSD');
    }
}
