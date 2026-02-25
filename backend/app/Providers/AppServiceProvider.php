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
use App\Services\PriceFeed\PriceFeedService;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Trade::class, TradePolicy::class);
        Gate::policy(Checklist::class, ChecklistPolicy::class);
        Gate::policy(SavedReport::class, SavedReportPolicy::class);
        Gate::policy(MissedTrade::class, MissedTradePolicy::class);
    }
}
