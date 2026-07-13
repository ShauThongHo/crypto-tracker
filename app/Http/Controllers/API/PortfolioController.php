<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Models\{Asset, CexSyncedAsset, CapitalFlow};

class PortfolioController extends Controller
{
    private const LOW_VALUE_ASSET_FILTER_THRESHOLD_USD = 0.01;

    public function thinkingMap()
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

    public function stats()
    {
        $deposits = CapitalFlow::where('type', 'DEPOSIT')->get();
        $withdrawals = CapitalFlow::where('type', 'WITHDRAWAL')->get();

        $totalDeposit = $deposits->sum(function($item) {
            return (float) ($item->fiat_amount ?? 0);
        });

        $totalWithdraw = $withdrawals->sum(function($item) {
            return (float) ($item->fiat_amount ?? 0);
        });

        return response()->json([
            'total_deposited' => (float) $totalDeposit,
            'total_withdrawn' => (float) $totalWithdraw,
            'net_invested' => (float) ($totalDeposit - $totalWithdraw)
        ]);
    }

    private function shouldHideLowValueAssets(): bool
    {
        $row = DB::table('app_settings')->where('key', 'hide_low_value_assets_enabled')->first();
        if (!$row) {
            return false;
        }

        $raw = strtolower(trim((string) ($row->value ?? '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}