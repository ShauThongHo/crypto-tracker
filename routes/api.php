<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\AssetController;
use App\Http\Controllers\API\PortfolioController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\CEXSyncController;
use App\Http\Controllers\API\CapitalFlowController;
use App\Http\Controllers\API\BalanceAlertController;
use App\Http\Controllers\API\WalletController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// --- 看板 (Portfolio) ---
Route::get('/assets/thinking-map', [PortfolioController::class, 'thinkingMap']);
Route::get('/assets/snapshots', [PortfolioController::class, 'getSnapshots']);
Route::get('/portfolio-stats', [PortfolioController::class, 'stats']);

// --- 资产管理 ---
Route::post('/assets', [AssetController::class, 'storeAsset']);
Route::put('/assets/{id}', [AssetController::class, 'updateAsset']);
Route::delete('/assets/{id}', [AssetController::class, 'deleteAsset']);

// --- 全量同步 ---
Route::post('/assets/sync', [CEXSyncController::class, 'manualSync']);

// --- 交易所同步 (CEX) ---
Route::get('/exchange-rate', [CEXSyncController::class, 'getExchangeRate']);

// --- 资产分类 ---
Route::get('/asset-categories', [CategoryController::class, 'index']);
Route::post('/asset-categories', [CategoryController::class, 'store']);
Route::put('/asset-categories/{id}', [CategoryController::class, 'update']);
Route::delete('/asset-categories/{id}', [CategoryController::class, 'destroy']);

// --- 交易所账号 ---
Route::get('/exchange-accounts', [CEXSyncController::class, 'index']);
Route::post('/exchange-accounts', [CEXSyncController::class, 'store']);
Route::put('/exchange-accounts/{id}', [CEXSyncController::class, 'update']);
Route::delete('/exchange-accounts/{id}', [CEXSyncController::class, 'destroy']);
Route::post('/exchange-accounts/{id}/sync', [CEXSyncController::class, 'sync']);
Route::get('/sync-status', [CEXSyncController::class, 'syncStatus']);

// --- 追踪代币 ---
Route::get('/tracked-tokens', [WalletController::class, 'trackedTokens']);
Route::post('/tracked-tokens', [WalletController::class, 'addTrackedToken']);
Route::delete('/tracked-tokens/{id}', [WalletController::class, 'deleteTrackedToken']);

// --- 钱包 ---
Route::get('/wallets', [WalletController::class, 'index']);
Route::post('/wallets', [WalletController::class, 'store']);
Route::delete('/wallets/{id}', [WalletController::class, 'destroy']);

// --- 危险区域 ---
Route::delete('/danger/snapshots', [AssetController::class, 'clearSnapshots']);
Route::delete('/danger/assets', [AssetController::class, 'clearAssets']);
Route::delete('/danger/wipe', [AssetController::class, 'wipeEverything']);

// --- 资金流水 ---
Route::get('/capital/history', [CapitalFlowController::class, 'history']);
Route::post('/capital/record', [CapitalFlowController::class, 'store']);
Route::delete('/capital/clear', [CapitalFlowController::class, 'clear']);
Route::delete('/capital/{id}', [CapitalFlowController::class, 'destroy']);

// --- 平衡提醒 (Balance Alert) ---
Route::get('/balance-alert/settings', [BalanceAlertController::class, 'settings']);
Route::put('/balance-alert/settings', [BalanceAlertController::class, 'update']);
Route::post('/balance-alert/snapshot', [BalanceAlertController::class, 'snapshot']);
Route::post('/balance-alert/send', [BalanceAlertController::class, 'send']);
