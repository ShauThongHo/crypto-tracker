<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\ExchangeAccount;
use App\Models\CexSyncedAsset;
use App\Services\CexSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CEXSyncController extends Controller
{
    public function index()
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

    public function store(Request $request)
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

    public function update(Request $request, $id)
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

    public function destroy($id)
    {
        $account = ExchangeAccount::find($id);
        if (!$account) {
            return response()->json(['status' => 'error', 'message' => '账号不存在'], 404);
        }

        $accountId = (string) $account->id;
        $exchange = strtolower((string) ($account->exchange ?? ''));
        $label = trim((string) ($account->label ?? ''));

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

    public function sync(Request $request)
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

    public function syncStatus()
    {
        return response()->json([
            'status' => Cache::get('sync_status', 'idle'),
            'last_sync' => Cache::get('last_sync_at', '从未同步'),
        ]);
    }

    public function getExchangeRate()
    {
        try {
            $rate = Cache::remember('usd_myr_rate', 300, function () {
                $res = Http::timeout(10)->get("https://api.frankfurter.app/latest?from=USD&to=MYR");
                return $res->successful() ? (float) $res->json()['rates']['MYR'] : 4.72;
            });
        } catch (\Throwable $e) {
            Log::warning('Exchange rate cache/API failed, using default', ['error' => $e->getMessage()]);
            $rate = 4.72;
        }
        return response()->json(['rate' => $rate]);
    }

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

    public function getCexAssets()
    {
        $assets = CexSyncedAsset::query()
            ->get()
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

    public function deleteCexAsset($id)
    {
        $asset = CexSyncedAsset::find($id);
        if (!$asset) {
            return response()->json(['status' => 'error', 'message' => '资产不存在'], 404);
        }

        $asset->delete();
        return response()->json(['status' => 'success']);
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
}