<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Artisan, Http, Cache};
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\{CapitalFlow, Asset, CexSyncedAsset, ExchangeAccount};
use App\Services\{RebalanceService, CexSyncService, BalanceAlertService};
use App\Jobs\GenerateBalanceAlertImage;

class AssetController extends Controller
{
    private const LOW_VALUE_ASSET_FILTER_THRESHOLD_USD = 0.01;

    public function __construct(
        private readonly BalanceAlertService $balanceAlertService
    ) {}

    // =========================================================================
    // 1. 核心看板数据 (Dashboard Data)
    // =========================================================================

    /**
     * 获取资产分布思维导图数据
     */
    public function getAssetThinkingMap()
    {
        $manualAssets = Asset::all();
        $autoAssets = CexSyncedAsset::query()
            ->where('is_active', true)
            ->get();

        $assets = $manualAssets->concat($autoAssets);
        if ($this->shouldHideLowValueAssets()) {
            $assets = $assets->filter(function ($asset) {
                return (float) ($asset->value_usd ?? 0) >= self::LOW_VALUE_ASSET_FILTER_THRESHOLD_USD;
            })->values();
        }

        $trackedTokens = DB::table('tracked_tokens')->get()->keyBy('coingecko_id');

        $totalValue = $assets->sum(function ($item) {
            return is_numeric($item->value_usd) ? (float) $item->value_usd : 0;
        });

        $tree = [
            'name' => '总资产 (USD)',
            'value' => round($totalValue, 2),
            'children' => []
        ];

        $formatted = $assets->groupBy(function ($asset) {
            $sourceType = (string) ($asset->source_type ?? 'manual');
            $sourceName = trim((string) ($asset->source_name ?? 'Unknown'));

            return $sourceType . '||' . $sourceName;
        })->map(function ($sourceAssets, $groupKey) use ($trackedTokens) {
            [$sourceType, $sourceName] = array_pad(explode('||', (string) $groupKey, 2), 2, '');
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
                            'label' => $asset->label ?? '',
                            'label_id' => $asset->label_id ?? '',
                            'source_type' => (string) ($asset->source_type ?? 'manual'),
                            'is_auto_synced' => (string) ($asset->source_type ?? 'manual') !== 'manual',
                        ];
                    })->values()
                ];
            })->values();

            return [
                'name' => $sourceName,
                'source_type' => $sourceType !== '' ? $sourceType : 'manual',
                'value' => round($sourceVal, 2),
                'children' => $networks,
            ];
        })->values();

        $tree['children'] = $formatted;
        return response()->json($tree);
    }

    // =========================================================================
    // 1. 核心看板数据 (Dashboard Data)
    // =========================================================================

    public function healthCheck()
    {
        try {
            $cexSync = app(CexSyncService::class)->syncEnabledAccounts('health-check');
            Artisan::call('app:sync-crypto-data');
            $output = Artisan::output();
            $autoNotify = $this->attemptHealthCheckAutoNotify();

            Log::info('Health check completed', [
                'output' => $output,
                'auto_notify' => $autoNotify,
                'cex_sync' => $cexSync,
            ]);

            return response()->json([
                'status' => 'alive',
                'time' => now()->toDateTimeString(),
                'command_output' => $output,
                'auto_notify' => $autoNotify,
                'cex_sync' => $cexSync,
            ]);
        } catch (\Throwable $e) {
            Log::error('Health check failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function getBalanceAlertAutomationConfig(): array
    {
        $storedWebhookUrl = $this->getStoredBalanceAlertWebhookUrl();
        $envWebhookUrl = trim((string) config('services.balance_alert.auto_notify_webhook_url', ''));
        $webhookUrl = $envWebhookUrl !== '' ? $envWebhookUrl : $storedWebhookUrl;

        return [
            'enabled' => (bool) config('services.balance_alert.auto_notify_enabled', false) || trim($webhookUrl) !== '',
            'webhook_url' => $webhookUrl,
            'webhook_source' => $envWebhookUrl !== '' ? 'env' : ($storedWebhookUrl !== '' ? 'db' : 'missing'),
            'prepare_threshold' => (float) config('services.balance_alert.auto_notify_prepare_threshold', config('services.balance_alert.prepare_threshold', 3.0)),
            'rebalance_threshold' => (float) config('services.balance_alert.auto_notify_rebalance_threshold', config('services.balance_alert.rebalance_threshold', 5.0)),
            'force_threshold' => (float) config('services.balance_alert.auto_notify_force_threshold', config('services.balance_alert.force_threshold', 7.5)),
        ];
    }

    public function getBalanceAlertSettings()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'webhook_url' => $this->getStoredBalanceAlertWebhookUrl(),
                'hide_low_value_assets' => $this->getStoredHideLowValueAssetsEnabled(),
                'hide_low_value_assets_threshold_usd' => self::LOW_VALUE_ASSET_FILTER_THRESHOLD_USD,
            ],
        ]);
    }

    public function updateBalanceAlertSettings(Request $request)
    {
        $v = $request->validate([
            'webhook_url' => 'nullable|url',
            'hide_low_value_assets' => 'nullable|boolean',
        ]);

        $webhookUrl = $this->getStoredBalanceAlertWebhookUrl();
        if ($request->has('webhook_url')) {
            $webhookUrl = trim((string) ($v['webhook_url'] ?? ''));
            $this->storeBalanceAlertWebhookUrl($webhookUrl);
        }

        $hideLowValueAssets = $this->getStoredHideLowValueAssetsEnabled();
        if ($request->has('hide_low_value_assets')) {
            $hideLowValueAssets = (bool) ($v['hide_low_value_assets'] ?? false);
            $this->storeHideLowValueAssetsEnabled($hideLowValueAssets);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'webhook_url' => $webhookUrl,
                'hide_low_value_assets' => $hideLowValueAssets,
                'hide_low_value_assets_threshold_usd' => self::LOW_VALUE_ASSET_FILTER_THRESHOLD_USD,
            ],
        ]);
    }

    private function getStoredBalanceAlertWebhookUrl(): string
    {
        $row = DB::table('app_settings')->where('key', 'balance_alert_webhook_url')->first();

        if (!$row) {
            return '';
        }

        return trim((string) ($row->value ?? ''));
    }

    private function storeBalanceAlertWebhookUrl(string $webhookUrl): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'balance_alert_webhook_url'],
            [
                'value' => $webhookUrl,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function shouldHideLowValueAssets(): bool
    {
        return $this->getStoredHideLowValueAssetsEnabled();
    }

    private function getStoredHideLowValueAssetsEnabled(): bool
    {
        $row = DB::table('app_settings')->where('key', 'hide_low_value_assets_enabled')->first();
        if (!$row) {
            return false;
        }

        $raw = strtolower(trim((string) ($row->value ?? '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function storeHideLowValueAssetsEnabled(bool $enabled): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'hide_low_value_assets_enabled'],
            [
                'value' => $enabled ? '1' : '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function getStoredBalanceAlertCategoryAllocations(): array
    {
        return DB::table('asset_categories')->get()
            ->map(function ($item, $index) {
                $rawId = $item->_id ?? ($item->id ?? null);
                $id = '';

                if (is_object($rawId)) {
                    if (isset($rawId->{'$oid'})) {
                        $id = (string) $rawId->{'$oid'};
                    } elseif (method_exists($rawId, '__toString')) {
                        $id = (string) $rawId;
                    }
                } elseif ($rawId !== null) {
                    $id = (string) $rawId;
                }

                $symbols = collect($item->symbols ?? [])
                    ->map(function ($symbol) {
                        return strtoupper(trim((string) $symbol));
                    })
                    ->filter(function ($symbol) {
                        return $symbol !== '';
                    })
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'id' => $id ?: 'category-' . ($index + 1),
                    'name' => trim((string) ($item->name ?? '')),
                    'target_pct' => max(0, (float) ($item->target_pct ?? 0)),
                    'symbols' => $symbols,
                    'symbol_targets' => is_array($item->symbol_targets ?? null) ? array_map(function ($v) {
                        return max(0, (float) $v);
                    }, $item->symbol_targets) : [],
                ];
            })
            ->filter(function ($item) {
                return trim((string) ($item['name'] ?? '')) !== '';
            })
            ->sortBy(function ($item) {
                return mb_strtolower(trim((string) ($item['name'] ?? '')));
            })
            ->values()
            ->all();
    }

    private function secondsUntilEndOfDay(): int
    {
        $now = Carbon::now('Asia/Kuala_Lumpur');
        $seconds = $now->diffInSeconds($now->copy()->endOfDay(), false);
        return max(60, (int) $seconds);
    }

    private function attemptHealthCheckAutoNotify(): array
    {
        $config = $this->getBalanceAlertAutomationConfig();
        if (!$config['enabled'] || trim($config['webhook_url']) === '') {
            return [
                'sent' => false,
                'reason' => 'disabled_or_missing_webhook',
                'webhook_source' => $config['webhook_source'],
            ];
        }

        // 23:59 自动清空当日触发计数，避免跨日误累计。
        $this->resetHealthCheckTriggerCounterAtDayEndIfNeeded();

        $snapshot = $this->balanceAlertService->getSnapshot($config + [
            'category_allocations' => $this->getStoredBalanceAlertCategoryAllocations(),
        ]);

        $decision = $this->determineHealthCheckAutoNotifyLevel($snapshot, $config);
        $level = (string) ($decision['level'] ?? 'none');
        if ($level === 'none') {
            $this->resetHealthCheckTriggerCounter();
            return array_merge([
                'sent' => false,
                'count' => 0,
                'required_count' => 2,
                'webhook_source' => $config['webhook_source'],
            ], $decision);
        }

        $dateKey = Carbon::now('Asia/Kuala_Lumpur')->toDateString();
        $countKey = "balance_alert:auto:{$dateKey}:{$level}:streak";
        $sentKey = "balance_alert:auto:{$dateKey}:{$level}:sent";
        $lastLevelKey = "balance_alert:auto:{$dateKey}:last_level";
        $ttl = $this->secondsUntilEndOfDay();

        $lastLevel = (string) Cache::get($lastLevelKey, 'none');
        $previousCount = (int) Cache::get($countKey, 0);
        $currentCount = $lastLevel === $level ? $previousCount + 1 : 1;

        Cache::put($lastLevelKey, $level, $ttl);
        Cache::put($countKey, $currentCount, $ttl);

        if ($currentCount < 2) {
            return [
                'sent' => false,
                'reason' => 'waiting_second_trigger',
                'count' => $currentCount,
                'required_count' => 2,
                'level' => $level,
                'webhook_source' => $config['webhook_source'],
                'in_window' => (bool) data_get($decision, 'in_window', false),
            ];
        }

        if (Cache::has($sentKey)) {
            return [
                'sent' => false,
                'reason' => 'already_sent_today',
                'count' => $currentCount,
                'required_count' => 2,
                'level' => $level,
                'webhook_source' => $config['webhook_source'],
                'in_window' => (bool) data_get($decision, 'in_window', false),
            ];
        }

        // Generate PNG image for health check notification
        $tmpDir = sys_get_temp_dir();
        $jsonPath = tempnam($tmpDir, 'hc_alert_') . '.json';
        $pngPath = tempnam($tmpDir, 'hc_alert_') . '.png';

        file_put_contents($jsonPath, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $pythonScript = base_path('tools/render_balance_alert_table.py');
        $pythonBin = $this->resolvePythonBinary();
        $processOutput = '';

        try {
            if (class_exists('\Symfony\\Component\\Process\\Process')) {
                $process = new \Symfony\Component\Process\Process([$pythonBin, $pythonScript, $jsonPath, $pngPath]);
                $process->setTimeout(30);
                $process->run();
                $processOutput = $process->getOutput() . $process->getErrorOutput();
                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('Image renderer failed: ' . $processOutput);
                }
            } else {
                $cmd = '"' . str_replace('"', '\\"', $pythonBin) . '" ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($jsonPath) . ' ' . escapeshellarg($pngPath) . ' 2>&1';
                $processOutput = shell_exec($cmd);
                if (!file_exists($pngPath) || filesize($pngPath) === 0) {
                    throw new \RuntimeException('Image renderer failed (shell): ' . $processOutput);
                }
            }
        } catch (\Throwable $e) {
            @unlink($jsonPath);
            @unlink($pngPath);
            Log::error('Health check notify image render failed', [
                'error' => $e->getMessage(),
                'output' => $processOutput,
            ]);
            return [
                'sent' => false,
                'reason' => 'render_failed',
                'error' => $e->getMessage(),
                'count' => $currentCount,
                'required_count' => 2,
                'level' => $level,
                'webhook_source' => $config['webhook_source'],
                'in_window' => (bool) data_get($decision, 'in_window', false),
            ];
        }

        if (!file_exists($pngPath) || filesize($pngPath) === 0) {
            @unlink($jsonPath);
            @unlink($pngPath);
            Log::error('Health check notify image missing', [
                'output' => $processOutput,
            ]);
            return [
                'sent' => false,
                'reason' => 'image_missing',
                'count' => $currentCount,
                'required_count' => 2,
                'level' => $level,
                'webhook_source' => $config['webhook_source'],
                'in_window' => (bool) data_get($decision, 'in_window', false),
            ];
        }

        $levelMap = [
            'force' => '强制平衡',
            'rebalance' => '执行平衡',
            'prepare' => '准备资金',
            'none' => '无需提醒',
        ];
        $levelText = $levelMap[$level] ?? $level;
        $windowText = (bool) data_get($snapshot, 'window.in_rebalance_window', false) ? '在平衡窗口' : '未在平衡窗口';
        $date = substr((string) ($snapshot['now'] ?? now()->toDateTimeString()), 0, 10);
        $maxDev = number_format((float) ($snapshot['portfolio']['max_deviation_pct'] ?? 0), 2);
        $content = implode("\n", [
            '【自动健康检查】',
            '等级: ' . $levelText,
            '平衡时机: ' . $windowText,
            '日期: ' . $date,
            '最大偏离: ' . $maxDev . '%',
            '触发计数: ' . $currentCount . '/2',
            '',
            '偏离明细:',
        ]);

        try {
            $res = Http::timeout(30)->attach('file', file_get_contents($pngPath), 'health_check_alert.png')
                ->post($config['webhook_url'], ['content' => $content]);

            if (!$res->successful()) {
                throw new \RuntimeException('Webhook returned ' . $res->status() . ': ' . $res->body());
            }
        } catch (\Throwable $e) {
            @unlink($jsonPath);
            @unlink($pngPath);
            Log::error('Health check notify webhook failed', [
                'error' => $e->getMessage(),
            ]);
            return [
                'sent' => false,
                'reason' => 'webhook_failed',
                'error' => $e->getMessage(),
                'count' => $currentCount,
                'required_count' => 2,
                'level' => $level,
                'webhook_source' => $config['webhook_source'],
                'in_window' => (bool) data_get($decision, 'in_window', false),
            ];
        }

        @unlink($jsonPath);
        @unlink($pngPath);

        Cache::put($sentKey, true, $ttl);

        return [
            'sent' => true,
            'reason' => 'dispatched',
            'count' => $currentCount,
            'required_count' => 2,
            'level' => $level,
            'webhook_source' => $config['webhook_source'],
            'in_window' => (bool) data_get($decision, 'in_window', false),
        ];
    }

    private function resetHealthCheckTriggerCounter(): void
    {
        $dateKey = Carbon::now('Asia/Kuala_Lumpur')->toDateString();
        Cache::forget("balance_alert:auto:{$dateKey}:last_level");
        Cache::forget("balance_alert:auto:{$dateKey}:prepare:streak");
        Cache::forget("balance_alert:auto:{$dateKey}:rebalance:streak");
        Cache::forget("balance_alert:auto:{$dateKey}:force:streak");
    }

    private function resetHealthCheckTriggerCounterAtDayEndIfNeeded(): void
    {
        $now = Carbon::now('Asia/Kuala_Lumpur');
        if ((int) $now->format('Hi') < 2359) {
            return;
        }

        $dateKey = $now->toDateString();
        $flagKey = "balance_alert:auto:{$dateKey}:day_end_counter_reset";
        if (Cache::has($flagKey)) {
            return;
        }

        $this->resetHealthCheckTriggerCounter();
        Cache::put($flagKey, true, $this->secondsUntilEndOfDay());
    }

    private function determineHealthCheckAutoNotifyLevel(array $snapshot, array $config): array
    {
        $maxDeviation = (float) data_get($snapshot, 'advice.max_deviation_pct', data_get($snapshot, 'portfolio.max_deviation_pct', 0));
        $inWindow = (bool) data_get($snapshot, 'window.in_rebalance_window', false);

        if ($maxDeviation >= (float) $config['force_threshold']) {
            return [
                'level' => 'force',
                'reason' => 'force_threshold_reached',
                'in_window' => $inWindow,
                'max_deviation_pct' => $maxDeviation,
            ];
        }

        if ($maxDeviation >= (float) $config['rebalance_threshold']) {
            if ($inWindow) {
                return [
                    'level' => 'rebalance',
                    'reason' => 'rebalance_threshold_reached_in_window',
                    'in_window' => true,
                    'max_deviation_pct' => $maxDeviation,
                ];
            }

            return [
                'level' => 'none',
                'reason' => 'rebalance_threshold_outside_window',
                'in_window' => false,
                'max_deviation_pct' => $maxDeviation,
            ];
        }

        if ($maxDeviation >= (float) $config['prepare_threshold']) {
            return [
                'level' => 'prepare',
                'reason' => 'prepare_threshold_reached',
                'in_window' => $inWindow,
                'max_deviation_pct' => $maxDeviation,
            ];
        }

        return [
            'level' => 'none',
            'reason' => 'below_prepare_threshold',
            'in_window' => $inWindow,
            'max_deviation_pct' => $maxDeviation,
        ];
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

        // For ALL range, only return calendar data; for other ranges, return time-series data
        if ($range === 'ALL') {
            return response()->json(['calendar' => $this->buildCalendarSeries($snapshots, $flows)]);
        }

        $payload = $this->buildSnapshotSeries($snapshots, $flows, $range);
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
            ->sortBy('time')
            ->groupBy(function ($row) {
                return $row['time']->copy()->format('Y-m-d H:i:s');
            })
            ->map(function ($rows) {
                return $rows->last();
            })
            ->values()
            ->all();

        if (empty($normalizedSnapshots)) {
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
            $firstSnapshotTime = $normalizedSnapshots[0]['time'] ?? null;
            if (!$firstSnapshotTime instanceof Carbon) {
                $firstSnapshotTime = Carbon::parse((string) $firstSnapshotTime)->setTimezone('Asia/Kuala_Lumpur');
            }

            $start = $range === 'ALL'
                ? $firstSnapshotTime->copy()->startOfDay()
                : $now->copy()->subDays(30)->startOfDay();
            $cursor = $start;
            while ($cursor->lte($now)) {
                $bucketTimes[] = $cursor->copy()->hour(0)->minute(0)->second(0);
                $cursor->addDay();
            }
        } else {
            $bucketTimes = collect($normalizedSnapshots)->pluck('time')->all();
        }

        $times = [];
        $values = [];
        $invested = [];
        $snapshotIndex = 0;
        $flowIndex = 0;
        $latestSnapshot = null;
        $netInvested = 0;
        $snapshotCount = count($normalizedSnapshots);
        $flowCount = $normalizedFlows->count();

        foreach ($bucketTimes as $bucketTime) {
            while ($snapshotIndex < $snapshotCount) {
                $snapshotRow = $normalizedSnapshots[$snapshotIndex] ?? null;
                $snapshotTime = $snapshotRow['time'] ?? null;
                if (!$snapshotTime instanceof Carbon) {
                    $snapshotIndex++;
                    continue;
                }

                if (!$snapshotTime->lte($bucketTime)) {
                    break;
                }

                $latestSnapshot = $snapshotRow;
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
            $cexSync = app(CexSyncService::class)->syncEnabledAccounts('manual-sync');
            Artisan::call('app:sync-crypto-data');

            return response()->json([
                'status' => 'success',
                'last_sync' => Cache::get('last_sync_at'),
                'cex_sync' => $cexSync,
            ]);
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

    public function getExchangeAccounts()
    {
        $accounts = ExchangeAccount::query()
            ->get()
            ->sortByDesc(function ($account) {
                $createdAt = $account->created_at ?? null;
                if (!$createdAt) {
                    return 0;
                }

                try {
                    return Carbon::parse($createdAt)->timestamp;
                } catch (\Throwable $e) {
                    return 0;
                }
            })
            ->values()
            ->map(function ($account) {
                return [
                    'id' => (string) $account->id,
                    'exchange' => strtolower((string) ($account->exchange ?? '')),
                    'label' => (string) ($account->label ?? ''),
                    'enabled' => (bool) ($account->enabled ?? false),
                    'has_passphrase' => trim((string) ($account->api_passphrase_enc ?? '')) !== '',
                    'last_sync_status' => (string) ($account->last_sync_status ?? ''),
                    'last_sync_at' => $account->last_sync_at ? Carbon::parse($account->last_sync_at)->toDateTimeString() : null,
                    'last_error' => (string) ($account->last_error ?? ''),
                    'api_key_masked' => $this->maskApiKey((string) ($account->api_key_enc ?? ''), true),
                ];
            })
            ->values();

        return response()->json($accounts);
    }

    public function storeExchangeAccount(Request $request)
    {
        $v = $request->validate([
            'exchange' => 'required|in:okx,bitget',
            'label' => 'required|string|max:80',
            'api_key' => 'required|string|max:255',
            'api_secret' => 'required|string|max:255',
            'passphrase' => 'nullable|string|max:255',
            'api_passphrase' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
        ]);

        $passphrase = trim((string) ($v['api_passphrase'] ?? $v['passphrase'] ?? ''));

        $account = ExchangeAccount::create([
            'exchange' => strtolower(trim((string) $v['exchange'])),
            'label' => trim((string) $v['label']),
            'api_key_enc' => Crypt::encryptString(trim((string) $v['api_key'])),
            'api_secret_enc' => Crypt::encryptString(trim((string) $v['api_secret'])),
            'api_passphrase_enc' => $passphrase !== ''
                ? Crypt::encryptString($passphrase)
                : '',
            'enabled' => (bool) ($v['enabled'] ?? true),
            'last_sync_status' => 'idle',
            'last_error' => null,
            'last_sync_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'id' => (string) $account->id,
        ]);
    }

    public function updateExchangeAccount(Request $request, $id)
    {
        $v = $request->validate([
            'label' => 'nullable|string|max:80',
            'api_key' => 'nullable|string|max:255',
            'api_secret' => 'nullable|string|max:255',
            'passphrase' => 'nullable|string|max:255',
            'api_passphrase' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
        ]);

        $account = ExchangeAccount::find($id);
        if (!$account) {
            return response()->json(['status' => 'error', 'message' => '账号不存在'], 404);
        }

        if ($request->has('label')) {
            $account->label = trim((string) ($v['label'] ?? ''));
        }
        if ($request->has('enabled')) {
            $account->enabled = (bool) ($v['enabled'] ?? false);
        }
        if ($request->has('api_key') && trim((string) ($v['api_key'] ?? '')) !== '') {
            $account->api_key_enc = Crypt::encryptString(trim((string) $v['api_key']));
        }
        if ($request->has('api_secret') && trim((string) ($v['api_secret'] ?? '')) !== '') {
            $account->api_secret_enc = Crypt::encryptString(trim((string) $v['api_secret']));
        }
        if ($request->has('passphrase') || $request->has('api_passphrase')) {
            $passphrase = trim((string) ($v['api_passphrase'] ?? $v['passphrase'] ?? ''));
            $account->api_passphrase_enc = $passphrase !== '' ? Crypt::encryptString($passphrase) : '';
        }

        $account->save();

        return response()->json(['status' => 'success']);
    }

    public function deleteExchangeAccount($id)
    {
        $account = ExchangeAccount::find($id);
        if (!$account) {
            return response()->json(['status' => 'error', 'message' => '账号不存在'], 404);
        }

        $accountId = (string) $account->id;
        $exchange = strtolower((string) ($account->exchange ?? ''));
        $label = trim((string) ($account->label ?? ''));

        // Historical data may store account_id in different BSON/string shapes.
        // We perform a tolerant in-memory match so deletion is reliable.
        $matchedIds = CexSyncedAsset::query()
            ->where('exchange', $exchange)
            ->get()
            ->filter(function ($asset) use ($accountId, $exchange, $label) {
                $assetAccountId = $this->normalizeComparableId($asset->account_id ?? null);
                $assetExchange = strtolower(trim((string) ($asset->exchange ?? $asset->source_type ?? '')));
                $assetLabel = trim((string) ($asset->account_label ?? ''));

                if ($assetAccountId !== '' && $assetAccountId === $accountId) {
                    return true;
                }

                if ($assetExchange !== '' && $assetExchange === $exchange && $label !== '' && $assetLabel === $label) {
                    return true;
                }

                return false;
            })
            ->map(function ($asset) {
                return (string) $asset->id;
            })
            ->filter()
            ->values();

        if ($matchedIds->isNotEmpty()) {
            CexSyncedAsset::query()->whereIn('_id', $matchedIds->all())->delete();
        }

        $account->delete();

        return response()->json(['status' => 'success']);
    }

    public function deleteCexAsset($id)
    {
        $asset = CexSyncedAsset::find($id);
        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => '资产不存在'], 404);
        }

        $asset->delete();

        return response()->json(['status' => 'success']);
    }

    public function syncCexAssets(Request $request)
    {
        $v = $request->validate([
            'account_id' => 'nullable|string',
            'exchange' => 'nullable|in:okx,bitget',
        ]);

        $exchange = trim((string) ($v['exchange'] ?? ''));
        $accountId = trim((string) ($v['account_id'] ?? ''));
        $service = app(CexSyncService::class);

        if ($accountId !== '') {
            $account = ExchangeAccount::find($accountId);
            if (!$account) {
                return response()->json(['status' => 'error', 'message' => '账号不存在'], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $service->syncSingleAccount($account, 'manual-api'),
            ]);
        }

        if ($exchange !== '') {
            $accounts = ExchangeAccount::query()
                ->where('enabled', true)
                ->where('exchange', $exchange)
                ->get();

            $summary = [
                'trigger' => 'manual-api',
                'accounts_total' => $accounts->count(),
                'accounts_success' => 0,
                'accounts_failed' => 0,
                'assets_upserted' => 0,
                'errors' => [],
            ];

            foreach ($accounts as $account) {
                $result = $service->syncSingleAccount($account, 'manual-api');
                if (($result['status'] ?? '') === 'success') {
                    $summary['accounts_success']++;
                    $summary['assets_upserted'] += (int) ($result['assets_upserted'] ?? 0);
                } else {
                    $summary['accounts_failed']++;
                    $summary['errors'][] = [
                        'account_id' => (string) $account->id,
                        'message' => (string) ($result['message'] ?? 'sync_failed'),
                    ];
                }
            }

            return response()->json(['status' => 'success', 'data' => $summary]);
        }

        return response()->json([
            'status' => 'success',
            'data' => $service->syncEnabledAccounts('manual-api'),
        ]);
    }

    public function getCexAssets()
    {
        $assets = CexSyncedAsset::query()
            ->get()
            ->when($this->shouldHideLowValueAssets(), function ($collection) {
                return $collection->filter(function ($asset) {
                    return (float) ($asset->value_usd ?? 0) >= self::LOW_VALUE_ASSET_FILTER_THRESHOLD_USD;
                })->values();
            })
            ->sortByDesc(function ($asset) {
                return (float) ($asset->value_usd ?? 0);
            })
            ->values()
            ->map(function ($asset) {
                return [
                    'id' => (string) $asset->id,
                    'exchange' => (string) ($asset->exchange ?? ''),
                    'account_id' => (string) ($asset->account_id ?? ''),
                    'account_label' => (string) ($asset->account_label ?? ''),
                    'source_name' => (string) ($asset->source_name ?? ''),
                    'symbol' => strtoupper((string) ($asset->symbol ?? '')),
                    'token_name' => (string) ($asset->token_name ?? ''),
                    'coingecko_id' => (string) ($asset->coingecko_id ?? ''),
                    'token_amount' => (float) ($asset->token_amount ?? 0),
                    'value_usd' => (float) ($asset->value_usd ?? 0),
                    'is_active' => (bool) ($asset->is_active ?? false),
                    'last_synced_at' => $asset->last_synced_at ? Carbon::parse($asset->last_synced_at)->toDateTimeString() : null,
                ];
            })
            ->values();

        return response()->json($assets);
    }

    private function maskApiKey(string $apiKeyEncrypted, bool $isEncrypted = false): string
    {
        if (trim($apiKeyEncrypted) === '') {
            return '';
        }

        $plain = $apiKeyEncrypted;
        if ($isEncrypted) {
            try {
                $plain = Crypt::decryptString($apiKeyEncrypted);
            } catch (\Throwable $e) {
                $plain = '';
            }
        }

        $plain = trim((string) $plain);
        if ($plain === '') {
            return '';
        }

        if (mb_strlen($plain) <= 8) {
            return str_repeat('*', mb_strlen($plain));
        }

        return mb_substr($plain, 0, 4) . str_repeat('*', mb_strlen($plain) - 8) . mb_substr($plain, -4);
    }

    private function normalizeComparableId($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (is_object($value)) {
            if (isset($value->{'$oid'})) {
                return trim((string) $value->{'$oid'});
            }

            if (method_exists($value, '__toString')) {
                return trim((string) $value);
            }
        }

        return trim((string) json_encode($value));
    }

    // =========================================================================
    // 3. 资产管理 (Asset Management)
    // =========================================================================

    public function storeAsset(Request $request)
    {
        $v = $request->validate([
            'source_name' => 'required',
            'network' => 'nullable|required_without:chain|string',
            'chain' => 'nullable|string',
            'token_name' => 'required',
            'coingecko_id' => 'required',
            'token_amount' => 'required|numeric',
            'label' => 'nullable|string',
        ]);

        $network = trim((string) ($v['network'] ?? $v['chain'] ?? ''));
        if ($network === '') {
            return response()->json(['status' => 'error', 'message' => 'network/chain 不能为空'], 422);
        }

        $v['network'] = strtoupper($network);
        unset($v['chain']);

        Asset::create(array_merge($v, [
            'source_type' => 'manual',
            'value_usd' => 0,
        ]));
        Artisan::call('app:sync-crypto-data');
        return response()->json(['status' => 'success']);
    }

    public function updateAsset(Request $request, $id)
    {
        $v = $request->validate([
            'token_amount' => 'required|numeric',
            'network' => 'nullable|required_without:chain|string',
            'chain' => 'nullable|string',
            'source_name' => 'required',
            'label' => 'nullable|string',
        ]);

        $network = trim((string) ($v['network'] ?? $v['chain'] ?? ''));
        if ($network === '') {
            return response()->json(['status' => 'error', 'message' => 'network/chain 不能为空'], 422);
        }

        $v['network'] = strtoupper($network);
        unset($v['chain']);

        $asset = Asset::find($id);
        if ($asset) {
            if (strtolower((string) ($asset->source_type ?? 'manual')) !== 'manual') {
                return response()->json(['status' => 'error', 'message' => '自动同步资产不可手动编辑'], 422);
            }
            $asset->update($v);
        }
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

    // =========================================================================
    // 5. 钱包与代币管理 (Wallets & Tracked Tokens)
    // =========================================================================

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
    // 6. 平衡提醒 (Balance Alert)
    // =========================================================================

    public function getBalanceAlertSnapshot(Request $request)
    {
        $v = $request->validate([
            'prepare_threshold' => 'nullable|numeric|min:0|max:100',
            'rebalance_threshold' => 'nullable|numeric|min:0|max:100',
            'force_threshold' => 'nullable|numeric|min:0|max:100',
            'allocations' => 'nullable|array',
            'allocations.*.id' => 'nullable|string|max:80',
            'allocations.*.name' => 'nullable|string|max:60',
            'allocations.*.target_pct' => 'nullable|numeric|min:0|max:100',
            'allocations.*.symbols' => 'nullable|array',
            'allocations.*.symbols.*' => 'string|max:30',
            'target_allocations' => 'nullable|array',
            'target_allocations.*.symbol' => 'required|string|max:30',
            'target_allocations.*.target_pct' => 'required|numeric|min:0|max:100',
            'category_allocations' => 'nullable|array',
            'category_allocations.*.name' => 'required|string|max:60',
            'category_allocations.*.target_pct' => 'nullable|numeric|min:0|max:100',
            'category_allocations.*.symbols' => 'nullable|array',
            'category_allocations.*.symbols.*' => 'string|max:30',
        ]);

        return response()->json($this->balanceAlertService->getSnapshot($v));
    }

    /**
     * Generate a PNG image of the balance alert table via Python + Pillow
     * and send it to the provided Discord webhook as an attachment (async via queue).
     */
    public function sendBalanceAlertImage(Request $request)
    {
        $v = $request->validate([
            'webhook_url' => 'required|url',
            'prepare_threshold' => 'nullable|numeric|min:0|max:100',
            'rebalance_threshold' => 'nullable|numeric|min:0|max:100',
            'force_threshold' => 'nullable|numeric|min:0|max:100',
        ]);

        $snapshot = $this->balanceAlertService->getSnapshot($v);

        // Dispatch to queue for async processing
        GenerateBalanceAlertImage::dispatch($snapshot, $v['webhook_url'])
            ->onQueue('alerts');

        return response()->json(['status' => 'success', 'message' => '图片生成任务已排队，稍后发送']);
    }

    // =========================================================================
    // 7. 系统维护 (System Maintenance)
    // =========================================================================

    // =========================================================================
    // 7. 系统维护 (System Maintenance)
    // =========================================================================
    public function clearSnapshots()
    {
        DB::table('asset_snapshots')->delete();
        return response()->json(['status' => 'success']);
    }

    public function clearAssets()
    {
        Asset::truncate();
        CexSyncedAsset::truncate();
        return response()->json(['status' => 'success']);
    }

    public function wipeEverything()
    {
        DB::table('asset_snapshots')->delete();
        Asset::truncate();
        CexSyncedAsset::truncate();
        ExchangeAccount::truncate();
        DB::table('wallets')->delete();
        DB::table('tracked_tokens')->delete();
        CapitalFlow::truncate();
        return response()->json(['status' => 'success']);
    }
}