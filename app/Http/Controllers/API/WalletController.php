<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class WalletController extends Controller
{
    public function index()
    {
        return response()->json(DB::table('wallets')->get());
    }

    public function store(Request $request)
    {
        DB::table('wallets')->insert(array_merge(
            $request->validate(['name' => 'required', 'type' => 'required']),
            ['created_at' => now()]
        ));
        return response()->json(['status' => 'success']);
    }

    public function destroy($id)
    {
        DB::table('wallets')->where('_id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    public function trackedTokens()
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

            if (!empty($symbol)) {
                DB::table('tracked_tokens')->updateOrInsert(
                    ['coingecko_id' => $id],
                    ['name' => $name, 'symbol' => $symbol, 'updated_at' => now()]
                );
                return response()->json(['status' => 'success', 'source' => 'client']);
            }

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
        DB::table('tracked_tokens')
            ->where('_id', $id)
            ->orWhere('coingecko_id', $id)
            ->delete();

        return response()->json(['status' => 'success']);
    }
}
