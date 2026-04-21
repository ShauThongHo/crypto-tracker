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
        // 🎯 核心修复：手动遍历计算总数（MongoDB sum() 可能不工作）
        $deposits = CapitalFlow::where('type', 'DEPOSIT')->get();
        $withdrawals = CapitalFlow::where('type', 'WITHDRAWAL')->get();

        // 手动累加，并确保转换为浮点数
        $totalDeposit = $deposits->sum(function($item) {
            return (float) ($item->fiat_amount ?? 0);
        });

        $totalWithdraw = $withdrawals->sum(function($item) {
            return (float) ($item->fiat_amount ?? 0);
        });

        \Log::info('✅ Portfolio Stats 计算完成', [
            'deposits_count' => $deposits->count(),
            'withdrawals_count' => $withdrawals->count(),
            'total_deposited' => $totalDeposit,
            'total_withdrawn' => $totalWithdraw,
            'net_invested' => $totalDeposit - $totalWithdraw,
            'sample_deposit' => $deposits->first() ? $deposits->first()->toArray() : null,
        ]);

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
        $range = strtoupper((string) $request->query('range', '1D'));
        $now = Carbon::now();
        $query = DB::table('asset_snapshots')->orderBy('snapshot_time', 'asc');

        if ($range === '7D') {
            $snapshots = $query->where('snapshot_time', '>=', $now->copy()->subDays(7)->startOfHour())->get();
        } elseif ($range === '30D') {
            $snapshots = $query->where('snapshot_time', '>=', $now->copy()->subDays(30)->startOfDay())->get();
        } elseif ($range === 'ALL') {
            $snapshots = $query->get();
        } else {
            $snapshots = $query->where('snapshot_time', '>=', $now->copy()->subDay())->get();
            $range = '1D';
        }

        $flows = CapitalFlow::orderBy('transaction_date', 'asc')->get();
        $payload = $this->buildSnapshotSeries($snapshots, $flows, $range);

        if ($range === 'ALL') {
            $payload['calendar'] = $this->buildCalendarSeries($snapshots, $flows);
        }

        return response()->json($payload);
    }

    private function buildSnapshotSeries($snapshots, $flows, string $range): array
    {
        $normalizedSnapshots = collect($snapshots)
            ->map(function ($snap) {
                return [
                    'time' => Carbon::parse($snap->snapshot_time)->setTimezone('Asia/Kuala_Lumpur'),
                    'value' => (float) ($snap->total_value_usd ?? 0),
                ];
            })
            ->values();

        if ($normalizedSnapshots->isEmpty()) {
            return [
                'times' => [],
                'values' => [],
                'invested' => [],
                'count' => 0,
                'granularity' => '5m',
            ];
        }

        $normalizedFlows = collect($flows)
            ->map(function ($flow) {
                return [
                    'time' => Carbon::parse($flow->transaction_date)->setTimezone('Asia/Kuala_Lumpur')->startOfDay(),
                    'amount' => (float) ($flow->fiat_amount ?? 0),
                    'direction' => $flow->type,
                ];
            })
            ->sortBy('time')
            ->values();

        $bucketTimes = [];
        $granularity = '5m';
        $now = Carbon::now();

        if ($range === '7D') {
            $granularity = 'hour';
            $cursor = $now->copy()->subDays(7)->startOfHour();
            while ($cursor->lte($now)) {
                $bucketTimes[] = $cursor->copy()->minute(0)->second(0);
                $cursor->addHour();
            }
        } elseif ($range === '30D' || $range === 'ALL') {
            $granularity = 'day';
            $start = $range === 'ALL'
                ? $normalizedSnapshots->first()['time']->copy()->startOfDay()
                : $now->copy()->subDays(30)->startOfDay();
            $cursor = $start;
            while ($cursor->lte($now)) {
                $bucketTimes[] = $cursor->copy()->hour(0)->minute(0)->second(0);
                $cursor->addDay();
            }
        } else {
            $bucketTimes = $normalizedSnapshots->pluck('time')->all();
        }

        $times = [];
        $values = [];
        $invested = [];
        $snapshotIndex = 0;
        $flowIndex = 0;
        $latestSnapshot = null;
        $netInvested = 0;
        $snapshotCount = $normalizedSnapshots->count();
        $flowCount = $normalizedFlows->count();

        foreach ($bucketTimes as $bucketTime) {
            while ($snapshotIndex < $snapshotCount && $normalizedSnapshots[$snapshotIndex]['time']->lte($bucketTime)) {
                $latestSnapshot = $normalizedSnapshots[$snapshotIndex];
                $snapshotIndex++;
            }

            while ($flowIndex < $flowCount && $normalizedFlows[$flowIndex]['time']->lte($bucketTime)) {
                $flow = $normalizedFlows[$flowIndex];
                $netInvested += $flow['direction'] === 'DEPOSIT' ? $flow['amount'] : -$flow['amount'];
                $flowIndex++;
            }

            if (!$latestSnapshot) {
                continue;
            }

            $times[] = $bucketTime->copy()->format('Y-m-d H:i:s');
            $values[] = round($latestSnapshot['value'], 2);
            $invested[] = round($netInvested, 2);
        }

        return [
            'times' => $times,
            'values' => $values,
            'invested' => $invested,
            'count' => count($times),
            'granularity' => $granularity,
        ];
    }

    private function buildCalendarSeries($snapshots, $flows): array
    {
        $timeZone = 'Asia/Kuala_Lumpur';
        $normalizedSnapshots = collect($snapshots)
            ->map(function ($snap) use ($timeZone) {
                return [
                    'time' => Carbon::parse($snap->snapshot_time)->setTimezone($timeZone),
                    'value' => (float) ($snap->total_value_usd ?? 0),
                ];
            })
            ->sortBy('time')
            ->values();

        $normalizedFlows = collect($flows)
            ->map(function ($flow) use ($timeZone) {
                $flowAmount = (float) ($flow->usdt_amount ?? 0);
                if ($flowAmount <= 0 && isset($flow->fiat_amount, $flow->usdt_rate) && (float) $flow->usdt_rate > 0) {
                    $flowAmount = (float) $flow->fiat_amount / (float) $flow->usdt_rate;
                }

                return [
                    'date' => Carbon::parse($flow->transaction_date)->setTimezone($timeZone)->toDateString(),
                    'amount' => $flowAmount,
                    'direction' => $flow->type,
                ];
            })
            ->groupBy('date');

        $today = Carbon::now($timeZone)->startOfDay();
        $startDate = Carbon::create($today->year, 1, 1, 0, 0, 0, $timeZone);
        $calendarSeries = [];
        $previousClose = null;

        for ($cursor = $startDate->copy(); $cursor->lte($today); $cursor->addDay()) {
            $dateStr = $cursor->toDateString();
            $dayStart = $cursor->copy()->startOfDay();
            $dayEnd = $cursor->copy()->startOfDay()->addDay()->subMinutes(5);

            $openSnapshot = $normalizedSnapshots->filter(function ($snap) use ($dayStart) {
                return $snap['time']->lte($dayStart);
            })->last();

            $closeSnapshot = $normalizedSnapshots->filter(function ($snap) use ($dayEnd) {
                return $snap['time']->lte($dayEnd);
            })->last();

            $flowsForDay = $normalizedFlows->get($dateStr, collect());
            $netFlow = collect($flowsForDay)->sum(function ($flow) {
                return $flow['direction'] === 'DEPOSIT' ? $flow['amount'] : -$flow['amount'];
            });

            if (!$closeSnapshot) {
                $calendarSeries[] = [$dateStr, 0, 0, $previousClose ?? 0, false];
                continue;
            }

            $dayClose = (float) $closeSnapshot['value'];
            $dayOpen = $openSnapshot ? (float) $openSnapshot['value'] : ($previousClose !== null ? (float) $previousClose : $dayClose);
            $dailyPnl = $dayClose - $dayOpen - $netFlow;
            $dailyPct = $dayOpen === 0.0 ? 0 : ($dailyPnl / $dayOpen) * 100;

            $calendarSeries[] = [$dateStr, round($dailyPnl, 2), round($dailyPct, 2), round($dayClose, 2), true];
            $previousClose = $dayClose;
        }

        return $calendarSeries;
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

    public function searchTrackedTokens(Request $request)
    {
        $query = trim((string) $request->query('query', ''));
        if (mb_strlen($query) < 2) {
            return response()->json(['coins' => []]);
        }

        $cacheKey = 'cg_search_' . md5(strtolower($query));
        $coins = Cache::remember($cacheKey, 30, function () use ($query) {
            $res = Http::timeout(10)->get('https://api.coingecko.com/api/v3/search', [
                'query' => $query,
            ]);

            if (!$res->successful()) {
                return [];
            }

            $payload = $res->json();
            $list = $payload['coins'] ?? [];

            return collect($list)
                ->take(8)
                ->map(function ($item) {
                    return [
                        'id' => $item['id'] ?? '',
                        'name' => $item['name'] ?? '',
                        'symbol' => $item['symbol'] ?? '',
                    ];
                })
                ->filter(fn($x) => !empty($x['id']) && !empty($x['name']))
                ->values()
                ->all();
        });

        return response()->json(['coins' => $coins]);
    }

    public function addTrackedToken(Request $request)
    {
        try {
            $validated = $request->validate([
                'coingecko_id' => 'required|string',
                'name' => 'required|string',
                'symbol' => 'nullable|string'
            ]);

            $id = strtolower(trim($validated['coingecko_id']));
            $name = trim($validated['name']);
            $symbol = isset($validated['symbol']) ? strtolower(trim($validated['symbol'])) : '';

            if (empty($id)) {
                return response()->json(['status' => 'error', 'message' => 'CoinGecko ID is required'], 400);
            }

            // 优先使用前端已选中的 symbol，避免再次请求 CoinGecko 导致 429。
            if (!empty($symbol)) {
                DB::table('tracked_tokens')->updateOrInsert(
                    ['coingecko_id' => $id],
                    ['name' => $name, 'symbol' => $symbol, 'updated_at' => now()]
                );
                return response()->json(['status' => 'success', 'source' => 'client']);
            }

            // 与同步命令一致：优先尝试通过中转站验证代币 ID，避免官方接口 429。
            $proxyUrl = config('services.coingecko.proxy_url');
            $proxyKey = config('services.coingecko.proxy_key');
            if (!empty($proxyUrl) && !empty($proxyKey)) {
                $proxyRes = Http::withHeaders(['x-proxy-key' => $proxyKey])
                    ->timeout(10)
                    ->get($proxyUrl, [
                        'ids' => $id,
                        'vs_currencies' => 'usd'
                    ]);

                if ($proxyRes->successful()) {
                    $proxyData = $proxyRes->json();
                    if (is_array($proxyData) && array_key_exists($id, $proxyData)) {
                        $fallbackSymbol = strtolower(substr(explode('-', $id)[0], 0, 10));
                        DB::table('tracked_tokens')->updateOrInsert(
                            ['coingecko_id' => $id],
                            ['name' => $name, 'symbol' => $fallbackSymbol, 'updated_at' => now()]
                        );
                        return response()->json(['status' => 'success', 'source' => 'proxy']);
                    }
                }
            }

            $res = Http::timeout(10)->get("https://api.coingecko.com/api/v3/coins/{$id}");
            if ($res->successful()) {
                $data = $res->json();
                $resolvedName = $data['name'] ?? $name;
                $resolvedSymbol = $data['symbol'] ?? strtoupper(substr(explode('-', $id)[0], 0, 10));

                DB::table('tracked_tokens')->updateOrInsert(
                    ['coingecko_id' => $id],
                    ['name' => $resolvedName, 'symbol' => strtolower($resolvedSymbol), 'updated_at' => now()]
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