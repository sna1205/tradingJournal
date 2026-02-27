<?php

namespace Tests\Unit;

use App\Models\FxRateSnapshot;
use App\Services\CurrencyConversionService;
use App\Services\PriceFeed\PriceFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_live_price_feed_quote_when_no_snapshot_or_fx_rate_exists(): void
    {
        $service = new CurrencyConversionService(new InMemoryPriceFeedService([
            'EURUSD' => 1.2345,
        ]));

        $resolved = $service->resolveRateOrNull('EUR', 'USD', now());

        $this->assertNotNull($resolved);
        $this->assertEqualsWithDelta(1.2345, (float) $resolved, 0.0000001);
    }

    public function test_it_can_derive_direct_rate_from_inverse_live_quote(): void
    {
        $service = new CurrencyConversionService(new InMemoryPriceFeedService([
            'USDEUR' => 0.8,
        ]));

        $resolved = $service->resolveRateOrNull('EUR', 'USD', now());

        $this->assertNotNull($resolved);
        $this->assertEqualsWithDelta(1.25, (float) $resolved, 0.0000001);
    }

    public function test_it_prefers_snapshot_rate_over_live_quote_fallback(): void
    {
        FxRateSnapshot::query()->create([
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'snapshot_date' => now()->toDateString(),
            'rate' => 1.1000000000,
        ]);

        $service = new CurrencyConversionService(new InMemoryPriceFeedService([
            'EURUSD' => 1.4,
        ]));

        $resolved = $service->resolveRateOrNull('EUR', 'USD', now());

        $this->assertNotNull($resolved);
        $this->assertEqualsWithDelta(1.1, (float) $resolved, 0.0000001);
    }
}

class InMemoryPriceFeedService implements PriceFeedService
{
    /**
     * @param  array<string,float>  $midBySymbol
     */
    public function __construct(
        private readonly array $midBySymbol
    ) {}

    public function getQuote(string $symbol): ?array
    {
        $normalized = strtoupper(trim($symbol));
        $mid = (float) ($this->midBySymbol[$normalized] ?? 0);
        if ($mid <= 0) {
            return null;
        }

        return [
            'bid' => $mid,
            'ask' => $mid,
            'mid' => $mid,
            'ts' => (int) now()->valueOf(),
        ];
    }
}
