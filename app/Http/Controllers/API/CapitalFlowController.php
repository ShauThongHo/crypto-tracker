<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CapitalFlow;
use Illuminate\Support\Facades\DB;

class CapitalFlowController extends Controller
{
    public function history()
    {
        $history = CapitalFlow::orderBy('transaction_date', 'desc')
            ->get()
            ->map(function ($item) {
                $item->id = (string) $item->_id;
                return $item;
            });

        return response()->json($history);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'asset_id' => 'required',
            'type' => 'required|in:DEPOSIT,WITHDRAWAL',
            'fiat_amount' => 'required|numeric',
            'usdt_rate' => 'required|numeric',
            'fiat_currency' => 'required|string',
            'transaction_date' => 'required|date'
        ]);

        $usdtAmount = $v['fiat_amount'] / $v['usdt_rate'];
        $flow = CapitalFlow::create(array_merge($v, ['usdt_amount' => $usdtAmount]));

        $asset = \App\Models\Asset::find($v['asset_id']);
        if ($asset) {
            $v['type'] === 'DEPOSIT' ? $asset->token_amount += $usdtAmount : $asset->token_amount -= $usdtAmount;
            $asset->save();
        }

        return response()->json(['status' => 'success', 'new_balance' => $asset ? $asset->token_amount : null]);
    }

    public function clear()
    {
        CapitalFlow::truncate();
        return response()->json(['status' => 'success']);
    }

    public function destroy($id)
    {
        CapitalFlow::destroy($id);
        return response()->json(['status' => 'success', 'message' => '记录已移除']);
    }
}