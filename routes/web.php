<?php

use Illuminate\Support\Facades\Route;
use App\Models\Asset; // 引入刚才建好的模型 (Import the model we just built)
use App\Http\Controllers\AssetSyncController;


// 测试数据库连接的路由 (Route to test database connection)
Route::get('/test-db', function () {
    try {
        // 向 Cosmos DB 写入数据 (Write data to Cosmos DB)
        $asset = Asset::create([
            'coin' => 'BTC',
            'balance' => 0.5,
            'source' => 'Binance'
        ]);
        
        return response()->json([
            'status' => 'success',
            'message' => '数据成功写入 Cosmos DB！(Data successfully written to Cosmos DB!)',
            'data' => $asset
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => '连接失败 (Connection failed): ' . $e->getMessage()
        ]);
    }
});

// 访问这个网址就会触发自动更新
Route::get('/sync', [AssetSyncController::class, 'syncPrices']);

Route::get('/', function () {
    return view('map'); // 这里指向 resources/views/map.blade.php
});
// 专门给保活机器人访问的接口，不查数据库，极速响应
Route::get('/health-check', function () {
    Artisan::call('schedule:run');
    return response("System Healthy - Scheduler Poked", 200);
});