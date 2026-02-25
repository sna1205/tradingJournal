<?php

namespace App\Providers;

use App\Services\PriceFeed\CachePriceFeedService;
use App\Services\PriceFeed\PriceFeedService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PriceFeedService::class, CachePriceFeedService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
