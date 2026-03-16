<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AssetController extends Controller
{
    /**
     * 获取资产分布思维导图数据
     */
    public function getAssetThinkingMap()
    {
        // 1. 获取数据，强制确保是 Collection
        $assets = DB::table('assets')->get();
        $trackedTokens = DB::table('tracked_tokens')->get()->keyBy('coingecko_id');

        // 2. 这里的 sum 增加判断，防止 int + array 报错
        $totalValue = $assets->sum(function ($item) {
            // 🎯 关键：检查字段是否存在且是数字
            $val = $item->value_usd ?? $item->balance_usd ?? 0;
            return is_numeric($val) ? (float) $val : 0;
        });

        $tree = [
            'name' => '总资产 (USD)',
            'value' => round($totalValue, 2),
            'children' => []
        ];

        // 3. 重新整理分组逻辑
        $formatted = $assets->groupBy('source_name')->map(function ($sourceAssets, $sourceName) use ($trackedTokens) {
            $sourceVal = $sourceAssets->sum(function ($a) {
                return (float) ($a->value_usd ?? $a->balance_usd ?? 0);
            });

            $networks = $sourceAssets->groupBy('network')->map(function ($networkAssets, $networkName) use ($trackedTokens) {
                return [
                    'name' => $networkName,
                    'children' => $networkAssets->map(function ($asset) use ($trackedTokens) {
                        $tokenInfo = $trackedTokens->get($asset->coingecko_id);
                        $officialSymbol = $tokenInfo->symbol ?? $asset->token_name;
                        $val = $asset->value_usd ?? $asset->balance_usd ?? 0;
                        return [
                            'id' => (string) ($asset->_id ?? $asset->id),
                            'symbol' => strtoupper($officialSymbol),
                            'amount' => (float) $asset->token_amount,
                            'value' => round((float) $val, 2)
                        ];
                    })->values()
                ];
            })->values();

            return [
                'name' => $sourceName,
                'value' => round($sourceVal, 2),
                'children' => $networks
            ];
        })->values();

        $tree['children'] = $formatted;
        return response()->json($tree);
    }

    /**
     * 获取快照数据
     */
    public function getSnapshots(Request $request)
{
    $range = $request->query('range', '1D');
    $query = DB::table('asset_snapshots');

    // 根据范围筛选（保持直接使用 Carbon 对象以适配 MongoDB）
    if ($range === '1D') {
        $query->where('snapshot_time', '>=', now()->subDay()); 
    } elseif ($range === '7D') {
        $query->where('snapshot_time', '>=', now()->subDays(7));
    } elseif ($range === '30D') {
        $query->where('snapshot_time', '>=', now()->subDays(30));
    }

    $snapshots = $query->orderBy('snapshot_time', 'asc')->get();

    $times = [];
    $values = [];

    foreach ($snapshots as $snap) {
        $rawTime = $snap->snapshot_time;
        $carbonTime = null;

        // 🎯 1. 将 MongoDB 的各种时间格式统一转为 Carbon 对象
        if ($rawTime instanceof \MongoDB\BSON\UTCDateTime) {
            $carbonTime = \Carbon\Carbon::createFromTimestampMs($rawTime->__toString());
        } elseif (is_object($rawTime) && isset($rawTime->toDateTime)) {
            $carbonTime = \Carbon\Carbon::instance($rawTime->toDateTime());
        } else {
            $carbonTime = \Carbon\Carbon::parse($rawTime);
        }

        // 🎯 2. 核心修复：强制转换为马来西亚时间 (UTC+8)
        // 这样返回给前端的字符串就是真实的本地时间了
        $localTime = $carbonTime->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i:s');

        $times[] = $localTime;
        $values[] = round((float)($snap->total_value_usd ?? 0), 2);
    }

    return response()->json([
        'times' => $times,
        'values' => $values,
        'count' => count($times)
    ]);
}

    /**
     * 手动同步指令
     */
    public function manualSync()
    {
        try {
            Artisan::call('app:sync-crypto-data');
            return response()->json([
                'status' => 'success',
                'message' => '同步指令已发出',
                'last_sync' => Cache::get('last_sync_at')
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 获取同步状态
     */
    public function getSyncStatus()
    {
        return response()->json([
            'status' => Cache::get('sync_status', 'idle'),
            'last_sync' => Cache::get('last_sync_at', '从未同步'),
        ]);
    }

    /**
     * 录入资产
     */
    public function storeAsset(Request $request)
    {
        $validated = $request->validate([
            'source_name' => 'required|string|max:50',
            'network' => 'required|string|max:50',
            'token_name' => 'required|string|max:20',
            'coingecko_id' => 'required|string|max:100',
            'token_amount' => 'required|numeric|min:0',
        ]);

        DB::table('assets')->insert([
            'source_name' => $validated['source_name'],
            'network' => strtoupper($validated['network']),
            'token_name' => strtoupper($validated['token_name']),
            'coingecko_id' => strtolower($validated['coingecko_id']),
            'token_amount' => (float) $validated['token_amount'],
            'value_usd' => 0,
            'created_at' => now()
        ]);

        Artisan::call('app:sync-crypto-data');
        return response()->json(['status' => 'success']);
    }

    /**
     * 更新资产
     */
    public function updateAsset(Request $request, $id)
    {
        $validated = $request->validate([
            'token_amount' => 'required|numeric|min:0',
            'network' => 'required|string',
            'source_name' => 'required|string'
        ]);

        $data = [
            'token_amount' => (float) $validated['token_amount'],
            'network' => strtoupper($validated['network']),
            'source_name' => $validated['source_name'],
            'updated_at' => now()
        ];

        $updated = DB::table('assets')->where('_id', $id)->update($data);
        if ($updated === 0) {
            $updated = DB::table('assets')->where('id', $id)->update($data);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * 删除资产
     */
    public function deleteAsset($id)
    {
        DB::table('assets')->where('_id', $id)->delete();
        DB::table('assets')->where('id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    /**
     * 钱包管理
     */
    public function getWallets()
    {
        return response()->json(DB::table('wallets')->get()->map(function ($i) {
            $i->id = (string) ($i->_id ?? $i->id);
            return $i;
        }));
    }

    public function storeWallet(Request $request)
    {
        $v = $request->validate(['name' => 'required', 'type' => 'required']);
        DB::table('wallets')->insert(['name' => $v['name'], 'type' => $v['type'], 'created_at' => now()]);
        return response()->json(['status' => 'success']);
    }

    public function deleteWallet($id)
    {
        DB::table('wallets')->where('_id', $id)->delete();
        DB::table('wallets')->where('id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    /**
     * 追踪代币管理
     */
    public function getTrackedTokens()
    {
        return response()->json(DB::table('tracked_tokens')->get()->map(function ($i) {
            $i->id = (string) ($i->_id ?? $i->id);
            return $i;
        }));
    }

    public function storeTrackedToken(Request $request)
    {
        $id = strtolower($request->coingecko_id);
        $res = Http::get("https://api.coingecko.com/api/v3/coins/{$id}");
        if ($res->successful()) {
            $data = $res->json();
            DB::table('tracked_tokens')->updateOrInsert(['coingecko_id' => $id], ['name' => $data['name'], 'symbol' => $data['symbol'], 'updated_at' => now()]);
            return response()->json(['status' => 'success']);
        }
        return response()->json(['status' => 'error'], 404);
    }

    public function deleteTrackedToken($id)
    {
        DB::table('tracked_tokens')->where('_id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    public function getExchangeRate()
    {
        $rate = Cache::remember('usd_myr_rate', 3600, function () {
            $res = Http::get("https://api.frankfurter.app/latest?from=USD&to=MYR");
            return $res->successful() ? (float) $res->json()['rates']['MYR'] : 4.72;
        });
        return response()->json(['rate' => $rate]);
    }

    public function clearSnapshots()
    {
        DB::table('asset_snapshots')->delete();
        return response()->json(['status' => 'success']);
    }
    public function clearAssets()
    {
        DB::table('assets')->delete();
        return response()->json(['status' => 'success']);
    }
    public function wipeEverything()
    {
        DB::table('asset_snapshots')->delete();
        DB::table('assets')->delete();
        DB::table('wallets')->delete();
        DB::table('tracked_tokens')->delete();
        return response()->json(['status' => 'success']);
    }
}