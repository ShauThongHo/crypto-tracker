<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\CEXSyncController;
use App\Http\Controllers\API\PortfolioController;
use App\Http\Controllers\API\BalanceAlertController;
use App\Http\Controllers\API\WalletController;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| 1. 页面跳转路由 (View Routes)
|--------------------------------------------------------------------------
| 对应你拆分出的三个 .blade.php 文件
*/

// 资产总览首页
Route::get('/', function () {
    return view('index');
})->name('portfolio');

// 盈亏历史页面
Route::get('/history', function () {
    return view('history');
})->name('history');

// 系统设置页面
Route::get('/settings', function () {
    return view('settings');
})->name('settings');

// 平衡提醒页面
Route::get('/balance-alert', function () {
    return view('balance-alert');
})->name('balance-alert');


/*
|--------------------------------------------------------------------------
| 2. 后端 API 路由 (Backend API Routes)
|--------------------------------------------------------------------------
| 供前端通过 fetch() 调用
*/

Route::prefix('api')->group(function () {
    // --- 看板 (Portfolio) ---
    Route::get('/assets/thinking-map', [PortfolioController::class, 'thinkingMap']);
    Route::get('/assets/snapshots', [PortfolioController::class, 'getSnapshots']);
    Route::get('/portfolio-stats', [PortfolioController::class, 'stats']);

    // --- 资产分类 (Category) ---
    Route::get('/asset-categories', [CategoryController::class, 'index']);
    Route::post('/asset-categories', [CategoryController::class, 'store']);
    Route::put('/asset-categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/asset-categories/{id}', [CategoryController::class, 'destroy']);

    // --- 交易所同步 (CEX Sync) ---
    Route::get('/sync-status', [CEXSyncController::class, 'syncStatus']);
    Route::get('/exchange-rate', [CEXSyncController::class, 'getExchangeRate']);
    Route::get('/exchange-accounts', [CEXSyncController::class, 'index']);
    Route::post('/exchange-accounts', [CEXSyncController::class, 'store']);
    Route::put('/exchange-accounts/{id}', [CEXSyncController::class, 'update']);
    Route::delete('/exchange-accounts/{id}', [CEXSyncController::class, 'destroy']);
    Route::post('/cex/sync', [CEXSyncController::class, 'sync']);
    Route::get('/cex/assets', [CEXSyncController::class, 'getCexAssets']);
    Route::delete('/cex/assets/{id}', [CEXSyncController::class, 'deleteCexAsset']);

    // --- 全量同步 ---
    Route::post('/assets/sync', [CEXSyncController::class, 'manualSync']);

    // --- 手动资产管理 (Asset CRUD) ---
    Route::post('/assets', [AssetController::class, 'storeAsset']);
    Route::put('/assets/{id}', [AssetController::class, 'updateAsset']);
    Route::delete('/assets/{id}', [AssetController::class, 'deleteAsset']);

    // --- 钱包 (Wallet) ---
    Route::get('/wallets', [WalletController::class, 'index']);
    Route::post('/wallets', [WalletController::class, 'store']);
    Route::delete('/wallets/{id}', [WalletController::class, 'destroy']);

    // --- 追踪代币 (Tracked Tokens) ---
    Route::get('/tracked-tokens', [WalletController::class, 'trackedTokens']);
    Route::get('/tracked-tokens/search', [WalletController::class, 'searchTrackedTokens']);
    Route::post('/tracked-tokens', [WalletController::class, 'addTrackedToken']);
    Route::delete('/tracked-tokens/{id}', [WalletController::class, 'deleteTrackedToken']);

    // --- 平衡提醒 (Balance Alert) ---
    Route::get('/balance-alert/settings', [BalanceAlertController::class, 'settings']);
    Route::put('/balance-alert/settings', [BalanceAlertController::class, 'update']);
    Route::get('/balance-alert/snapshot', [BalanceAlertController::class, 'snapshot']);
    Route::post('/balance-alert/snapshot', [BalanceAlertController::class, 'snapshot']);
    Route::post('/balance-alert/notify-image', [BalanceAlertController::class, 'send']);

    // --- 危险区域 (Danger Zone) ---
    Route::delete('/danger/snapshots', [AssetController::class, 'clearSnapshots']);
    Route::delete('/danger/assets', [AssetController::class, 'clearAssets']);
    Route::delete('/danger/wipe', [AssetController::class, 'wipeEverything']);
});


/*
|--------------------------------------------------------------------------
| 3. 特殊触发路由 (Automation Routes)
|--------------------------------------------------------------------------
*/

// UptimeRobot 保活+触发接口
Route::get('/health-check', [AssetController::class, 'healthCheck']);

// 保留原有的 sync 路由以防万一
Route::get('/sync', function() {
    Artisan::call('app:sync-crypto-data');
    return "Sync Command Executed.";
});

Route::view('/capital', 'capital');
