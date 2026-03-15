<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class SyncCryptoData extends Command
{
    protected $signature = 'app:sync-crypto-data';
    protected $description = '每5分钟刷新加密货币价格并存入资产快照';

    public function handle()
    {
        $this->info('📡 正在从 CoinGecko 获取最新价格...');

        // 1. 获取所有需要追踪的 ID
        $trackedTokens = DB::table('tracked_tokens')->get();
        $ids = $trackedTokens->pluck('coingecko_id')->toArray();

        if (empty($ids)) {
            $this->warn('未找到任何追踪代币。');
            return;
        }

        // 2. 调用 API 获取价格
        $response = Http::get("https://api.coingecko.com/api/v3/simple/price", [
            'ids' => implode(',', $ids),
            'vs_currencies' => 'usd'
        ]);

        if ($response->successful()) {
            $prices = $response->json();
            $totalPortfolioValue = 0;

            foreach ($prices as $tokenId => $data) {
                $price = $data['usd'];

                // --- 关键点 A：更新追踪表的价格 ---
                DB::table('tracked_tokens')->where('coingecko_id', $tokenId)->update([
                    'last_price' => $price,
                    'updated_at' => now()
                ]);

                // --- 关键点 B：计算并更新 assets 表里的每一项资产 ---
                // 找出所有属于这个 ID 的资产记录
                $assets = DB::table('assets')->where('coingecko_id', $tokenId)->get();

                foreach ($assets as $asset) {
                    $newValue = (float) $asset->token_amount * $price;

                    // --- 修复开始：安全获取 ID ---
                    // 尝试获取 _id，如果不存在就取 id
                    $targetId = $asset->_id ?? $asset->id ?? null;

                    if ($targetId) {
                        // 更新资产美金价值
                        // 在 where 条件里，也同样尝试匹配 _id 或 id
                        DB::table('assets')
                            ->where('id', $targetId)
                            ->update([
                                'value_usd' => $newValue,
                                'updated_at' => now()
                            ]);

                        $totalPortfolioValue += $newValue;
                        $this->info("✨ 已更新 {$asset->token_name}: \${$newValue}");
                    } else {
                        $this->error("❌ 无法找到资产 ID，跳过更新。");
                    }
                    // --- 修复结束 ---
                }
            }

            // 3. 记录快照 (用于图表)
            DB::table('asset_snapshots')->insert([
                'total_value_usd' => $totalPortfolioValue,
                'snapshot_time' => now()
            ]);

            $this->info('✅ 同步成功！已完成全线资产重算。');
        } else {
            $this->error('API 请求失败，请检查网络或频率限制。');
        }
    }
}