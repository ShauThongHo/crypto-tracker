<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache; // 💡 引入缓存
use Carbon\Carbon;

class SyncCryptoData extends Command
{
    protected $signature = 'app:sync-crypto-data';
    protected $description = '精准对齐5分钟整点，刷新价格并存入资产快照';

    public function handle()
    {
        // --- 1. 计算对齐时间 (5分钟对齐) ---
        $now = time();
        $interval = 300; // 5分钟 = 300秒
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

        $this->info("📡 正在对齐到 {$alignedTime->format('H:i:s')} 同步价格...");

        try {
            // 获取所有需要追踪的 ID
            $trackedTokens = DB::table('tracked_tokens')->get();
            $ids = $trackedTokens->pluck('coingecko_id')->toArray();

            if (empty($ids)) {
                $this->warn('未找到任何追踪代币。');
                Cache::put('sync_status', 'idle', 60);
                return;
            }

            // 调用 API
            $response = Http::get("https://api.coingecko.com/api/v3/simple/price", [
                'ids' => implode(',', $ids),
                'vs_currencies' => 'usd'
            ]);

            if ($response->successful()) {
                $prices = $response->json();
                $totalPortfolioValue = 0;

                foreach ($prices as $tokenId => $data) {
                    $price = $data['usd'];

                    // A. 更新追踪表 (使用对齐后的时间)
                    DB::table('tracked_tokens')->where('coingecko_id', $tokenId)->update([
                        'last_price' => $price,
                        'updated_at' => $alignedTime // 💡 统一使用对齐时间
                    ]);

                    // B. 计算资产表
                    $assets = DB::table('assets')->where('coingecko_id', $tokenId)->get();
                    foreach ($assets as $asset) {
                        $newValue = (float) $asset->token_amount * $price;
                        $targetId = $asset->_id ?? $asset->id ?? null;

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
                DB::table('asset_snapshots')->insert([
                    'total_value_usd' => $totalPortfolioValue,
                    'snapshot_time' => $alignedTime, // 🎯 强行存入 10:00:00, 10:05:00...
                    'created_at' => now(), // 记录实际插入时间以便调试
                ]);

                // --- 4. 锁定该槽位并更新成功状态 ---
                Cache::put($slotKey, true, 600); // 锁定10分钟防止重复
                Cache::put('sync_status', 'success', 3600);
                Cache::put('last_sync_at', $alignedTime->toDateTimeString(), 3600);

                $this->info("✅ 槽位 {$alignedTime->format('H:i:s')} 同步成功！");
            } else {
                throw new \Exception("API Request Failed");
            }
        } catch (\Exception $e) {
            Cache::put('sync_status', 'error', 3600);
            $this->error('❌ 同步出错: ' . $e->getMessage());
        }
    }
}