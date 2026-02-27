<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Checklist;
use App\Models\MissedTrade;
use App\Models\SavedReport;
use App\Models\Trade;
use App\Policies\AccountPolicy;
use App\Policies\ChecklistPolicy;
use App\Policies\MissedTradePolicy;
use App\Policies\SavedReportPolicy;
use App\Policies\TradePolicy;
use App\Services\PriceFeed\CachePriceFeedService;
use App\Services\PriceFeed\ChainedPriceFeedService;
use App\Services\PriceFeed\ExchangeRateApiPriceFeedService;
use App\Services\PriceFeed\PriceFeedService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CachePriceFeedService::class, function ($app): CachePriceFeedService {
            return new CachePriceFeedService($app->make(CacheRepository::class));
        });

        $this->app->singleton(ExchangeRateApiPriceFeedService::class, function ($app): ExchangeRateApiPriceFeedService {
            $config = (array) config('price_feed.exchange_rate_api', []);

            return new ExchangeRateApiPriceFeedService(
                $app->make(HttpClientFactory::class),
                $app->make(CacheRepository::class),
                (string) ($config['base_url'] ?? ''),
                $config['api_key'] !== null ? (string) $config['api_key'] : null,
                (float) ($config['request_timeout_seconds'] ?? 2.0),
                (int) ($config['base_cache_ttl_seconds'] ?? 30),
                (float) ($config['synthetic_spread_bps'] ?? 1.0)
            );
        });

        $this->app->singleton(PriceFeedService::class, function ($app): PriceFeedService {
            $provider = strtolower((string) config('price_feed.provider', 'cache'));
            $cache = $app->make(CachePriceFeedService::class);
            $exchangeRateApi = $app->make(ExchangeRateApiPriceFeedService::class);

            return match ($provider) {
                'exchange_rate_api' => $exchangeRateApi,
                'cache_then_exchange_rate_api' => new ChainedPriceFeedService([$cache, $exchangeRateApi]),
                'exchange_rate_api_then_cache' => new ChainedPriceFeedService([$exchangeRateApi, $cache]),
                default => $cache,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Trade::class, TradePolicy::class);
        Gate::policy(Checklist::class, ChecklistPolicy::class);
        Gate::policy(SavedReport::class, SavedReportPolicy::class);
        Gate::policy(MissedTrade::class, MissedTradePolicy::class);
    }
}
