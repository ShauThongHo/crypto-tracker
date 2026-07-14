<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
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

    /**
     * 获取资产快照历史
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
            // 限制 ALL 范围: 最近 12 个月 + 每日抽样 (防止大量数据导致超时)
            $snapshots = $query->where('snapshot_time', '>=', $now->copy()->subMonths(12)->startOfDay())
                ->get()
                ->groupBy(function ($snap) {
                    return Carbon::parse($snap->snapshot_time)->format('Y-m-d');
                })
                ->map(function ($daySnaps) {
                    return $daySnaps->last(); // 每日取最后一条
                })
                ->values();
        } else {
            $snapshots = $query->where('snapshot_time', '>=', $now->copy()->subDay())->get();
            $range = '1D';
        }

        $flows = CapitalFlow::orderBy('transaction_date', 'asc')->get();

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