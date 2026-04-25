<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\CexSyncedAsset;

class SyncCryptoData extends Command
{
    protected $signature = 'app:sync-crypto-data';
    protected $description = '通过 Cloudflare Worker 中转住宅 IP 抓取价格，并存入快照';

    public function handle()
    {
        // --- 1. 计算对齐时间 (5分钟对齐) ---
        $now = time();
        $interval = 300; 
        $alignedTimestamp = floor($now / $interval) * $interval;
        $alignedTime = Carbon::createFromTimestamp($alignedTimestamp);
        $slotKey = 'synced_slot_' . $alignedTimestamp;

        // --- 2. 检查是否已经跑过这个槽位 ---
        if (Cache::has($slotKey)) {
            $this->info("⏭️ 槽位 " . $alignedTime->format('H:i:s') . " 已经同步过，跳过。");
            return;
        }

        // --- 3. 更新状态给前端 (Syncing...) ---
        Cache::put('sync_status', 'running', 60);

        $this->info("📡 正在通过中转站对齐到 {$alignedTime->format('H:i:s')} 同步价格...");

        try {
            // 获取所有需要追踪的 ID
            $trackedTokens = DB::table('tracked_tokens')->get();
            $ids = $trackedTokens->pluck('coingecko_id')->toArray();

            if (empty($ids)) {
                $this->warn('未找到任何追踪代币。');
                Cache::put('sync_status', 'idle', 60);
                return;
            }

            // --- 🎯 核心修改：调用你的 Cloudflare Worker 中转站 ---
            $proxyUrl = config('services.coingecko.proxy_url'); // 例如 https://xxx.workers.dev
            $proxyKey = config('services.coingecko.proxy_key'); // 你的“暗号”密钥

            $response = Http::withHeaders([
                'x-proxy-key' => $proxyKey // 发送暗号给 Worker
            ])
            ->timeout(20)      // 手机中转响应稍慢，给 20 秒宽容时间
            ->retry(3, 2000)   // 失败自动重试 3 次，每次间隔 2 秒
            ->get($proxyUrl, [ // 注意：如果你的 Worker 逻辑里带了后缀，这里要加，如 $proxyUrl . '/fetch'
                'ids' => implode(',', $ids),
                'vs_currencies' => 'usd'
            ]);

            if ($response->successful()) {
                $prices = $response->json();
                
                // 容错处理：有时中转返回了 200 但内容是空的
                if (empty($prices)) {
                    throw new \Exception("中转站响应成功但数据为空，可能手机端 API 报错。");
                }

                $totalPortfolioValue = 0;

                foreach ($prices as $tokenId => $data) {
                    if (!isset($data['usd'])) continue;
                    
                    $price = $data['usd'];

                    // A. 更新追踪表
                    DB::table('tracked_tokens')->where('coingecko_id', $tokenId)->update([
                        'last_price' => $price,
                        'updated_at' => $alignedTime
                    ]);

                    // B. 计算资产表
                    $assets = DB::table('assets')->where('coingecko_id', $tokenId)->get();
                    foreach ($assets as $asset) {
                        $newValue = (float) $asset->token_amount * $price;
                        // 注意：Cosmos DB 的 ID 字段可能叫 id 也可能叫 _id，请根据你实际数据库确认
                        $targetId = $asset->id ?? $asset->_id ?? null;

                        if ($targetId) {
                            DB::table('assets')
                                ->where('id', $targetId)
                                ->update([
                                    'value_usd' => $newValue,
                                    'updated_at' => $alignedTime
                                ]);
                            $totalPortfolioValue += $newValue;
                        }
                    }
                }

                // C. 记录快照 (这是你图表不乱的关键)
                $cexPortfolioValue = CexSyncedAsset::query()
                    ->where('is_active', true)
                    ->get()
                    ->sum(function ($asset) {
                        return (float) ($asset->value_usd ?? 0);
                    });

                $totalPortfolioValue += (float) $cexPortfolioValue;

                DB::table('asset_snapshots')->insert([
                    'total_value_usd' => $totalPortfolioValue,
                    'snapshot_time' => $alignedTime, 
                    'created_at' => now(), 
                ]);

                // --- 4. 锁定该槽位并更新成功状态 ---
                Cache::put($slotKey, true, 600); 
                Cache::put('sync_status', 'success', 3600);
                Cache::put('last_sync_at', $alignedTime->toDateTimeString(), 3600);

                $this->info("✅ 槽位 {$alignedTime->format('H:i:s')} 通过中转同步成功！");

            } else {
                $status = $response->status();
                $errorBody = $response->body();
                throw new \Exception("中转请求失败 (状态码: $status), 详情: $errorBody");
            }
        } catch (\Exception $e) {
            Cache::put('sync_status', 'error', 3600);
            $this->error('❌ 同步出错: ' . $e->getMessage());
        }
    }
}