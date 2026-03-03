<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DictionaryController;
use App\Http\Controllers\Api\FxRateController;
use App\Http\Controllers\Api\InstrumentController;
use App\Http\Controllers\Api\PriceFeedController;
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
use App\Http\Controllers\Api\UserPreferenceController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'trading-journal-api',
    'time' => now()->toIso8601String(),
]));

Route::prefix('auth')->middleware('web')->group(function () {
    Route::get('config', [AuthController::class, 'config']);
    Route::post('register', [AuthController::class, 'register'])->middleware(['allow.register', 'throttle:auth-register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
});

    Route::prefix('auth')->middleware(['web', 'auth:sanctum'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
    });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user/preferences', [UserPreferenceController::class, 'show']);
    Route::put('user/preferences', [UserPreferenceController::class, 'update']);
    Route::apiResource('accounts', AccountController::class);
    Route::get('accounts/{account}/equity', [AccountController::class, 'equity']);
    Route::get('accounts/{account}/analytics', [AccountController::class, 'analytics'])->middleware('throttle:analytics-high');
    Route::get('accounts/{account}/risk-policy', [AccountController::class, 'riskPolicy']);
    Route::put('accounts/{account}/risk-policy', [AccountController::class, 'upsertRiskPolicy']);
    Route::get('accounts/{account}/challenge', [AccountController::class, 'challenge']);
    Route::put('accounts/{account}/challenge', [AccountController::class, 'upsertChallenge']);
    Route::get('accounts/{account}/challenge-status', [AccountController::class, 'challengeStatus']);
    Route::get('instruments', [InstrumentController::class, 'index']);
    Route::get('fx-rates', [FxRateController::class, 'index'])->middleware('throttle:market-data');
    Route::get('price-feed/quotes', [PriceFeedController::class, 'quotes'])->middleware('throttle:market-data');
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
    Route::get('rules', [ChecklistController::class, 'index'])->middleware('deprecated.alias:/api/checklists');
    Route::post('rules', [ChecklistController::class, 'store'])->middleware('deprecated.alias:/api/checklists');
    Route::put('rules/{checklist}', [ChecklistController::class, 'update'])->middleware('deprecated.alias:/api/checklists/{checklist}');
    Route::delete('rules/{checklist}', [ChecklistController::class, 'destroy'])->middleware('deprecated.alias:/api/checklists/{checklist}');
    Route::post('rules/{checklist}/duplicate', [ChecklistController::class, 'duplicate'])->middleware('deprecated.alias:/api/checklists/{checklist}/duplicate');
    Route::get('rules/{checklist}/items', [ChecklistItemController::class, 'index'])->middleware('deprecated.alias:/api/checklists/{checklist}/items');
    Route::post('rules/{checklist}/items', [ChecklistItemController::class, 'store'])->middleware('deprecated.alias:/api/checklists/{checklist}/items');
    Route::put('rules/{checklist}/items/reorder', [ChecklistItemController::class, 'reorder'])->middleware('deprecated.alias:/api/checklists/{checklist}/items/reorder');
    Route::put('rule-items/{checklistItem}', [ChecklistItemController::class, 'update'])->middleware('deprecated.alias:/api/checklist-items/{checklistItem}');
    Route::delete('rule-items/{checklistItem}', [ChecklistItemController::class, 'destroy'])->middleware('deprecated.alias:/api/checklist-items/{checklistItem}');
    Route::prefix('dictionaries')->group(function () {
        Route::get('strategy-models', [DictionaryController::class, 'strategyModels']);
        Route::get('setups', [DictionaryController::class, 'setups']);
        Route::get('killzones', [DictionaryController::class, 'killzones']);
        Route::get('trade-tags', [DictionaryController::class, 'tradeTags']);
        Route::get('sessions', [DictionaryController::class, 'sessions']);
    });
    Route::get('trade-checklist/resolve', [TradeChecklistResponseController::class, 'resolve'])->middleware('deprecated.alias:/api/trade-rules/resolve');
    Route::get('trade-rules/resolve', [TradeChecklistResponseController::class, 'resolve']);
    Route::post('trades/precheck', [TradeController::class, 'precheck'])->middleware('throttle:trades-precheck');
    Route::get('trades', [TradeController::class, 'index']);
    Route::post('trades', [TradeController::class, 'store'])->middleware(['throttle:trade-writes', 'idempotency:required']);
    Route::get('trades/{trade}', [TradeController::class, 'show']);
    Route::put('trades/{trade}', [TradeController::class, 'update'])->middleware('throttle:trade-writes');
    Route::patch('trades/{trade}', [TradeController::class, 'update'])->middleware('throttle:trade-writes');
    Route::delete('trades/{trade}', [TradeController::class, 'destroy'])->middleware('throttle:trade-writes');
    Route::get('trades/{trade}/checklist-responses', [TradeChecklistResponseController::class, 'show']);
    Route::put('trades/{trade}/checklist-responses', [TradeChecklistResponseController::class, 'upsert'])->middleware('throttle:trade-writes');
    Route::get('trades/{trade}/rule-responses', [TradeChecklistResponseController::class, 'show']);
    Route::put('trades/{trade}/rule-responses', [TradeChecklistResponseController::class, 'upsert'])->middleware('throttle:trade-writes');
    Route::get('trades/{trade}/legs', [TradeLegController::class, 'index']);
    Route::post('trades/{trade}/legs', [TradeLegController::class, 'store'])->middleware(['throttle:trade-writes', 'idempotency']);
    Route::get('trades/{trade}/psychology', [TradePsychologyController::class, 'show']);
    Route::put('trades/{trade}/psychology', [TradePsychologyController::class, 'upsert'])->middleware('throttle:trade-writes');
    Route::put('trade-legs/{tradeLeg}', [TradeLegController::class, 'update'])->middleware(['throttle:trade-writes', 'idempotency']);
    Route::delete('trade-legs/{tradeLeg}', [TradeLegController::class, 'destroy'])->middleware(['throttle:trade-writes', 'idempotency']);
    Route::post('trades/{trade}/images', [TradeImageController::class, 'store'])->middleware(['throttle:upload-writes', 'idempotency']);
    Route::put('trade-images/{tradeImage}', [TradeImageController::class, 'update'])->middleware('throttle:upload-writes');
    Route::delete('trade-images/{tradeImage}', [TradeImageController::class, 'destroy'])->middleware('throttle:upload-writes');
    Route::get('reports/export.csv', [ReportController::class, 'exportCsvFromQuery'])->middleware('throttle:reports-export');
    Route::get('reports/{report}/run', [ReportController::class, 'run']);
    Route::get('reports/{report}/export.csv', [ReportController::class, 'exportCsv'])->middleware('throttle:reports-export');
    Route::apiResource('reports', ReportController::class);
    Route::apiResource('missed-trades', MissedTradeController::class)
        ->middlewareFor(['store', 'update', 'destroy'], 'throttle:trade-writes');
    Route::post('missed-trades/{missedTrade}/images', [MissedTradeImageController::class, 'store'])->middleware(['throttle:upload-writes', 'idempotency']);
    Route::delete('missed-trade-images/{missedTradeImage}', [MissedTradeImageController::class, 'destroy'])->middleware('throttle:upload-writes');
    Route::get('portfolio/analytics', [AnalyticsController::class, 'portfolioAnalytics'])->middleware('throttle:analytics-high');

    Route::prefix('analytics')->middleware('throttle:analytics-high')->group(function () {
        Route::get('dashboard-summary', [AnalyticsController::class, 'dashboardSummary']);
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
        Route::get('risk_status', [AnalyticsController::class, 'riskStatus'])->middleware('deprecated.alias:/api/analytics/risk-status');
        Route::get('accounts', [AnalyticsController::class, 'accounts']);
        Route::get('portfolio', [AnalyticsController::class, 'portfolio']);
    });
});
