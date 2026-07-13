<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\BalanceAlertService;
use App\Jobs\GenerateBalanceAlertImage;

class BalanceAlertController extends Controller
{
    public function __construct(
        private readonly BalanceAlertService $balanceAlertService
    ) {}

    public function settings()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'webhook_url' => $this->getStoredBalanceAlertWebhookUrl(),
                'hide_low_value_assets' => $this->getStoredHideLowValueAssetsEnabled(),
                'hide_low_value_assets_threshold_usd' => 0.01,
            ],
        ]);
    }

    public function update(Request $request)
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
                'hide_low_value_assets_threshold_usd' => 0.01,
            ],
        ]);
    }

    public function snapshot(Request $request)
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

    public function send(Request $request)
    {
        $v = $request->validate([
            'webhook_url' => 'required|url',
            'prepare_threshold' => 'nullable|numeric|min:0|max:100',
            'rebalance_threshold' => 'nullable|numeric|min:0|max:100',
            'force_threshold' => 'nullable|numeric|min:0|max:100',
        ]);

        $snapshot = $this->balanceAlertService->getSnapshot($v);

        GenerateBalanceAlertImage::dispatch($snapshot, $v['webhook_url'])
            ->onQueue('alerts');

        return response()->json(['status' => 'success', 'message' => '图片生成任务已排队，稍后发送']);
    }

    private function getStoredBalanceAlertWebhookUrl(): string
    {
        $row = \Illuminate\Support\Facades\DB::table('app_settings')->where('key', 'balance_alert_webhook_url')->first();

        if (!$row) {
            return '';
        }

        return trim((string) ($row->value ?? ''));
    }

    private function storeBalanceAlertWebhookUrl(string $webhookUrl): void
    {
        \Illuminate\Support\Facades\DB::table('app_settings')->updateOrInsert(
            ['key' => 'balance_alert_webhook_url'],
            [
                'value' => $webhookUrl,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function getStoredHideLowValueAssetsEnabled(): bool
    {
        $row = \Illuminate\Support\Facades\DB::table('app_settings')->where('key', 'hide_low_value_assets_enabled')->first();
        if (!$row) {
            return false;
        }

        $raw = strtolower(trim((string) ($row->value ?? '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function storeHideLowValueAssetsEnabled(bool $enabled): void
    {
        \Illuminate\Support\Facades\DB::table('app_settings')->updateOrInsert(
            ['key' => 'hide_low_value_assets_enabled'],
            [
                'value' => $enabled ? '1' : '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}