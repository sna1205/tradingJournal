<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\InstrumentController;
use App\Http\Controllers\Api\MissedTradeController;
use App\Http\Controllers\Api\MissedTradeImageController;
use App\Http\Controllers\Api\TradeController;
use App\Http\Controllers\Api\TradeImageController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'trading-journal-api',
    'time' => now()->toIso8601String(),
]));
Route::apiResource('accounts', AccountController::class);
Route::get('accounts/{account}/equity', [AccountController::class, 'equity']);
Route::get('accounts/{account}/analytics', [AccountController::class, 'analytics']);
Route::get('accounts/{account}/risk-policy', [AccountController::class, 'riskPolicy']);
Route::put('accounts/{account}/risk-policy', [AccountController::class, 'upsertRiskPolicy']);
Route::get('instruments', [InstrumentController::class, 'index']);
Route::post('trades/precheck', [TradeController::class, 'precheck']);
Route::apiResource('trades', TradeController::class);
Route::post('trades/{trade}/images', [TradeImageController::class, 'store']);
Route::delete('trade-images/{tradeImage}', [TradeImageController::class, 'destroy']);
Route::apiResource('missed-trades', MissedTradeController::class);
Route::post('missed-trades/{missedTrade}/images', [MissedTradeImageController::class, 'store']);
Route::delete('missed-trade-images/{missedTradeImage}', [MissedTradeImageController::class, 'destroy']);
Route::get('portfolio/analytics', [AnalyticsController::class, 'portfolioAnalytics']);

Route::prefix('analytics')->group(function () {
    Route::get('overview', [AnalyticsController::class, 'overview']);
    Route::get('daily', [AnalyticsController::class, 'daily']);
    Route::get('performance-profile', [AnalyticsController::class, 'performanceProfile']);
    Route::get('equity', [AnalyticsController::class, 'equity']);
    Route::get('drawdown', [AnalyticsController::class, 'drawdown']);
    Route::get('streaks', [AnalyticsController::class, 'streaks']);
    Route::get('metrics', [AnalyticsController::class, 'metrics']);
    Route::get('behavioral', [AnalyticsController::class, 'behavioral']);
    Route::get('rankings', [AnalyticsController::class, 'rankings']);
    Route::get('monthly-heatmap', [AnalyticsController::class, 'monthlyHeatmap']);
    Route::get('risk-status', [AnalyticsController::class, 'riskStatus']);
    Route::get('risk_status', [AnalyticsController::class, 'riskStatus']);
    Route::get('accounts', [AnalyticsController::class, 'accounts']);
    Route::get('portfolio', [AnalyticsController::class, 'portfolio']);
});
