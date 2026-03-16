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
     * 获取资产分布思维导图数据 (便当盒核心)
     */
    public function getThinkingMap()
    {
        // 1. 获取所有资产和追踪代币
        $assets = DB::table('assets')->get();
        // keyBy 可以让我们通过 coingecko_id 瞬间秒查代币详情
        $trackedTokens = DB::table('tracked_tokens')->get()->keyBy('coingecko_id');

        // 2. 计算总价值 (强制转 float 避免 CosmosDB 类型陷阱)
        $totalValue = $assets->sum(function ($item) {
            return is_numeric($item->value_usd) ? (float) $item->value_usd : 0;
        });

        $tree = [
            'name' => '总资产 (USD)',
            'value' => round($totalValue, 2),
            'children' => []
        ];

        // 3. 按来源分组
        $groupedBySource = $assets->groupBy('source_name');

        foreach ($groupedBySource as $sourceName => $sourceAssets) {
            $sourceValue = $sourceAssets->sum(function ($item) {
                return is_numeric($item->value_usd) ? (float) $item->value_usd : 0;
            });

            $sourceNode = [
                'name' => $sourceName,
                'value' => round($sourceValue, 2),
                'children' => []
            ];

            // 4. 按网络分组
            $groupedByNetwork = $sourceAssets->groupBy('network');

            foreach ($groupedByNetwork as $networkName => $networkAssets) {
                $networkValue = $networkAssets->sum(function ($item) {
                    return is_numeric($item->value_usd) ? (float) $item->value_usd : 0;
                });

                $networkNode = [
                    'name' => $networkName,
                    'value' => round($networkValue, 2),
                    'children' => []
                ];

                // 在 getThinkingMap 的 foreach ($networkAssets as $asset) 循环内
                foreach ($networkAssets as $asset) {
                    $tokenInfo = $trackedTokens->get($asset->coingecko_id);

                    // --- 逻辑修复：优先从官方信息里拿 symbol，没有才用表里的名字 ---
                    $officialSymbol = isset($tokenInfo->symbol) ? strtoupper($tokenInfo->symbol) : strtoupper($asset->token_name);
                    $officialFullName = $tokenInfo->name ?? $asset->token_name;

                    $networkNode['children'][] = [
                        '_id' => (string) ($asset->_id ?? $asset->id),
                        'symbol' => $officialSymbol, // 👈 变成真正的简称 (如 USDT)
                        'full_name' => $officialFullName,
                        'amount' => (float) $asset->token_amount,
                        'value' => round((float) $asset->value_usd, 2)
                    ];
                }
                $sourceNode['children'][] = $networkNode;
            }
            $tree['children'][] = $sourceNode;
        }

        return response()->json($tree);
    }

    /**
     * 获取历史快照数据 (1D/7D/30D 降采样逻辑)
     */
    public function getSnapshots(Request $request)
    {
        $range = $request->query('range', '1D');
        $query = DB::table('asset_snapshots')->orderBy('snapshot_time', 'asc');

        if ($range === '1D') {
            $query->where('snapshot_time', '>=', now()->subDay());
        } elseif ($range === '7D') {
            $query->where('snapshot_time', '>=', now()->subDays(7))
                ->where('snapshot_time', 'like', '%:00:%');
        } elseif ($range === '30D') {
            $query->where('snapshot_time', '>=', now()->subDays(30))
                ->where('snapshot_time', 'like', '% 00:00:%');
        }

        $snapshots = $query->get();

        $times = [];
        $values = [];
        foreach ($snapshots as $snap) {
            $times[] = $snap->snapshot_time;
            $values[] = round((float) $snap->total_value_usd, 2);
        }

        return response()->json(['times' => $times, 'values' => $values]);
    }

    public function store(Request $request)
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

        try {
            Artisan::call('app:sync-crypto-data');
        } catch (\Exception $e) {
        }

        return response()->json(['status' => 'success', 'message' => '资产已同步！']);
    }

    public function sync()
    {
        try {
            // 运行我们写好的对齐命令
            Artisan::call('app:sync-crypto-data');

            // 获取最新的状态返回给前端
            return response()->json([
                'status' => 'success',
                'message' => '同步指令已发出',
                'last_sync' => Cache::get('last_sync_at')
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $deleted = DB::table('assets')->where('_id', $id)->delete();
            if ($deleted === 0) {
                $deleted = DB::table('assets')->where('id', $id)->delete();
            }
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * 添加新追踪代币：自动从 CoinGecko 抓取官方名称和简称
     */
    public function addTrackedToken(Request $request)
    {
        $id = strtolower($request->coingecko_id);
        try {
            $response = Http::get("https://api.coingecko.com/api/v3/coins/{$id}");
            if ($response->successful()) {
                $data = $response->json();

                DB::table('tracked_tokens')->updateOrInsert(
                    ['coingecko_id' => $id],
                    [
                        'name' => $data['name'],
                        'symbol' => $data['symbol'], // 👈 存入简称 (如 usdt)
                        'last_price' => 0,
                        'updated_at' => now()
                    ]
                );

                // 顺便修正一下 assets 表里现有的错误名称
                DB::table('assets')->where('coingecko_id', $id)->update([
                    'token_name' => strtoupper($data['symbol'])
                ]);

                Artisan::call('app:sync-crypto-data');
                return response()->json(['status' => 'success']);
            }
            return response()->json(['status' => 'error', 'message' => 'ID无效'], 404);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
    public function getTrackedTokens()
    {
        return response()->json(
            DB::table('tracked_tokens')->get()->map(function ($item) {
                $item->id = (string) ($item->_id ?? $item->id ?? '');
                return $item;
            })
        );
    }

    public function deleteTrackedToken($id)
    {
        DB::table('tracked_tokens')->where('_id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    public function getWallets()
    {
        return response()->json(
            DB::table('wallets')->get()->map(function ($item) {
                $item->id = (string) ($item->_id ?? $item->id ?? '');
                return $item;
            })
        );
    }

    public function deleteWallet($id)
    {
        DB::table('wallets')->where('_id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    public function getExchangeRate()
    {
        $rate = Cache::remember('usd_myr_rate', 3600, function () {
            try {
                $response = Http::get("https://api.frankfurter.app/latest?from=USD&to=MYR");
                return $response->successful() ? (float) $response->json()['rates']['MYR'] : 3.94;
            } catch (\Exception $e) {
                return 3.94;
            }
        });
        return response()->json(['rate' => $rate]);
    }

    // 危险区域
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
    public function update(Request $request, $id)
    {
        // 1. 验证数据
        $validated = $request->validate([
            'token_amount' => 'required|numeric|min:0',
            'network' => 'required|string',
            'source_name' => 'required|string'
        ]);

        try {
            $data = [
                'token_amount' => (float) $validated['token_amount'],
                'network' => strtoupper($validated['network']),
                'source_name' => $validated['source_name'],
                'updated_at' => now()
            ];

            // 2. 尝试更新。在 CosmosDB/Mongo 中，我们需要同时兼容 _id 和 id
            // 先尝试用字符串形式更新
            $updated = DB::table('assets')->where('_id', $id)->update($data);

            if ($updated === 0) {
                // 如果更新失败，尝试匹配普通的 id 字段
                $updated = DB::table('assets')->where('id', $id)->update($data);
            }

            return response()->json(['status' => 'success', 'updated_count' => $updated]);

        } catch (\Exception $e) {
            // 这里的错误会被记录在 storage/logs/laravel.log
            return response()->json([
                'status' => 'error',
                'message' => '数据库更新失败: ' . $e->getMessage()
            ], 500);
        }
    }
    public function addWallet(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'type' => 'required|string'
        ]);

        try {
            DB::table('wallets')->insert([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
    /**
 * 1. 获取同步状态 (供前端指示灯使用)
 */
public function getSyncStatus()
{
    return response()->json([
        'status' => Cache::get('sync_status', 'idle'), // idle, running, success, error
        'last_sync' => Cache::get('last_sync_at', '从未同步'),
    ]);
}

}