<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\MissedTradeController;
use App\Http\Controllers\Api\TradeController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'trading-journal-api',
    'time' => now()->toIso8601String(),
]));

Route::apiResource('trades', TradeController::class);
Route::apiResource('missed-trades', MissedTradeController::class);

Route::prefix('analytics')->group(function () {
    Route::get('overview', [AnalyticsController::class, 'overview']);
    Route::get('daily', [AnalyticsController::class, 'daily']);
    Route::get('performance-profile', [AnalyticsController::class, 'performanceProfile']);
});
