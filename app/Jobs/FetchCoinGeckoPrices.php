<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\{Cache, Config, Http, Log};

class FetchCoinGeckoPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 120;

    public function handle(): void
    {
        $proxyUrl = Config::get('services.coingecko.proxy_url');
        $proxyKey = Config::get('services.coingecko.proxy_key');

        if (empty($proxyUrl) || empty($proxyKey)) {
            Log::warning('CoinGecko proxy not configured, skipping price fetch');
            return;
        }

        // Get all unique coingecko_ids from tracked assets
        $ids = $this->getTrackedCoinGeckoIds();

        if (empty($ids)) {
            return;
        }

        // Process in chunks of 50 (CoinGecko limit)
        $chunks = array_chunk($ids, 50);

        foreach ($chunks as $chunk) {
            $this->fetchAndCachePrices($chunk, $proxyUrl, $proxyKey);
        }

        Log::info('CoinGecko price fetch completed', ['updated_count' => count($ids)]);
    }

    private function getTrackedCoinGeckoIds(): array
    {
        // Get from tracked_tokens collection
        $trackedIds = \DB::table('tracked_tokens')
            ->pluck('coingecko_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Also get from assets and cex_synced_assets
        $assetIds = \App\Models\Asset::query()
            ->whereNotNull('coingecko_id')
            ->where('coingecko_id', '!=', '')
            ->pluck('coingecko_id')
            ->all();

        $cexIds = \App\Models\CexSyncedAsset::query()
            ->where('is_active', true)
            ->whereNotNull('coingecko_id')
            ->where('coingecko_id', '!=', '')
            ->pluck('coingecko_id')
            ->all();

        return array_values(array_unique(array_merge($trackedIds, $assetIds, $cexIds)));
    }

    private function fetchAndCachePrices(array $ids, string $proxyUrl, string $proxyKey): void
    {
        try {
            $res = Http::withHeaders(['x-proxy-key' => $proxyKey])
                ->timeout(30)
                ->get($proxyUrl, [
                    'ids' => implode(',', $ids),
                    'vs_currencies' => 'usd',
                ]);

            if (!$res->successful()) {
                Log::warning('CoinGecko price fetch failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);
                return;
            }

            $data = $res->json();
            if (!is_array($data)) {
                return;
            }

            foreach ($data as $id => $priceData) {
                if (isset($priceData['usd']) && is_numeric($priceData['usd'])) {
                    Cache::put("coingecko:price:{$id}", (float) $priceData['usd'], 300); // 5 min TTL
                }
            }
        } catch (\Throwable $e) {
            Log::error('CoinGecko price fetch exception', ['error' => $e->getMessage()]);
        }
    }
}