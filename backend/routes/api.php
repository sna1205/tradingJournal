<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\MissedTradeController;
use App\Http\Controllers\Api\TradeController;
use Illuminate\Support\Facades\Route;

Route::apiResource('trades', TradeController::class);
Route::apiResource('missed-trades', MissedTradeController::class);

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
});
