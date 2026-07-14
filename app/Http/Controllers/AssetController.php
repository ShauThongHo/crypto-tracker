<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Artisan, Http, Cache};
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\{CapitalFlow, Asset, CexSyncedAsset, ExchangeAccount};
use App\Services\{CexSyncService, BalanceAlertService};

class AssetController extends Controller
{
    public function __construct(
        private readonly BalanceAlertService $balanceAlertService
    ) {}

    // =========================================================================
    // 自动健康检查 (UptimeRobot ping target)
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

    // =========================================================================
    // 手动资产管理 (Asset CRUD)
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
    // 系统维护 (Danger Zone)
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

    // =========================================================================
    // Health Check Auto-Notify (private helpers)
    // =========================================================================

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

    private function getStoredBalanceAlertWebhookUrl(): string
    {
        $row = DB::table('app_settings')->where('key', 'balance_alert_webhook_url')->first();

        if (!$row) {
            return '';
        }

        return trim((string) ($row->value ?? ''));
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

    private function resolvePythonBinary(): string
    {
        $bin = 'python3';
        if (PHP_OS_FAMILY === 'Windows') {
            $bin = 'python';
        }

        $output = '';
        $returnCode = 0;
        exec($bin . ' --version 2>&1', $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return $bin;
        }

        $alternatives = PHP_OS_FAMILY === 'Windows'
            ? ['py -3', 'python3', 'python']
            : ['python3', 'python'];

        foreach ($alternatives as $alt) {
            $out = [];
            $code = 0;
            exec($alt . ' --version 2>&1', $out, $code);
            if ($code === 0 && !empty($out)) {
                return $alt;
            }
        }

        return $bin;
    }
}
