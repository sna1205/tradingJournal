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
use App\Support\AuthLifetimeConfigValidator;
use App\Support\CorsConfigValidator;
use App\Services\PriceFeed\CachePriceFeedService;
use App\Services\PriceFeed\ChainedPriceFeedService;
use App\Services\PriceFeed\ExchangeRateApiPriceFeedService;
use App\Services\PriceFeed\PriceFeedService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

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
        AuthLifetimeConfigValidator::validate(
            (int) config('session.lifetime', 0),
            (int) config('sanctum.expiration', 0)
        );

        PersonalAccessToken::creating(function (PersonalAccessToken $token): void {
            if ($token->expires_at !== null) {
                return;
            }

            $expirationMinutes = (int) config('sanctum.expiration', 0);
            if ($expirationMinutes <= 0) {
                return;
            }

            $token->expires_at = now()->addMinutes($expirationMinutes);
        });

        CorsConfigValidator::validate(
            (string) config('app.env', 'production'),
            is_array(config('cors.allowed_origins')) ? config('cors.allowed_origins') : [],
            is_array(config('cors.allowed_origins_patterns')) ? config('cors.allowed_origins_patterns') : [],
            (bool) config('cors.supports_credentials', true)
        );

        RateLimiter::for('auth-login', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $email = strtolower(trim((string) $request->input('email', '')));

            $emailKey = $email !== ''
                ? "auth:login:ip-email:{$ip}|{$email}"
                : "auth:login:ip-email:{$ip}|missing-email";

            return [
                Limit::perMinute(5)->by($emailKey),
                Limit::perMinute(20)->by("auth:login:ip:{$ip}"),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $email = strtolower(trim((string) $request->input('email', '')));
            $emailKey = $email !== ''
                ? "auth:register:ip-email:{$ip}|{$email}"
                : "auth:register:ip-email:{$ip}|missing-email";

            return [
                Limit::perMinute(3)->by("auth:register:ip:{$ip}"),
                Limit::perMinute(3)->by($emailKey),
            ];
        });

        RateLimiter::for('analytics-high', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $user = $request->user();
            $userKey = $user !== null
                ? "analytics:user:{$user->getAuthIdentifier()}"
                : "analytics:user-fallback-ip:{$ip}";

            return [
                Limit::perMinute(20)->by($userKey),
                Limit::perMinute(60)->by("analytics:ip:{$ip}"),
            ];
        });

        RateLimiter::for('reports-export', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $user = $request->user();
            $userKey = $user !== null
                ? "reports-export:user:{$user->getAuthIdentifier()}"
                : "reports-export:user-fallback-ip:{$ip}";

            return [
                Limit::perMinute(5)->by($userKey),
                Limit::perMinute(20)->by("reports-export:ip:{$ip}"),
            ];
        });

        RateLimiter::for('trades-precheck', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $user = $request->user();
            $userKey = $user !== null
                ? "trades-precheck:user:{$user->getAuthIdentifier()}"
                : "trades-precheck:user-fallback-ip:{$ip}";

            return [
                Limit::perMinute(30)->by($userKey),
                Limit::perSecond(5)->by("trades-precheck:burst:{$userKey}"),
                Limit::perMinute(120)->by("trades-precheck:ip:{$ip}"),
            ];
        });

        RateLimiter::for('market-data', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $user = $request->user();
            $userKey = $user !== null
                ? "market-data:user:{$user->getAuthIdentifier()}"
                : "market-data:user-fallback-ip:{$ip}";

            return [
                Limit::perMinute(60)->by($userKey),
                Limit::perMinute(120)->by("market-data:ip:{$ip}"),
            ];
        });

        RateLimiter::for('trade-writes', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $user = $request->user();
            $userKey = $user !== null
                ? "trade-writes:user:{$user->getAuthIdentifier()}"
                : "trade-writes:user-fallback-ip:{$ip}";

            return [
                Limit::perMinute(90)->by($userKey),
                Limit::perSecond(8)->by("trade-writes:burst:{$userKey}"),
                Limit::perMinute(180)->by("trade-writes:ip:{$ip}"),
            ];
        });

        RateLimiter::for('upload-writes', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $user = $request->user();
            $userKey = $user !== null
                ? "upload-writes:user:{$user->getAuthIdentifier()}"
                : "upload-writes:user-fallback-ip:{$ip}";

            return [
                Limit::perMinute(30)->by($userKey),
                Limit::perSecond(3)->by("upload-writes:burst:{$userKey}"),
                Limit::perMinute(60)->by("upload-writes:ip:{$ip}"),
            ];
        });

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Trade::class, TradePolicy::class);
        Gate::policy(Checklist::class, ChecklistPolicy::class);
        Gate::policy(SavedReport::class, SavedReportPolicy::class);
        Gate::policy(MissedTrade::class, MissedTradePolicy::class);
    }
}
