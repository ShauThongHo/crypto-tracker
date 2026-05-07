<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssetController;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| 1. 页面跳转路由 (View Routes)
|--------------------------------------------------------------------------
| 对应你拆分出的三个 .blade.php 文件
*/

// 资产总览首页 (原 map.blade.php 现在叫 index.blade.php)
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
| 供你的 dashboard.js 通过 fetch() 调用
*/

Route::prefix('api')->group(function () {
    Route::get('/assets/thinking-map', [AssetController::class, 'getAssetThinkingMap']);
    Route::get('/asset-categories', [AssetController::class, 'getAssetCategories']);
    Route::post('/asset-categories', [AssetController::class, 'storeAssetCategory']);
    Route::put('/asset-categories/{id}', [AssetController::class, 'updateAssetCategory']);
    Route::delete('/asset-categories/{id}', [AssetController::class, 'deleteAssetCategory']);
    Route::get('/assets/snapshots', [AssetController::class, 'getSnapshots']);
    Route::get('/sync-status', [AssetController::class, 'getSyncStatus']);
    Route::get('/exchange-rate', [AssetController::class, 'getExchangeRate']);
    Route::get('/exchange-accounts', [AssetController::class, 'getExchangeAccounts']);
    Route::post('/exchange-accounts', [AssetController::class, 'storeExchangeAccount']);
    Route::put('/exchange-accounts/{id}', [AssetController::class, 'updateExchangeAccount']);
    Route::delete('/exchange-accounts/{id}', [AssetController::class, 'deleteExchangeAccount']);
    Route::post('/cex/sync', [AssetController::class, 'syncCexAssets']);
    Route::get('/cex/assets', [AssetController::class, 'getCexAssets']);
    Route::delete('/cex/assets/{id}', [AssetController::class, 'deleteCexAsset']);
    
    Route::post('/assets/sync', [AssetController::class, 'manualSync']);
    Route::post('/assets', [AssetController::class, 'storeAsset']);
    Route::put('/assets/{id}', [AssetController::class, 'updateAsset']);
    Route::delete('/assets/{id}', [AssetController::class, 'deleteAsset']);

    Route::get('/wallets', [AssetController::class, 'getWallets']);
    Route::post('/wallets', [AssetController::class, 'storeWallet']);
    Route::delete('/wallets/{id}', [AssetController::class, 'deleteWallet']);

    Route::get('/balance-alert/snapshot', [AssetController::class, 'getBalanceAlertSnapshot']);
    Route::post('/balance-alert/snapshot', [AssetController::class, 'getBalanceAlertSnapshot']);
    Route::post('/balance-alert/notify-image', [AssetController::class, 'sendBalanceAlertImage']);
    Route::get('/balance-alert/settings', [AssetController::class, 'getBalanceAlertSettings']);
    Route::put('/balance-alert/settings', [AssetController::class, 'updateBalanceAlertSettings']);

    Route::get('/tracked-tokens', [AssetController::class, 'getTrackedTokens']);
    Route::get('/tracked-tokens/search', [AssetController::class, 'searchTrackedTokens']);
    Route::post('/tracked-tokens', [AssetController::class, 'addTrackedToken']);
    Route::delete('/tracked-tokens/{id}', [AssetController::class, 'deleteTrackedToken']);

    // 危险区域
    Route::delete('/danger/snapshots', [AssetController::class, 'clearSnapshots']);
    Route::delete('/danger/assets', [AssetController::class, 'clearAssets']);
    Route::delete('/danger/wipe', [AssetController::class, 'wipeEverything']);
});


/*
|--------------------------------------------------------------------------
| 3. 特殊触发路由 (Automation Routes)
|--------------------------------------------------------------------------
*/

// UptimeRobot 戳的保活+触发接口
Route::get('/health-check', [AssetController::class, 'healthCheck']);

// 保留原有的 sync 路由以防万一
Route::get('/sync', function() {
    Artisan::call('app:sync-crypto-data');
    return "Sync Command Executed.";
});

Route::view('/capital', 'capital');