<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use Illuminate\Http\Request;

class InstrumentController extends Controller
{
    public function index(Request $request)
    {
        $query = Instrument::query()
            ->orderBy('symbol');

        if (!$request->boolean('include_inactive', false)) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }
}

