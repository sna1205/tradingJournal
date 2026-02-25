<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PriceFeed\PriceFeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class PriceFeedController extends Controller
{
    public function __construct(
        private readonly PriceFeedService $priceFeedService
    ) {
    }

    public function quotes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'symbols' => ['required'],
        ]);
        $validator->validate();

        $rawSymbols = $request->input('symbols');
        $symbols = $this->normalizeSymbols($rawSymbols);

        $quotes = [];
        $missing = [];
        foreach ($symbols as $symbol) {
            $quote = $this->priceFeedService->getQuote($symbol);
            if ($quote === null) {
                $missing[] = $symbol;
                continue;
            }

            $quotes[$symbol] = [
                'bid' => (float) $quote['bid'],
                'ask' => (float) $quote['ask'],
                'mid' => (float) $quote['mid'],
                'ts' => (int) $quote['ts'],
            ];
        }

        return response()->json([
            'quotes' => $quotes,
            'missing' => $missing,
        ]);
    }

    /**
     * @param mixed $rawSymbols
     * @return array<int,string>
     */
    private function normalizeSymbols(mixed $rawSymbols): array
    {
        $values = is_array($rawSymbols)
            ? $rawSymbols
            : explode(',', (string) $rawSymbols);

        return Collection::make($values)
            ->map(fn ($symbol): string => strtoupper(trim((string) $symbol)))
            ->filter(fn (string $symbol): bool => $symbol !== '')
            ->unique()
            ->take(30)
            ->values()
            ->all();
    }
}
