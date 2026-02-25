<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FxRate;
use Illuminate\Http\Request;

class FxRateController extends Controller
{
    public function index(Request $request)
    {
        $query = FxRate::query()
            ->orderBy('from_currency')
            ->orderBy('to_currency');

        if ($request->filled('to_currency')) {
            $query->where('to_currency', strtoupper((string) $request->string('to_currency')));
        }

        return response()->json($query->get());
    }
}
