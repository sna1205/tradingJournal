<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithTradeRevision;
use App\Http\Controllers\Controller;
use App\Models\Trade;
use App\Models\TradePsychology;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TradePsychologyController extends Controller
{
    use InteractsWithTradeRevision;

    /**
     * @throws ValidationException
     */
    public function show(Trade $trade)
    {
        $this->authorize('view', $trade);
        $psychology = $trade->psychology()->first();
        if ($psychology === null) {
            return response()
                ->json([
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
                ])
                ->header('ETag', $this->buildTradeEtag($trade));
        }

        return response()
            ->json($psychology)
            ->header('ETag', $this->buildTradeEtag($trade));
    }

    /**
     * @throws ValidationException
     */
    public function upsert(Request $request, Trade $trade)
    {
        $this->authorize('update', $trade);
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

        $psychology = null;
        $updatedTrade = null;

        DB::transaction(function () use ($request, $trade, $validated, &$psychology, &$updatedTrade): void {
            $lockedTrade = Trade::query()
                ->whereKey((int) $trade->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTradeWritePrecondition($request, $lockedTrade);

            $psychology = TradePsychology::query()->updateOrCreate(
                ['trade_id' => (int) $lockedTrade->id],
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

            $updatedTrade = $this->bumpTradeRevision($lockedTrade);
        });

        abort_if(! $psychology instanceof TradePsychology, 500, 'Psychology write completed without persisted state.');
        abort_if(! $updatedTrade instanceof Trade, 500, 'Psychology write completed without refreshed trade revision.');

        $this->touchAnalyticsCacheVersion();

        return response()
            ->json($psychology)
            ->header('ETag', $this->buildTradeEtag($updatedTrade));
    }

    private function touchAnalyticsCacheVersion(): void
    {
        if (!Cache::has('analytics:version')) {
            Cache::forever('analytics:version', 1);
        }

        Cache::increment('analytics:version');
    }
}
