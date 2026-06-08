<?php

namespace App\Infrastructure\Exchange\Adapters;

use App\Infrastructure\Exchange\Contracts\ExchangeAdapterInterface;
use App\Models\ExchangeAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitgetExchangeAdapter implements ExchangeAdapterInterface
{
    public function fetchBalances(ExchangeAccount $account): array
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

        return collect(array_merge($spotBalances, $earnBalances))
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

    private function decryptOptional(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        return Crypt::decryptString($value);
    }
}