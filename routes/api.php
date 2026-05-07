<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
// 1. 记得引入你刚创建的 Controller
use App\Http\Controllers\AssetController;
use App\Http\Controllers\API\PortfolioController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\CEXSyncController;
use App\Http\Controllers\API\CapitalFlowController;
use App\Http\Controllers\API\BalanceAlertController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// 2. 添加这一行，定义思维导图的 API 接口
Route::get('/assets/thinking-map', [PortfolioController::class, 'thinkingMap']);

Route::get('/assets/snapshots', [AssetController::class, 'getSnapshots']);

Route::post('/assets', [AssetController::class, 'store']);

// 触发一键同步的路由
Route::post('/assets/sync', [AssetController::class, 'sync']);

Route::delete('/assets/{id}', [App\Http\Controllers\AssetController::class, 'destroy']);

Route::put('/assets/{id}', [AssetController::class, 'update']);

Route::post('/assets/sync-okx', [AssetController::class, 'syncOKX']);

Route::get('/tracked-tokens', [AssetController::class, 'getTrackedTokens']);
Route::post('/tracked-tokens', [AssetController::class, 'addTrackedToken']);
Route::delete('/tracked-tokens/{id}', [AssetController::class, 'deleteTrackedToken']);

Route::get('/exchange-rate', [AssetController::class, 'getExchangeRate']);

Route::get('/asset-categories', [CategoryController::class, 'index']);
Route::post('/asset-categories', [CategoryController::class, 'store']);
Route::put('/asset-categories/{id}', [CategoryController::class, 'update']);
Route::delete('/asset-categories/{id}', [CategoryController::class, 'destroy']);

Route::get('/exchange-accounts', [CEXSyncController::class, 'index']);
Route::post('/exchange-accounts', [CEXSyncController::class, 'store']);
Route::put('/exchange-accounts/{id}', [CEXSyncController::class, 'update']);
Route::delete('/exchange-accounts/{id}', [CEXSyncController::class, 'destroy']);
Route::post('/exchange-accounts/{id}/sync', [CEXSyncController::class, 'sync']);
Route::get('/sync-status', [CEXSyncController::class, 'syncStatus']);

Route::get('/portfolio-stats', [PortfolioController::class, 'stats']);

Route::get('/wallets', [AssetController::class, 'getWallets']);
Route::post('/wallets', [AssetController::class, 'addWallet']);
Route::delete('/wallets/{id}', [AssetController::class, 'deleteWallet']);

Route::delete('/danger/snapshots', [AssetController::class, 'clearSnapshots']);
Route::delete('/danger/assets', [AssetController::class, 'clearAssets']);
Route::delete('/danger/wallets', [AssetController::class, 'clearWallets']);
Route::delete('/danger/wipe', [AssetController::class, 'wipeEverything']);

// 获取资金流水历史
Route::get('/capital/history', [AssetController::class, 'getCapitalHistory']);

// 提交入金/出金记录
Route::post('/capital/record', [AssetController::class, 'storeCapitalRecord']);

Route::delete('/capital/clear', [AssetController::class, 'clearCapitalFlows']);
Route::delete('/capital/{id}', [AssetController::class, 'deleteCapitalRecord']);

Route::get('/portfolio-stats', [AssetController::class, 'getPortfolioStats']);

Route::get('/balance-alert/settings', [BalanceAlertController::class, 'settings']);
Route::put('/balance-alert/settings', [BalanceAlertController::class, 'update']);
Route::post('/balance-alert/snapshot', [BalanceAlertController::class, 'snapshot']);
Route::post('/balance-alert/send', [BalanceAlertController::class, 'send']);