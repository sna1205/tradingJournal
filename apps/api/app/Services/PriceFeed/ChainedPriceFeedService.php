<?php

namespace App\Services\PriceFeed;

class ChainedPriceFeedService implements PriceFeedService
{
    /**
     * @param  array<int,PriceFeedService>  $providers
     */
    public function __construct(
        private readonly array $providers
    ) {}

    public function getQuote(string $symbol): ?array
    {
        foreach ($this->providers as $provider) {
            $quote = $provider->getQuote($symbol);
            if ($quote !== null) {
                return $quote;
            }
        }

        return null;
    }
}
