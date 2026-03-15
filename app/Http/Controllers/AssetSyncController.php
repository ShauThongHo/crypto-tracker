<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http; // ⚠️ 引入 Laravel 的 Http 客户端
use App\Models\Asset;

class AssetSyncController extends Controller
{
    public function syncPrices()
    {
        // 1. 调用 CoinGecko API 获取 BTC 的实时美元价格
        // URL: https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd
        $response = Http::get('https://api.coingecko.com/api/v3/simple/price', [
            'ids' => 'bitcoin',
            'vs_currencies' => 'usd',
        ]);

        if ($response->successful()) {
            $price = $response->json()['bitcoin']['usd'];

            // 2. 从你的 Cosmos DB 中找到那笔 0.5 BTC 的数据
            $asset = Asset::where('coin', 'BTC')->first();

            if ($asset) {
                // 3. 计算总价值并更新数据库
                $totalValue = $asset->balance * $price;
                
                // MongoDB 的灵活性：我们可以直接动态增加一个字段，不需要改数据库结构
                $asset->update([
                    'current_price' => $price,
                    'total_value_usd' => $totalValue,
                    'last_synced_at' => now(),
                ]);

                return response()->json([
                    'status' => 'success',
                    'coin' => 'BTC',
                    'current_price' => '$' . number_format($price, 2),
                    'your_balance' => $asset->balance,
                    'total_value' => '$' . number_format($totalValue, 2)
                ]);
            }
        }

        return response()->json(['status' => 'error', 'message' => '抓取价格失败 (Failed to fetch price)']);
    }
}