<?php

namespace App\Services\PriceFeed;

interface PriceFeedService
{
    /**
     * @return array{
     *   bid:float,
     *   ask:float,
     *   mid:float,
     *   ts:int
     * }|null
     */
    public function getQuote(string $symbol): ?array;
}
