<?php

use Illuminate\Support\Facades\Route;
use App\Models\Asset; // 引入刚才建好的模型 (Import the model we just built)
use App\Http\Controllers\AssetSyncController;
use App\Http\Controllers\AssetController;
use Illuminate\Support\Facades\Artisan;


// 访问这个网址就会触发自动更新
Route::get('/sync', [AssetSyncController::class, 'syncPrices']);

Route::get('/', function () {
    return view('map'); // 这里指向 resources/views/map.blade.php
});
// 2. 给 UptimeRobot 戳的保活+触发接口
Route::get('/health-check', function () {
    try {
        // 直接执行同步命令，确保 100% 触发逻辑
        Artisan::call('app:sync-crypto-data');
        $output = Artisan::output();

        return response()->json([
            'status' => 'alive',
            'time' => now()->toDateTimeString(),
            'command_output' => $output // 可以在浏览器直接看到同步结果
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/api/sync-status', [AssetController::class, 'getSyncStatus']);