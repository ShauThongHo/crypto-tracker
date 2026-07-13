<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CexSyncedAsset;
use App\Models\CapitalFlow;
use Illuminate\Support\Facades\{Cache, DB, Http, Log};
use Illuminate\Support\Facades\Crypt;
use Carbon\Carbon;

class BalanceAlertService
{
    private const LOW_VALUE_ASSET_THRESHOLD = 0.01;
    private const PRICE_CACHE_TTL = 300;      // 5 minutes
    private const ASSET_SNAPSHOT_TTL = 3600;  // 1 hour
    private const ALLOCATION_FINGERPRINT_TTL = 86400; // 24 hours

    public function __construct(
        private readonly RebalanceService $rebalanceService,
        private readonly CexSyncService $cexSyncService
    ) {}

    /**
     * Main entry point - get balance alert snapshot with caching
     */
    public function getSnapshot(array $input): array
    {
        $normalizedInput = $this->normalizeInput($input);
        $cacheKey = $this->buildCacheKey($normalizedInput);

        return Cache::remember($cacheKey, 300, function () use ($normalizedInput) {
            return $this->buildSnapshotPayload($normalizedInput);
        });
    }

    /**
     * Build the complete snapshot payload (uncached inner logic)
     */
    private function buildSnapshotPayload(array $input): array
    {
        $prepareThreshold = (float) ($input['prepare_threshold'] ?? 3.0);
        $rebalanceThreshold = (float) ($input['rebalance_threshold'] ?? 5.0);
        $forceThreshold = (float) ($input['force_threshold'] ?? 7.5);

        // 1. Load all assets (cached per user/account)
        $assets = $this->loadAssetsSnapshot();
        $totalValue = $assets->sum('value');

        // 2. Load price map for all unique coingecko_ids (cached per token)
        $priceMap = $this->loadPriceMap($assets);

        // 3. Merge prices into assets
        $assetsWithPrices = $this->applyPrices($assets, $priceMap);

        // 4. Build allocations from categories + input overrides
        $allocations = $this->buildAllocations($input, $assetsWithPrices, $totalValue);

        // 5. Run rebalance calculation
        $rebalanceResult = $this->rebalanceService->calculateProportional(
            $allocations,
            $totalValue,
            $rebalanceThreshold
        );

        // 6. Build grouped items for response
        $items = $this->buildGroupedItems($rebalanceResult, $input);
        $maxDeviation = $this->calculateMaxDeviation($items, $rebalanceResult);

        // 7. Determine alert level
        $now = Carbon::now('Asia/Kuala_Lumpur');
        $isLateMonth = $now->day >= 21;
        $isQuarterRebalanceMonth = in_array($now->month, [1, 4, 7, 10], true);
        $inRebalanceWindow = $isLateMonth && $isQuarterRebalanceMonth;

        $level = 'none';
        $message = '当前偏离在安全范围内。';

        if ($maxDeviation >= $forceThreshold) {
            $level = 'force';
            $message = '偏离超过强制阈值，建议立即强制平衡。';
        } elseif ($maxDeviation >= $rebalanceThreshold && $inRebalanceWindow) {
            $level = 'rebalance';
            $message = '偏离超过平衡阈值且处于季度下旬窗口，建议执行平衡。';
        } elseif ($maxDeviation >= $prepareThreshold) {
            $level = 'prepare';
            $message = '偏离超过准备阈值，建议提前准备资金。';
        }

        return [
            'status' => 'success',
            'now' => $now->toDateTimeString(),
            'items' => $items,
            'known_symbols' => $assetsWithPrices->pluck('symbol')->unique()->values(),
            'window' => [
                'is_late_month' => $isLateMonth,
                'is_quarter_rebalance_month' => $isQuarterRebalanceMonth,
                'in_rebalance_window' => $inRebalanceWindow,
                'rule' => '每年 1/4/7/10 月下旬（21 号至月末）',
            ],
            'thresholds' => [
                'prepare_threshold' => $prepareThreshold,
                'rebalance_threshold' => $rebalanceThreshold,
                'force_threshold' => $forceThreshold,
            ],
            'portfolio' => [
                'total_value' => round($totalValue, 2),
                'allocation_count' => count($allocations),
                'default_target_pct' => count($allocations) > 0 ? round(100 / count($allocations), 2) : 0,
                'max_deviation_pct' => round($maxDeviation, 2),
                'target_input_total_pct' => array_sum(array_column($allocations, 'target_pct')),
                'target_normalized_total_pct' => (float) ($rebalanceResult['normalized_total_pct'] ?? 0),
            ],
            'advice' => [
                'threshold_pct' => $rebalanceThreshold,
                'k_factor' => (float) ($rebalanceResult['k_factor'] ?? 1.0),
                'normalized_total_pct' => (float) ($rebalanceResult['normalized_total_pct'] ?? 0),
                'max_deviation_pct' => $maxDeviation,
                'summary' => $rebalanceResult['summary'] ?? [
                    'buy_usd' => 0,
                    'sell_usd' => 0,
                    'net_usd' => 0,
                    'text' => '无需调仓',
                ],
            ],
            'level' => $level,
            'message' => $message,
            'allocations' => $allocations,
        ];
    }

    /**
     * Load all assets (manual + CEX) with caching
     */
    private function loadAssetsSnapshot(): \Illuminate\Support\Collection
    {
        $cacheKey = 'balance_alert:assets_snapshot:' . $this->getAssetsFingerprint();

        return Cache::remember($cacheKey, self::ASSET_SNAPSHOT_TTL, function () {
            $manualAssets = Asset::all(['id', 'symbol', 'token_name', 'coingecko_id', 'token_amount', 'value_usd', 'source_type', 'source_name', 'network', 'label', 'label_id'])
                ->map(function ($asset) {
                    return [
                        'id' => (string) $asset->id,
                        'symbol' => strtoupper((string) ($asset->symbol ?? $asset->token_name ?? 'UNKNOWN')),
                        'token_name' => (string) ($asset->token_name ?? ''),
                        'coingecko_id' => (string) ($asset->coingecko_id ?? ''),
                        'token_amount' => (float) ($asset->token_amount ?? 0),
                        'value_usd' => (float) ($asset->value_usd ?? 0),
                        'source_type' => 'manual',
                        'source_name' => (string) ($asset->source_name ?? 'Manual'),
                        'network' => (string) ($asset->network ?? ''),
                        'label' => (string) ($asset->label ?? ''),
                        'label_id' => (string) ($asset->label_id ?? ''),
                    ];
                });

            $autoAssets = CexSyncedAsset::query()
                ->where('is_active', true)
                ->where('value_usd', '>=', self::LOW_VALUE_ASSET_THRESHOLD)
                ->get(['id', 'symbol', 'token_name', 'coingecko_id', 'token_amount', 'value_usd', 'exchange', 'account_id', 'account_label', 'source_name', 'network', 'label', 'label_id'])
                ->map(function ($asset) {
                    return [
                        'id' => (string) $asset->id,
                        'symbol' => strtoupper((string) ($asset->symbol ?? $asset->token_name ?? 'UNKNOWN')),
                        'token_name' => (string) ($asset->token_name ?? ''),
                        'coingecko_id' => (string) ($asset->coingecko_id ?? ''),
                        'token_amount' => (float) ($asset->token_amount ?? 0),
                        'value_usd' => (float) ($asset->value_usd ?? 0),
                        'source_type' => 'cex',
                        'source_name' => (string) ($asset->source_name ?? 'CEX'),
                        'network' => 'CEX',
                        'label' => (string) ($asset->label ?? 'AUTO_SYNC'),
                        'label_id' => (string) ($asset->label_id ?? ''),
                    ];
                });

            return $manualAssets->concat($autoAssets)
                ->filter(fn($a) => ($a['value_usd'] ?? 0) >= self::LOW_VALUE_ASSET_THRESHOLD)
                ->values();
        });
    }

    /**
     * Get fingerprint for asset data invalidation
     */
    private function getAssetsFingerprint(): string
    {
        $manualCount = Asset::count();
        $manualUpdated = Asset::max('updated_at') ?? 'none';

        $cexCount = CexSyncedAsset::where('is_active', true)->count();
        $cexUpdated = CexSyncedAsset::where('is_active', true)->max('last_synced_at') ?? 'none';

        $hideLowValue = config('services.balance_alert.hide_low_value_assets_enabled', false) ? '1' : '0';

        return "m:{$manualCount}:{$manualUpdated}|c:{$cexCount}:{$cexUpdated}|h:{$hideLowValue}";
    }

    /**
     * Load price map for all unique coingecko_ids with per-token caching
     */
    private function loadPriceMap(\Illuminate\Support\Collection $assets): array
    {
        $coingeckoIds = $assets
            ->pluck('coingecko_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($coingeckoIds)) {
            return [];
        }

        $priceMap = [];
        $uncachedIds = [];

        // Check cache for each token
        foreach ($coingeckoIds as $id) {
            $cached = Cache::get("coingecko:price:{$id}");
            if ($cached !== null) {
                $priceMap[$id] = $cached;
            } else {
                $uncachedIds[] = $id;
            }
        }

        // Fetch uncached prices in batch via proxy
        if (!empty($uncachedIds)) {
            $fetched = $this->fetchPricesBatch($uncachedIds);
            foreach ($fetched as $id => $price) {
                $priceMap[$id] = $price;
                Cache::put("coingecko:price:{$id}", $price, self::PRICE_CACHE_TTL);
            }
        }

        return $priceMap;
    }

    /**
     * Batch fetch prices from CoinGecko proxy
     */
    private function fetchPricesBatch(array $ids): array
    {
        $proxyUrl = config('services.coingecko.proxy_url');
        $proxyKey = config('services.coingecko.proxy_key');

        if (empty($proxyUrl) || empty($proxyKey)) {
            return [];
        }

        try {
            $res = Http::withHeaders(['x-proxy-key' => $proxyKey])
                ->timeout(20)
                ->get($proxyUrl, [
                    'ids' => implode(',', $ids),
                    'vs_currencies' => 'usd',
                ]);

            if ($res->successful()) {
                $data = $res->json();
                if (is_array($data)) {
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CoinGecko batch price fetch failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Apply prices to assets
     */
    private function applyPrices(\Illuminate\Support\Collection $assets, array $priceMap): \Illuminate\Support\Collection
    {
        return $assets->map(function ($asset) use ($priceMap) {
            $coingeckoId = $asset['coingecko_id'] ?? '';
            $priceUsd = $coingeckoId !== '' ? (float) ($priceMap[$coingeckoId]['usd'] ?? 0) : 0;

            // Recalculate value if we have a price
            if ($priceUsd > 0 && ($asset['token_amount'] ?? 0) > 0) {
                $asset['value_usd'] = round($asset['token_amount'] * $priceUsd, 8);
            }

            $asset['price_usd'] = $priceUsd;
            return $asset;
        });
    }

    /**
     * Build allocations from categories + input overrides
     */
    private function buildAllocations(array $input, \Illuminate\Support\Collection $assets, float $totalValue): array
    {
        $categoryAllocations = $this->getCategoryAllocations($input);
        $symbolTargets = collect($input['target_allocations'] ?? []);

        // Group assets by symbol
        $tokenValueMap = $assets->groupBy('symbol')->map(function ($items) {
            return (float) $items->sum('value_usd');
        });

        $expanded = [];
        foreach ($categoryAllocations as $item) {
            $symbols = collect($item['symbols'] ?? [])->unique()->values();
            $categoryTargetInput = max(0, (float) ($item['target_pct'] ?? 0));

            $dbSymbolTargets = collect($item['symbol_targets'] ?? [])->mapWithKeys(function ($v, $k) {
                return [strtoupper(trim((string) $k)) => max(0, (float) $v)];
            });

            $combinedSymbolTargets = $dbSymbolTargets->merge($symbolTargets);
            $sumInCategory = $symbols->sum(fn($s) => (float) ($combinedSymbolTargets->get($s, 0)));

            if ($symbols->count() > 1 && $sumInCategory > 0) {
                foreach ($symbols as $symbol) {
                    $symbolValue = (float) ($tokenValueMap->get($symbol, 0));
                    $weight = $totalValue > 0 ? ($symbolValue / $totalValue) * 100 : 0;
                    $relative = $combinedSymbolTargets->get($symbol, 0) / $sumInCategory;
                    $symbolTargetInput = $categoryTargetInput * $relative;

                    $expanded[] = [
                        'id' => (string) $item['id'] . '|' . $symbol,
                        'name' => (string) $symbol,
                        'value' => round($symbolValue, 2),
                        'current_value' => $symbolValue,
                        'weight_pct' => round($weight, 2),
                        'target_pct_input' => (float) $symbolTargetInput,
                        'symbols' => [$symbol],
                    ];
                }
            } else {
                $allocationValue = $symbols->sum(fn($symbol) => (float) ($tokenValueMap->get($symbol, 0)));
                $weight = $totalValue > 0 ? ($allocationValue / $totalValue) * 100 : 0;

                $expanded[] = [
                    'id' => (string) $item['id'],
                    'name' => (string) $item['name'],
                    'value' => round($allocationValue, 2),
                    'current_value' => (float) $allocationValue,
                    'weight_pct' => round($weight, 2),
                    'target_pct_input' => max(0, (float) ($item['target_pct'] ?? 0)),
                    'symbols' => $symbols->all(),
                ];
            }
        }

        $allocationRows = collect($expanded)->values();
        $inputTargetTotal = (float) $allocationRows->sum('target_pct_input');
        $hasTargets = $allocationRows->isNotEmpty() && $inputTargetTotal > 0;
        $defaultTargetPct = $allocationRows->count() > 0 ? (100 / $allocationRows->count()) : 0;

        return $allocationRows->map(function ($row) use ($hasTargets, $inputTargetTotal, $defaultTargetPct) {
            $target = $hasTargets ? (($row['target_pct_input'] / $inputTargetTotal) * 100) : $defaultTargetPct;

            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'current_value' => (float) $row['current_value'],
                'target_pct' => (float) $target,
                'symbols' => $row['symbols'],
            ];
        })->values()->all();
    }

    /**
     * Get category allocations (from DB or input)
     */
    private function getCategoryAllocations(array $input): array
    {
        $rawAllocations = collect($input['allocations'] ?? []);

        if ($rawAllocations->isEmpty() && !empty($input['category_allocations'])) {
            $rawAllocations = collect($input['category_allocations']);
        }

        if ($rawAllocations->isEmpty()) {
            return $this->getStoredCategoryAllocations();
        }

        return $rawAllocations->map(function ($item, $index) {
            $symbols = collect($item['symbols'] ?? [])
                ->map(fn($s) => strtoupper(trim((string) $s)))
                ->filter()->unique()->values()->all();

            if (empty($symbols)) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    $symbols = [strtoupper($name)];
                }
            }

            $firstSymbol = $symbols[0] ?? strtoupper(trim((string) ($item['name'] ?? '')));
            $fallbackName = count($symbols) === 1 ? $firstSymbol : '组合 ' . ($index + 1);

            return [
                'id' => trim((string) ($item['id'] ?? '')) ?: 'row-' . ($index + 1),
                'name' => trim((string) ($item['name'] ?? '')) ?: $fallbackName,
                'target_pct' => max(0, (float) ($item['target_pct'] ?? 0)),
                'symbols' => $symbols,
                'symbol_targets' => collect($item['symbol_targets'] ?? [])->mapWithKeys(function ($v, $k) {
                    return [strtoupper(trim((string) $k)) => max(0, (float) $v)];
                })->all(),
            ];
        })->values()->all();
    }

    /**
     * Get stored category allocations from DB
     */
    private function getStoredCategoryAllocations(): array
    {
        return DB::table('asset_categories')->get()
            ->map(function ($item, $index) {
                $rawId = $item->_id ?? ($item->id ?? null);
                $id = '';
                if (is_object($rawId)) {
                    $id = $rawId->{'$oid'} ?? (string) $rawId;
                } elseif ($rawId !== null) {
                    $id = (string) $rawId;
                }

                $symbols = collect($item->symbols ?? [])
                    ->map(fn($s) => strtoupper(trim((string) $s)))
                    ->filter()->unique()->values()->all();

                return [
                    'id' => $id ?: 'category-' . ($index + 1),
                    'name' => trim((string) ($item->name ?? '')),
                    'target_pct' => max(0, (float) ($item->target_pct ?? 0)),
                    'symbols' => $symbols,
                    'symbol_targets' => is_array($item->symbol_targets ?? null)
                        ? array_map(fn($v) => max(0, (float) $v), $item->symbol_targets)
                        : [],
                ];
            })
            ->filter(fn($item) => trim((string) ($item['name'] ?? '')) !== '')
            ->sortBy(fn($item) => mb_strtolower(trim((string) ($item['name'] ?? ''))))
            ->values()
            ->all();
    }

    /**
     * Build grouped items for response
     */
    private function buildGroupedItems(array $rebalanceResult, array $input): array
    {
        $flatItems = collect($rebalanceResult['items'] ?? [])->values();
        $allocationSourceMap = collect($this->getCategoryAllocations($input))->keyBy('id');

        $groupedItems = $flatItems
            ->groupBy(function ($item) {
                $id = (string) ($item['id'] ?? '');
                return str_contains($id, '|') ? explode('|', $id, 2)[0] : $id;
            })
            ->map(function ($groupItems, $groupId) use ($allocationSourceMap) {
                $groupItems = collect($groupItems)->values();
                $source = $allocationSourceMap->get($groupId, []);

                $children = $groupItems->map(function ($item) use ($groupId) {
                    $child = $item;
                    $child['group_id'] = $groupId;
                    $child['is_child'] = true;
                    return $child;
                })->sortByDesc('abs_deviation_pct')->values()->all();

                $rows = $groupItems->all();
                $currentValue = array_sum(array_map(fn($r) => (float) ($r['current_value'] ?? 0), $rows));
                $targetValue = array_sum(array_map(fn($r) => (float) ($r['new_target_value'] ?? 0), $rows));
                $currentPct = array_sum(array_map(fn($r) => (float) ($r['current_pct'] ?? 0), $rows));
                $targetPct = array_sum(array_map(fn($r) => (float) ($r['target_pct'] ?? 0), $rows));
                $rebalancedTargetPct = array_sum(array_map(fn($r) => (float) ($r['new_target_pct'] ?? 0), $rows));
                $deviationPct = $targetPct - $currentPct;
                $adviceUsd = array_sum(array_map(fn($r) => (float) ($r['advice_usd'] ?? 0), $rows));
                $absDeviation = abs($deviationPct);

                $parent = [
                    'id' => $groupId,
                    'name' => (string) ($source['name'] ?? ($groupItems->first()['name'] ?? $groupId)),
                    'value' => round($currentValue, 2),
                    'current_value' => (float) $currentValue,
                    'current_pct' => (float) $currentPct,
                    'weight_pct' => (float) $currentPct,
                    'target_pct' => (float) $targetPct,
                    'new_target_pct' => (float) $rebalancedTargetPct,
                    'new_target_value' => (float) $targetValue,
                    'deviation_pct' => (float) $deviationPct,
                    'abs_deviation_pct' => (float) $absDeviation,
                    'advice_usd' => (float) $adviceUsd,
                    'advice_action' => $adviceUsd > 0 ? 'buy' : ($adviceUsd < 0 ? 'sell' : 'hold'),
                    'is_active' => true,
                    'symbols' => array_values(array_unique((array) ($source['symbols'] ?? []))),
                ];

                if (!empty($children)) {
                    $parent['children'] = $children;
                }

                return $parent;
            })
            ->sortByDesc('abs_deviation_pct')
            ->values()
            ->all();

        return $groupedItems;
    }

    /**
     * Calculate max deviation from grouped items
     */
    private function calculateMaxDeviation(array $items, array $rebalanceResult): float
    {
        if (empty($items)) {
            return (float) ($rebalanceResult['max_deviation_pct'] ?? 0);
        }

        return (float) collect($items)->max(function ($item) {
            return (float) ($item['abs_deviation_pct'] ?? 0);
        });
    }

    /**
     * Normalize input for cache key
     */
    private function normalizeInput(array $input): array
    {
        $normalized = [
            'prepare_threshold' => (float) ($input['prepare_threshold'] ?? 3.0),
            'rebalance_threshold' => (float) ($input['rebalance_threshold'] ?? 5.0),
            'force_threshold' => (float) ($input['force_threshold'] ?? 7.5),
        ];

        // Normalize allocations for cache key
        foreach (['allocations', 'category_allocations', 'target_allocations'] as $key) {
            if (!empty($input[$key])) {
                $normalized[$key] = $this->normalizeAllocations($input[$key]);
            }
        }

        return $normalized;
    }

    /**
     * Normalize allocations array for consistent cache keys
     */
    private function normalizeAllocations(array $allocations): array
    {
        return collect($allocations)->map(function ($item) {
            return [
                'id' => trim((string) ($item['id'] ?? '')),
                'name' => trim((string) ($item['name'] ?? '')),
                'target_pct' => max(0, (float) ($item['target_pct'] ?? 0)),
                'symbols' => collect($item['symbols'] ?? [])
                    ->map(fn($s) => strtoupper(trim((string) $s)))
                    ->filter()->unique()->sort()->values()->all(),
                'symbol_targets' => collect($item['symbol_targets'] ?? [])
                    ->mapWithKeys(fn($v, $k) => [strtoupper(trim((string) $k)) => max(0, (float) $v)])
                    ->sortKeys()->all(),
            ];
        })->sortBy('id')->values()->all();
    }

    /**
     * Build cache key from normalized input
     */
    private function buildCacheKey(array $normalizedInput): string
    {
        $allocationsFingerprint = Cache::remember(
            'balance_alert:allocations_fingerprint',
            self::ALLOCATION_FINGERPRINT_TTL,
            fn() => $this->getAllocationsFingerprint()
        );

        $normalizedInput['_allocations_fingerprint'] = $allocationsFingerprint;

        return 'balance_alert:snapshot:' . md5(json_encode($normalizedInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get allocations fingerprint for cache invalidation
     */
    private function getAllocationsFingerprint(): string
    {
        $categoryRows = DB::table('asset_categories')->get();
        $count = $categoryRows->count();
        $lastUpdated = $categoryRows->map(fn($r) => $r->updated_at ?? $r->created_at ?? null)
            ->filter()->sort()->last();

        return $count . ':' . ($lastUpdated ?? 'none');
    }
}