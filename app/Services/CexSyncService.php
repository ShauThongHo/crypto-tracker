<?php

namespace App\Services;

use App\Infrastructure\Exchange\ExchangeAdapterFactory;
use App\Models\CexSyncedAsset;
use App\Models\ExchangeAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CexSyncService
{
    public function __construct(private readonly ExchangeAdapterFactory $exchangeAdapterFactory = new ExchangeAdapterFactory())
    {
    }

    public function getExchangeAccounts(): array
    {
        return ExchangeAccount::query()
            ->get()
            ->map(function (ExchangeAccount $account) {
                return [
                    'id' => (string) $account->id,
                    'exchange' => (string) $account->exchange,
                    'label' => (string) ($account->label ?? ''),
                    'enabled' => (bool) $account->enabled,
                    'api_key_masked' => $this->maskSecret((string) ($account->api_key_enc ?? '')),
                    'last_sync_at' => optional($account->last_sync_at)?->toDateTimeString(),
                    'last_sync_status' => $account->last_sync_status,
                    'last_error' => $account->last_error,
                ];
            })
            ->values()
            ->all();
    }

    public function createExchangeAccount(array $data): array
    {
        $account = new ExchangeAccount();
        $account->exchange = strtolower((string) ($data['exchange'] ?? ''));
        $account->label = (string) ($data['label'] ?? '');
        $account->api_key_enc = Crypt::encryptString((string) ($data['api_key'] ?? ''));
        $account->api_secret_enc = Crypt::encryptString((string) ($data['api_secret'] ?? ''));
        $account->api_passphrase_enc = isset($data['api_passphrase']) && $data['api_passphrase'] !== ''
            ? Crypt::encryptString((string) $data['api_passphrase'])
            : null;
        $account->enabled = (bool) ($data['enabled'] ?? true);
        $account->save();

        return [
            'id' => (string) $account->id,
            'exchange' => (string) $account->exchange,
            'label' => (string) ($account->label ?? ''),
            'enabled' => (bool) $account->enabled,
        ];
    }

    public function updateExchangeAccount(string $accountId, array $data): array
    {
        $account = ExchangeAccount::query()->findOrFail($accountId);

        if (array_key_exists('exchange', $data)) {
            $account->exchange = strtolower((string) $data['exchange']);
        }

        if (array_key_exists('label', $data)) {
            $account->label = (string) $data['label'];
        }

        if (!empty($data['api_key'])) {
            $account->api_key_enc = Crypt::encryptString((string) $data['api_key']);
        }

        if (!empty($data['api_secret'])) {
            $account->api_secret_enc = Crypt::encryptString((string) $data['api_secret']);
        }

        if (array_key_exists('api_passphrase', $data)) {
            $account->api_passphrase_enc = $data['api_passphrase'] !== ''
                ? Crypt::encryptString((string) $data['api_passphrase'])
                : null;
        }

        if (array_key_exists('enabled', $data)) {
            $account->enabled = (bool) $data['enabled'];
        }

        $account->save();

        return [
            'id' => (string) $account->id,
            'exchange' => (string) $account->exchange,
            'label' => (string) ($account->label ?? ''),
            'enabled' => (bool) $account->enabled,
        ];
    }

    public function deleteExchangeAccount(string $accountId): bool
    {
        return (bool) ExchangeAccount::query()->whereKey($accountId)->delete();
    }

    public function syncAccount(string $accountId, string $trigger = 'manual'): array
    {
        /** @var ExchangeAccount $account */
        $account = ExchangeAccount::query()->whereKey($accountId)->firstOrFail();

        return $this->syncSingleAccount($account, $trigger);
    }

    public function getCexSyncedAssets(): array
    {
        return CexSyncedAsset::query()
            ->get()
            ->map(static fn (CexSyncedAsset $asset) => $asset->toArray())
            ->values()
            ->all();
    }

    public function getSyncStatus(): array
    {
        $accounts = ExchangeAccount::query()->get();

        return [
            'accounts_total' => $accounts->count(),
            'accounts_enabled' => $accounts->where('enabled', true)->count(),
            'last_sync_at' => optional($accounts->sortByDesc('last_sync_at')->first())?->last_sync_at?->toDateTimeString(),
            'last_sync_status' => optional($accounts->sortByDesc('last_sync_at')->first())?->last_sync_status,
            'last_error' => optional($accounts->sortByDesc('last_sync_at')->first())?->last_error,
        ];
    }

    public function getExchangeRate(): float
    {
        return 1.0;
    }

    public function syncEnabledAccounts(string $trigger = 'manual'): array
    {
        $accounts = ExchangeAccount::query()
            ->where('enabled', true)
            ->get();

        $summary = [
            'trigger' => $trigger,
            'accounts_total' => $accounts->count(),
            'accounts_success' => 0,
            'accounts_failed' => 0,
            'assets_upserted' => 0,
            'errors' => [],
            'started_at' => now()->toDateTimeString(),
        ];

        foreach ($accounts as $account) {
            $result = $this->syncSingleAccount($account, $trigger);

            if (($result['status'] ?? '') === 'success') {
                $summary['accounts_success']++;
                $summary['assets_upserted'] += (int) ($result['assets_upserted'] ?? 0);
                continue;
            }

            $summary['accounts_failed']++;
            $summary['errors'][] = [
                'account_id' => (string) $account->id,
                'exchange' => (string) $account->exchange,
                'label' => (string) ($account->label ?? ''),
                'message' => (string) ($result['message'] ?? 'unknown_error'),
            ];
        }

        $summary['finished_at'] = now()->toDateTimeString();
        return $summary;
    }

    public function syncSingleAccount(ExchangeAccount $account, string $trigger = 'manual'): array
    {
        $exchange = strtolower(trim((string) $account->exchange));
        $accountId = (string) $account->id;
        $slot = Carbon::now()->format('YmdHi');
        $lockKey = "cex_sync_lock:{$accountId}";
        $slotKey = "cex_sync_slot:{$accountId}:{$slot}";

        if (Cache::has($slotKey)) {
            return [
                'status' => 'skipped',
                'message' => 'already_synced_in_current_slot',
                'assets_upserted' => 0,
            ];
        }

        if (!Cache::add($lockKey, true, 60)) {
            return [
                'status' => 'skipped',
                'message' => 'account_sync_in_progress',
                'assets_upserted' => 0,
            ];
        }

        try {
            $adapter = $this->exchangeAdapterFactory->make($exchange);
            $balances = $adapter->fetchBalances($account);

            $upserted = $this->upsertSyncedAssets($account, $balances, $slot, $trigger);

            $account->last_sync_at = now();
            $account->last_sync_status = 'success';
            $account->last_error = null;
            $account->save();

            Cache::put($slotKey, true, 120);

            return [
                'status' => 'success',
                'message' => 'synced',
                'assets_upserted' => $upserted,
            ];
        } catch (\Throwable $e) {
            Log::warning('CEX account sync failed', [
                'account_id' => $accountId,
                'exchange' => $exchange,
                'message' => $e->getMessage(),
            ]);

            $account->last_sync_at = now();
            $account->last_sync_status = 'error';
            $account->last_error = $e->getMessage();
            $account->save();

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'assets_upserted' => 0,
            ];
        } finally {
            Cache::forget($lockKey);
        }
    }

    private function fetchOkxBalances(ExchangeAccount $account): array
    {
        $passphrase = $this->decryptOptional((string) ($account->api_passphrase_enc ?? ''));

        if ($passphrase === '') {
            throw new \RuntimeException('okx_passphrase_missing');
        }

        $allBalances = [];

        // 1) 交易账户
        $tradingRows = $this->okxRequest($account, '/api/v5/account/balance');
        $allBalances = array_merge($allBalances, $this->normalizeOkxBalances($tradingRows));

        // 2) 资金账户
        $fundingRows = $this->okxRequest($account, '/api/v5/asset/balances');
        $allBalances = array_merge($allBalances, $this->normalizeOkxBalances($fundingRows));

        // 3) 金融账户（理财）
        $financialPaths = [
            '/api/v5/finance/savings/balance',
            '/api/v5/finance/savings/account',
        ];

        $financialBalances = [];
        foreach ($financialPaths as $path) {
            try {
                $financialRows = $this->okxRequest($account, $path);
                $financialBalances = $this->normalizeOkxBalances($financialRows);
                break;
            } catch (\Throwable $e) {
                Log::info('OKX financial endpoint unavailable, trying next', [
                    'account_id' => (string) $account->id,
                    'path' => $path,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (empty($financialBalances)) {
            Log::info('OKX financial balances not available; continuing with trading+funding', [
                'account_id' => (string) $account->id,
            ]);
        }

        $allBalances = array_merge($allBalances, $financialBalances);

        return collect($allBalances)
            ->groupBy('symbol')
            ->map(function ($items, $symbol) {
                $amount = collect($items)->sum(function ($row) {
                    return (float) ($row['amount'] ?? 0);
                });

                if ($amount <= 0) {
                    return null;
                }

                return [
                    'symbol' => strtoupper((string) $symbol),
                    'amount' => (float) $amount,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function okxRequest(ExchangeAccount $account, string $path, array $query = []): array
    {
        $apiKey = Crypt::decryptString((string) $account->api_key_enc);
        $apiSecret = Crypt::decryptString((string) $account->api_secret_enc);
        $passphrase = $this->decryptOptional((string) ($account->api_passphrase_enc ?? ''));

        $baseUrl = rtrim((string) config('services.okx.base_url'), '/');
        $method = 'GET';
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');

        $queryString = http_build_query($query);
        $requestPath = $queryString !== '' ? ($path . '?' . $queryString) : $path;
        $prehash = $timestamp . $method . $requestPath;
        $signature = base64_encode(hash_hmac('sha256', $prehash, $apiSecret, true));

        $response = Http::timeout(15)
            ->withHeaders([
                'OK-ACCESS-KEY' => $apiKey,
                'OK-ACCESS-SIGN' => $signature,
                'OK-ACCESS-TIMESTAMP' => $timestamp,
                'OK-ACCESS-PASSPHRASE' => $passphrase,
                'Content-Type' => 'application/json',
            ])
            ->get($baseUrl . $path, $query);

        if (!$response->successful()) {
            throw new \RuntimeException('okx_http_' . $response->status());
        }

        $code = (string) $response->json('code', '');
        if ($code !== '' && $code !== '0') {
            throw new \RuntimeException('okx_api_' . $code . ':' . (string) $response->json('msg', 'unknown'));
        }

        $data = $response->json('data', []);
        if (!is_array($data)) {
            return [];
        }

        $rows = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['details']) && is_array($item['details'])) {
                foreach ($item['details'] as $detail) {
                    if (is_array($detail)) {
                        $rows[] = $detail;
                    }
                }
                continue;
            }

            $rows[] = $item;
        }

        return $rows;
    }

    private function normalizeOkxBalances(array $rows): array
    {
        return collect($rows)
            ->map(function ($row) {
                if (!is_array($row)) {
                    return null;
                }

                $symbol = strtoupper(trim((string) (
                    $row['ccy']
                    ?? $row['coin']
                    ?? $row['currency']
                    ?? ''
                )));

                $availPlusFrozen = (float) ($row['availBal'] ?? 0) + (float) ($row['frozenBal'] ?? 0);
                $candidates = [
                    (float) ($row['cashBal'] ?? 0),
                    (float) ($row['bal'] ?? 0),
                    (float) ($row['eq'] ?? 0),
                    (float) ($row['amount'] ?? 0),
                    (float) ($row['amt'] ?? 0),
                    (float) ($row['totalBal'] ?? 0),
                    $availPlusFrozen,
                ];

                // Trading bot positions may appear as frozen/eq while cashBal is zero.
                $amount = collect($candidates)->max();

                if ($symbol === '' || $amount <= 0) {
                    return null;
                }

                return [
                    'symbol' => $symbol,
                    'amount' => $amount,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function fetchBitgetBalances(ExchangeAccount $account): array
    {
        $spotRows = $this->bitgetRequest($account, '/api/v2/spot/account/assets');
        $spotBalances = $this->normalizeBitgetBalances($spotRows);

        $earnBalances = [];
        $earnPaths = [
            '/api/v2/earn/account/assets',
            '/api/v2/earn/account/asset',
            '/api/v2/earn/account/balance',
        ];

        foreach ($earnPaths as $earnPath) {
            try {
                $earnRows = $this->bitgetRequest($account, $earnPath);
                $earnBalances = $this->normalizeBitgetBalances($earnRows);
                break;
            } catch (\Throwable $e) {
                Log::info('Bitget earn endpoint unavailable, trying next', [
                    'account_id' => (string) $account->id,
                    'path' => $earnPath,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (empty($earnBalances)) {
            Log::info('Bitget earn balances not available; continuing with spot only', [
                'account_id' => (string) $account->id,
            ]);
        }

        $merged = collect(array_merge($spotBalances, $earnBalances))
            ->groupBy('symbol')
            ->map(function ($items, $symbol) {
                $amount = collect($items)->sum(function ($row) {
                    return (float) ($row['amount'] ?? 0);
                });

                if ($amount <= 0) {
                    return null;
                }

                return [
                    'symbol' => strtoupper((string) $symbol),
                    'amount' => (float) $amount,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return $merged;
    }

    private function bitgetRequest(ExchangeAccount $account, string $path, array $query = []): array
    {
        $apiKey = Crypt::decryptString((string) $account->api_key_enc);
        $apiSecret = Crypt::decryptString((string) $account->api_secret_enc);
        $passphrase = $this->decryptOptional((string) ($account->api_passphrase_enc ?? ''));

        $baseUrl = rtrim((string) config('services.bitget.base_url'), '/');
        $method = 'GET';
        $timestamp = (string) round(microtime(true) * 1000);

        $queryString = http_build_query($query);
        $requestPath = $queryString !== '' ? ($path . '?' . $queryString) : $path;
        $prehash = $timestamp . $method . $requestPath;
        $signature = base64_encode(hash_hmac('sha256', $prehash, $apiSecret, true));

        $response = Http::timeout(15)
            ->withHeaders([
                'ACCESS-KEY' => $apiKey,
                'ACCESS-SIGN' => $signature,
                'ACCESS-TIMESTAMP' => $timestamp,
                'ACCESS-PASSPHRASE' => $passphrase,
                'ACCESS-VERSION' => '2',
                'Content-Type' => 'application/json',
            ])
            ->get($baseUrl . $path, $query);

        if (!$response->successful()) {
            throw new \RuntimeException('bitget_http_' . $response->status());
        }

        $code = (string) $response->json('code', '');
        if ($code !== '' && $code !== '00000') {
            throw new \RuntimeException('bitget_api_' . $code . ':' . (string) $response->json('msg', 'unknown'));
        }

        $data = $response->json('data', []);
        if (is_array($data)) {
            return $data;
        }

        return [];
    }

    private function normalizeBitgetBalances(array $rows): array
    {
        return collect($rows)
            ->map(function ($row) {
                if (!is_array($row)) {
                    return null;
                }

                $symbol = strtoupper(trim((string) (
                    $row['coin']
                    ?? $row['asset']
                    ?? $row['currency']
                    ?? $row['ccy']
                    ?? $row['productCoin']
                    ?? ''
                )));

                $total = 0.0;
                if (isset($row['available']) || isset($row['frozen'])) {
                    $total = (float) ($row['available'] ?? 0) + (float) ($row['frozen'] ?? 0);
                } else {
                    $total = (float) (
                        $row['amount']
                        ?? $row['balance']
                        ?? $row['totalAmount']
                        ?? $row['assets']
                        ?? 0
                    );
                }

                if ($symbol === '' || $total <= 0) {
                    return null;
                }

                return [
                    'symbol' => $symbol,
                    'amount' => $total,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function upsertSyncedAssets(ExchangeAccount $account, array $balances, string $slot, string $trigger): int
    {
        $exchange = strtolower((string) $account->exchange);
        $accountId = (string) $account->id;
        $accountLabel = (string) ($account->label ?: strtoupper($exchange));

        $symbols = collect($balances)->pluck('symbol')->filter()->unique()->values();

        $tracked = collect();
        if ($symbols->isNotEmpty()) {
            $tracked = collect(\DB::table('tracked_tokens')->get())->keyBy(function ($row) {
                return strtoupper((string) data_get($row, 'symbol', ''));
            });
        }

        $priceMap = [];
        $ids = $tracked->pluck('coingecko_id')->filter()->unique()->implode(',');
        if ($ids !== '') {
            $proxyUrl = config('services.coingecko.proxy_url');
            $proxyKey = config('services.coingecko.proxy_key');
            $res = Http::withHeaders(['x-proxy-key' => $proxyKey])->timeout(20)->get($proxyUrl, [
                'ids' => $ids,
                'vs_currencies' => 'usd',
            ]);
            if ($res->successful()) {
                $priceMap = (array) $res->json();
            }
        }

        $now = now();
        $activeSymbols = [];
        $count = 0;

        foreach ($balances as $row) {
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));
            $amount = (float) ($row['amount'] ?? 0);
            if ($symbol === '' || $amount <= 0) {
                continue;
            }

            $trackedRow = $tracked->get($symbol);
            $coingeckoId = (string) data_get($trackedRow, 'coingecko_id', '');
            $tokenName = (string) data_get($trackedRow, 'name', $symbol);
            $priceUsd = $coingeckoId !== '' ? (float) data_get($priceMap, $coingeckoId . '.usd', 0) : 0;
            $valueUsd = $amount * $priceUsd;

            CexSyncedAsset::query()->updateOrCreate(
                [
                    'exchange' => $exchange,
                    'account_id' => $accountId,
                    'symbol' => $symbol,
                    'network' => 'CEX',
                ],
                [
                    'account_label' => $accountLabel,
                    'source_name' => strtoupper($exchange) . ':' . $accountLabel,
                    'source_type' => $exchange,
                    'token_name' => $tokenName,
                    'coingecko_id' => $coingeckoId,
                    'token_amount' => $amount,
                    'value_usd' => round($valueUsd, 8),
                    'label' => 'AUTO_SYNC',
                    'label_id' => $trigger,
                    'sync_slot' => $slot,
                    'last_synced_at' => $now,
                    'is_active' => true,
                    'raw_hash' => sha1($exchange . '|' . $accountId . '|' . $symbol . '|' . $amount),
                ]
            );

            $activeSymbols[] = $symbol;
            $count++;
        }

        CexSyncedAsset::query()
            ->where('exchange', $exchange)
            ->where('account_id', $accountId)
            ->whereNotIn('symbol', $activeSymbols)
            ->update([
                'token_amount' => 0,
                'value_usd' => 0,
                'is_active' => false,
                'sync_slot' => $slot,
                'last_synced_at' => $now,
                'label_id' => $trigger,
            ]);

        return $count;
    }

    private function decryptOptional(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        return Crypt::decryptString($value);
    }

    private function maskSecret(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        try {
            $plain = Crypt::decryptString($value);
        } catch (\Throwable) {
            $plain = $value;
        }

        $length = strlen($plain);

        if ($length <= 6) {
            return str_repeat('*', max(3, $length));
        }

        return substr($plain, 0, 2) . str_repeat('*', $length - 4) . substr($plain, -2);
    }
}
