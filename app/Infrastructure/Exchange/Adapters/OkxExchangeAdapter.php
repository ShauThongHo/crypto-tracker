<?php

namespace App\Infrastructure\Exchange\Adapters;

use App\Infrastructure\Exchange\Contracts\ExchangeAdapterInterface;
use App\Models\ExchangeAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OkxExchangeAdapter implements ExchangeAdapterInterface
{
    public function fetchBalances(ExchangeAccount $account): array
    {
        $allBalances = [];

        $tradingRows = $this->okxRequest($account, '/api/v5/account/balance');
        $allBalances = array_merge($allBalances, $this->normalizeOkxBalances($tradingRows));

        $fundingRows = $this->okxRequest($account, '/api/v5/asset/balances');
        $allBalances = array_merge($allBalances, $this->normalizeOkxBalances($fundingRows));

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

    private function decryptOptional(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        return Crypt::decryptString($value);
    }
}