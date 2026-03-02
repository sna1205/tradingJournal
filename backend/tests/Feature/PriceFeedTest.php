<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_price_feed_quotes_endpoint_returns_live_cache_quotes(): void
    {
        config()->set('price_feed.provider', 'cache');

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

    public function test_price_feed_quotes_endpoint_uses_exchange_rate_provider_when_enabled(): void
    {
        config()->set('price_feed.provider', 'exchange_rate_api');
        config()->set('price_feed.exchange_rate_api.api_key', 'test-key');
        config()->set('price_feed.exchange_rate_api.base_url', 'https://v6.exchangerate-api.com/v6');
        config()->set('price_feed.exchange_rate_api.synthetic_spread_bps', 1.0);

        Http::fake([
            'https://v6.exchangerate-api.com/v6/test-key/latest/EUR' => Http::response([
                'result' => 'success',
                'time_last_update_unix' => 1700000000,
                'conversion_rates' => [
                    'USD' => 1.1,
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/price-feed/quotes?symbols=EURUSD');

        $response->assertOk();
        $response->assertJsonPath('quotes.EURUSD.mid', 1.1);
        $response->assertJsonPath('quotes.EURUSD.ts', 1700000000000);
        $response->assertJsonMissingPath('missing.0');
    }

    public function test_price_feed_quotes_endpoint_uses_open_rates_format_without_api_key(): void
    {
        config()->set('price_feed.provider', 'exchange_rate_api');
        config()->set('price_feed.exchange_rate_api.api_key', null);
        config()->set('price_feed.exchange_rate_api.base_url', 'https://open.er-api.com/v6/latest');
        config()->set('price_feed.exchange_rate_api.synthetic_spread_bps', 1.0);

        Http::fake([
            'https://open.er-api.com/v6/latest/USD' => Http::response([
                'result' => 'success',
                'time_last_update_unix' => 1700000100,
                'rates' => [
                    'JPY' => 156.00,
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/price-feed/quotes?symbols=USDJPY');

        $response->assertOk();
        $response->assertJsonPath('quotes.USDJPY.mid', 156);
        $response->assertJsonPath('quotes.USDJPY.ts', 1700000100000);
        $response->assertJsonMissingPath('missing.0');
    }
}
