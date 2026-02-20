<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use Illuminate\Http\Request;

class TradeController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));

        $trades = Trade::query()
            ->applyFilters($request->only([
                'pair',
                'direction',
                'session',
                'model',
                'date_from',
                'date_to',
            ]))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($trades);
    }

    public function store(Request $request)
    {
        $trade = Trade::create($this->validatePayload($request));

        return response()->json($trade, 201);
    }

    public function show(Trade $trade)
    {
        return response()->json($trade);
    }

    public function update(Request $request, Trade $trade)
    {
        $trade->update($this->validatePayload($request, true));

        return response()->json($trade->fresh());
    }

    public function destroy(Trade $trade)
    {
        $trade->delete();

        return response()->noContent();
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'pair' => [$required, 'string', 'max:30'],
            'direction' => [$required, 'in:buy,sell'],
            'entry_price' => [$required, 'numeric'],
            'stop_loss' => [$required, 'numeric'],
            'take_profit' => [$required, 'numeric'],
            'lot_size' => [$required, 'numeric', 'min:0.0001'],
            'profit_loss' => [$required, 'numeric'],
            'rr' => [$required, 'numeric', 'min:0'],
            'session' => [$required, 'string', 'max:60'],
            'model' => [$required, 'string', 'max:120'],
            'date' => [$required, 'date'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
