<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MissedTrade;
use Illuminate\Http\Request;

class MissedTradeController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));

        $missedTrades = MissedTrade::query()
            ->applyFilters($request->only([
                'pair',
                'model',
                'reason',
                'date_from',
                'date_to',
            ]))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($missedTrades);
    }

    public function store(Request $request)
    {
        $missedTrade = MissedTrade::create($this->validatePayload($request));

        return response()->json($missedTrade, 201);
    }

    public function show(MissedTrade $missedTrade)
    {
        return response()->json($missedTrade);
    }

    public function update(Request $request, MissedTrade $missedTrade)
    {
        $missedTrade->update($this->validatePayload($request, true));

        return response()->json($missedTrade->fresh());
    }

    public function destroy(MissedTrade $missedTrade)
    {
        $missedTrade->delete();

        return response()->noContent();
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'pair' => [$required, 'string', 'max:30'],
            'model' => [$required, 'string', 'max:120'],
            'reason' => [$required, 'string', 'max:255'],
            'date' => [$required, 'date'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
