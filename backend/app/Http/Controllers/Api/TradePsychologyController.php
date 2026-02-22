<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use App\Models\TradePsychology;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TradePsychologyController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function show(Trade $trade)
    {
        $psychology = $trade->psychology()->first();
        if ($psychology === null) {
            return response()->json([
                'trade_id' => (int) $trade->id,
                'pre_emotion' => null,
                'post_emotion' => null,
                'confidence_score' => null,
                'stress_score' => null,
                'sleep_hours' => null,
                'impulse_flag' => false,
                'fomo_flag' => false,
                'revenge_flag' => false,
                'notes' => null,
            ]);
        }

        return response()->json($psychology);
    }

    /**
     * @throws ValidationException
     */
    public function upsert(Request $request, Trade $trade)
    {
        $validated = Validator::make($request->all(), [
            'pre_emotion' => ['nullable', 'string', 'max:40'],
            'post_emotion' => ['nullable', 'string', 'max:40'],
            'confidence_score' => ['nullable', 'integer', 'min:1', 'max:10'],
            'stress_score' => ['nullable', 'integer', 'min:1', 'max:10'],
            'sleep_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'impulse_flag' => ['sometimes', 'boolean'],
            'fomo_flag' => ['sometimes', 'boolean'],
            'revenge_flag' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ])->validate();

        $psychology = TradePsychology::query()->updateOrCreate(
            ['trade_id' => (int) $trade->id],
            [
                'pre_emotion' => $validated['pre_emotion'] ?? null,
                'post_emotion' => $validated['post_emotion'] ?? null,
                'confidence_score' => $validated['confidence_score'] ?? null,
                'stress_score' => $validated['stress_score'] ?? null,
                'sleep_hours' => isset($validated['sleep_hours']) ? (float) $validated['sleep_hours'] : null,
                'impulse_flag' => (bool) ($validated['impulse_flag'] ?? false),
                'fomo_flag' => (bool) ($validated['fomo_flag'] ?? false),
                'revenge_flag' => (bool) ($validated['revenge_flag'] ?? false),
                'notes' => $validated['notes'] ?? null,
            ]
        );

        $this->touchAnalyticsCacheVersion();

        return response()->json($psychology);
    }

    private function touchAnalyticsCacheVersion(): void
    {
        if (!Cache::has('analytics:version')) {
            Cache::forever('analytics:version', 1);
        }

        Cache::increment('analytics:version');
    }
}
