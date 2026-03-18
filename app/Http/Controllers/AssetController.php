<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Artisan, Http, Cache};
use Carbon\Carbon;
use App\Models\{CapitalFlow, Asset};

class AssetController extends Controller
{
    // =========================================================================
    // 1. 核心看板数据 (Dashboard Data)
    // =========================================================================

    /**
     * 获取资产分布思维导图数据
     */
    public function getAssetThinkingMap()
    {
        $assets = Asset::all(); // 统一使用 Model
        $trackedTokens = DB::table('tracked_tokens')->get()->keyBy('coingecko_id');

        $totalValue = $assets->sum(function ($item) {
            return is_numeric($item->value_usd) ? (float) $item->value_usd : 0;
        });

        $tree = [
            'name' => '总资产 (USD)',
            'value' => round($totalValue, 2),
            'children' => []
        ];

        $formatted = $assets->groupBy('source_name')->map(function ($sourceAssets, $sourceName) use ($trackedTokens) {
            $sourceVal = $sourceAssets->sum(fn($a) => (float) $a->value_usd);

            $networks = $sourceAssets->groupBy('network')->map(function ($networkAssets, $networkName) use ($trackedTokens) {
                return [
                    'name' => $networkName,
                    'children' => $networkAssets->map(function ($asset) use ($trackedTokens) {
                        $tokenInfo = $trackedTokens->get($asset->coingecko_id);
                        $officialSymbol = $asset->symbol ?? ($tokenInfo->symbol ?? $asset->token_name);

                        return [
                            'id' => (string) $asset->id,
                            'symbol' => strtoupper($officialSymbol),
                            'amount' => (float) $asset->token_amount,
                            'value' => round((float) $asset->value_usd, 2),
                            'label' => $asset->label ?? ''
                        ];
                    })->values()
                ];
            })->values();

            return ['name' => $sourceName, 'value' => round($sourceVal, 2), 'children' => $networks];
        })->values();

        $tree['children'] = $formatted;
        return response()->json($tree);
    }

    /**
     * 获取资产价值快照历史
     */
    // =========================================================================
    // 1. 核心看板数据 (Dashboard Data)
    // =========================================================================

    /**
     * 🎯 新增：获取全局出入金统计，用于前端计算 ROI
     */
    public function getPortfolioStats()
    {
        // 建议统一使用 usdt_amount (USDT 计价) 来作为本金基准
        $totalDeposit = DB::table('capital_flows')->where('type', 'DEPOSIT')->sum('usdt_amount');
        $totalWithdraw = DB::table('capital_flows')->where('type', 'WITHDRAWAL')->sum('usdt_amount');

        return response()->json([
            'total_deposited' => (float) $totalDeposit,
            'total_withdrawn' => (float) $totalWithdraw,
            'net_invested' => (float) ($totalDeposit - $totalWithdraw)
        ]);
    }

    /**
     * 🎯 修改：获取资产快照历史 (新增本金线数据)
     */
    public function getSnapshots(Request $request)
    {
        $range = $request->query('range', '1D');
        $query = DB::table('asset_snapshots');

        $days = ['1D' => 1, '7D' => 7, '30D' => 30];
        $since = now()->subDays($days[$range] ?? 1);

        $snapshots = $query->where('snapshot_time', '>=', $since)->orderBy('snapshot_time', 'asc')->get();

        // 获取所有的出入金流水，用来计算每个时间点的累计本金
        $flows = DB::table('capital_flows')->orderBy('transaction_date', 'asc')->get();

        $times = [];
        $values = [];
        $invested = [];

        foreach ($snapshots as $snap) {
            $carbonTime = Carbon::parse($snap->snapshot_time);
            $times[] = $carbonTime->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i:s');
            $values[] = round((float) $snap->total_value_usd, 2);

            // 核心逻辑：计算在这个快照时间点之前的“净投入本金”
            $netInvestedAtPoint = $flows->filter(function ($f) use ($carbonTime) {
                return Carbon::parse($f->transaction_date)->endOfDay() <= $carbonTime->endOfDay();
            })->sum(function ($f) {
                return $f->type === 'DEPOSIT' ? $f->usdt_amount : -$f->usdt_amount;
            });

            $invested[] = round((float) $netInvestedAtPoint, 2);
        }

        return response()->json([
            'times' => $times,
            'values' => $values,
            'invested' => $invested, // 🎯 吐出本金水位线数据
            'count' => count($times)
        ]);
    }

    // =========================================================================
    // 2. 市场数据与同步 (Market Data & Sync)
    // =========================================================================

    public function manualSync()
    {
        try {
            Artisan::call('app:sync-crypto-data');
            return response()->json(['status' => 'success', 'last_sync' => Cache::get('last_sync_at')]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getSyncStatus()
    {
        return response()->json([
            'status' => Cache::get('sync_status', 'idle'),
            'last_sync' => Cache::get('last_sync_at', '从未同步'),
        ]);
    }

    public function getExchangeRate()
    {
        $rate = Cache::remember('usd_myr_rate', 3600, function () {
            $res = Http::get("https://api.frankfurter.app/latest?from=USD&to=MYR");
            return $res->successful() ? (float) $res->json()['rates']['MYR'] : 4.72;
        });
        return response()->json(['rate' => $rate]);
    }

    // =========================================================================
    // 3. 资产管理 (Asset Management)
    // =========================================================================

    public function storeAsset(Request $request)
    {
        $v = $request->validate([
            'source_name' => 'required',
            'network' => 'required',
            'token_name' => 'required',
            'coingecko_id' => 'required',
            'token_amount' => 'required|numeric',
            'label' => 'nullable|string',
        ]);

        Asset::create(array_merge($v, ['value_usd' => 0]));
        Artisan::call('app:sync-crypto-data');
        return response()->json(['status' => 'success']);
    }

    public function updateAsset(Request $request, $id)
    {
        $v = $request->validate(['token_amount' => 'required|numeric', 'network' => 'required', 'source_name' => 'required', 'label' => 'nullable|string']);
        $asset = Asset::find($id);
        if ($asset)
            $asset->update($v);
        return response()->json(['status' => 'success']);
    }

    public function deleteAsset($id)
    {
        Asset::destroy($id);
        return response()->json(['status' => 'success']);
    }

    // =========================================================================
    // 4. 资金流水 / P2P 管理 (Capital Flow Management)
    // =========================================================================

    public function getCapitalHistory()
    {
        // 🎯 核心修复：手动将 _id 转换为字符串 id，确保前端渲染器能正常工作
        $history = CapitalFlow::orderBy('transaction_date', 'desc')
            ->get()
            ->map(function ($item) {
                $item->id = (string) $item->_id;
                return $item;
            });

        return response()->json($history);
    }

    public function storeCapitalRecord(Request $request)
    {
        $v = $request->validate([
            'asset_id' => 'required',
            'type' => 'required|in:DEPOSIT,WITHDRAWAL',
            'fiat_amount' => 'required|numeric',
            'usdt_rate' => 'required|numeric',
            'fiat_currency' => 'required|string',
            'transaction_date' => 'required|date'
        ]);

        $usdtAmount = $v['fiat_amount'] / $v['usdt_rate'];
        $flow = CapitalFlow::create(array_merge($v, ['usdt_amount' => $usdtAmount]));

        // 精准更新选中的资产余额
        $asset = Asset::find($v['asset_id']);
        if ($asset) {
            $v['type'] === 'DEPOSIT' ? $asset->token_amount += $usdtAmount : $asset->token_amount -= $usdtAmount;
            $asset->save();
        }

        return response()->json(['status' => 'success', 'new_balance' => $asset ? $asset->token_amount : null]);
    }

    public function deleteCapitalRecord($id)
    {
        // 合并后的单条删除逻辑
        CapitalFlow::destroy($id);
        return response()->json(['status' => 'success', 'message' => '记录已移除']);
    }

    // =========================================================================
    // 5. 钱包与代币管理 (Wallets & Tracked Tokens)
    // =========================================================================

    public function getWallets()
    {
        return response()->json(DB::table('wallets')->get());
    }

    public function storeWallet(Request $request)
    {
        DB::table('wallets')->insert(array_merge($request->validate(['name' => 'required', 'type' => 'required']), ['created_at' => now()]));
        return response()->json(['status' => 'success']);
    }

    public function deleteWallet($id)
    {
        DB::table('wallets')->where('_id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    public function getTrackedTokens()
    {
        return response()->json(DB::table('tracked_tokens')->get());
    }

    public function addTrackedToken(Request $request)
    {
        try {
            $validated = $request->validate([
                'coingecko_id' => 'required|string',
                'name' => 'required|string'
            ]);

            $id = strtolower($validated['coingecko_id']);
            if (empty($id)) {
                return response()->json(['status' => 'error', 'message' => 'CoinGecko ID is required'], 400);
            }

            $res = Http::timeout(10)->get("https://api.coingecko.com/api/v3/coins/{$id}");
            if ($res->successful()) {
                $data = $res->json();
                DB::table('tracked_tokens')->updateOrInsert(
                    ['coingecko_id' => $id],
                    ['name' => $data['name'], 'symbol' => $data['symbol'], 'updated_at' => now()]
                );
                return response()->json(['status' => 'success']);
            } else {
                return response()->json(['status' => 'error', 'message' => 'CoinGecko API returned status ' . $res->status()], $res->status());
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            return response()->json(['status' => 'error', 'message' => 'Network error contacting CoinGecko: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function deleteTrackedToken($id)
    {
        // Some MongoDB drivers store _id as an object; allow deleting by both _id and coingecko_id.
        DB::table('tracked_tokens')
            ->where('_id', $id)
            ->orWhere('coingecko_id', $id)
            ->delete();

        return response()->json(['status' => 'success']);
    }

    // =========================================================================
    // 6. 系统维护 (System Maintenance)
    // =========================================================================

    public function clearCapitalFlows()
    {
        CapitalFlow::truncate();
        return response()->json(['status' => 'success']);
    }

    public function wipeEverything()
    {
        DB::table('asset_snapshots')->delete();
        Asset::truncate();
        DB::table('wallets')->delete();
        DB::table('tracked_tokens')->delete();
        CapitalFlow::truncate();
        return response()->json(['status' => 'success']);
    }
}