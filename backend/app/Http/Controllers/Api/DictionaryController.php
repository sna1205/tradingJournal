<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Killzone;
use App\Models\Setup;
use App\Models\StrategyModel;
use App\Models\TradeTag;

class DictionaryController extends Controller
{
    public function strategyModels()
    {
        return response()->json(
            StrategyModel::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    public function setups()
    {
        return response()->json(
            Setup::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    public function killzones()
    {
        return response()->json(
            Killzone::query()
                ->where('is_active', true)
                ->orderBy('session_enum')
                ->orderBy('name')
                ->get()
        );
    }

    public function tradeTags()
    {
        return response()->json(
            TradeTag::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    public function sessions()
    {
        return response()->json([
            ['value' => 'asia', 'label' => 'Asia'],
            ['value' => 'london', 'label' => 'London'],
            ['value' => 'new_york', 'label' => 'New York'],
            ['value' => 'overlap', 'label' => 'London/NY Overlap'],
            ['value' => 'off_session', 'label' => 'Off Session'],
        ]);
    }
}
