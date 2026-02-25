<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\DictionaryController;
use App\Http\Controllers\Api\InstrumentController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\ChecklistItemController;
use App\Http\Controllers\Api\MissedTradeController;
use App\Http\Controllers\Api\MissedTradeImageController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TradeController;
use App\Http\Controllers\Api\TradeChecklistResponseController;
use App\Http\Controllers\Api\TradeLegController;
use App\Http\Controllers\Api\TradeImageController;
use App\Http\Controllers\Api\TradePsychologyController;
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
Route::get('accounts/{account}/challenge', [AccountController::class, 'challenge']);
Route::put('accounts/{account}/challenge', [AccountController::class, 'upsertChallenge']);
Route::get('accounts/{account}/challenge-status', [AccountController::class, 'challengeStatus']);
Route::get('instruments', [InstrumentController::class, 'index']);
Route::get('checklists', [ChecklistController::class, 'index']);
Route::post('checklists', [ChecklistController::class, 'store']);
Route::put('checklists/{checklist}', [ChecklistController::class, 'update']);
Route::delete('checklists/{checklist}', [ChecklistController::class, 'destroy']);
Route::post('checklists/{checklist}/duplicate', [ChecklistController::class, 'duplicate']);
Route::get('checklists/{checklist}/items', [ChecklistItemController::class, 'index']);
Route::post('checklists/{checklist}/items', [ChecklistItemController::class, 'store']);
Route::put('checklist-items/{checklistItem}', [ChecklistItemController::class, 'update']);
Route::put('checklists/{checklist}/items/reorder', [ChecklistItemController::class, 'reorder']);
Route::delete('checklist-items/{checklistItem}', [ChecklistItemController::class, 'destroy']);
Route::prefix('dictionaries')->group(function () {
    Route::get('strategy-models', [DictionaryController::class, 'strategyModels']);
    Route::get('setups', [DictionaryController::class, 'setups']);
    Route::get('killzones', [DictionaryController::class, 'killzones']);
    Route::get('trade-tags', [DictionaryController::class, 'tradeTags']);
    Route::get('sessions', [DictionaryController::class, 'sessions']);
});
Route::post('trades/precheck', [TradeController::class, 'precheck']);
Route::apiResource('trades', TradeController::class);
Route::get('trades/{trade}/checklist-responses', [TradeChecklistResponseController::class, 'show']);
Route::put('trades/{trade}/checklist-responses', [TradeChecklistResponseController::class, 'upsert']);
Route::get('trades/{trade}/legs', [TradeLegController::class, 'index']);
Route::post('trades/{trade}/legs', [TradeLegController::class, 'store']);
Route::get('trades/{trade}/psychology', [TradePsychologyController::class, 'show']);
Route::put('trades/{trade}/psychology', [TradePsychologyController::class, 'upsert']);
Route::put('trade-legs/{tradeLeg}', [TradeLegController::class, 'update']);
Route::delete('trade-legs/{tradeLeg}', [TradeLegController::class, 'destroy']);
Route::post('trades/{trade}/images', [TradeImageController::class, 'store']);
Route::put('trade-images/{tradeImage}', [TradeImageController::class, 'update']);
Route::delete('trade-images/{tradeImage}', [TradeImageController::class, 'destroy']);
Route::get('reports/export.csv', [ReportController::class, 'exportCsvFromQuery']);
Route::get('reports/{report}/run', [ReportController::class, 'run']);
Route::get('reports/{report}/export.csv', [ReportController::class, 'exportCsv']);
Route::apiResource('reports', ReportController::class);
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
