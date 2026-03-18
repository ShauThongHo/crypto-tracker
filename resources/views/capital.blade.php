@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-4 md:p-6 space-y-6">
    <div id="stats-container" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <section class="lg:col-span-4 bg-slate-900 border border-slate-800 p-6 rounded-3xl h-fit sticky top-24">
            <h2 class="text-lg font-bold text-white mb-6 flex items-center gap-2">
                <i class="fa-solid fa-plus-circle text-sky-500"></i> 新增 P2P 流水
            </h2>
            <form id="capitalForm" class="space-y-4">
                <select id="cap_asset_id" required class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-sm text-white focus:border-sky-500 outline-none transition-all">
                    <option value="">正在加载目标资产...</option>
                </select>

                <div class="flex bg-slate-800 p-1 rounded-xl">
                    <button type="button" data-type="DEPOSIT" class="cap-type-btn flex-1 py-2 rounded-lg text-xs font-bold transition-all bg-emerald-500 text-white">入金 (In)</button>
                    <button type="button" data-type="WITHDRAWAL" class="cap-type-btn flex-1 py-2 rounded-lg text-xs font-bold transition-all text-slate-400">出金 (Out)</button>
                    <input type="hidden" id="cap_type" value="DEPOSIT">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <input type="number" id="fiat_amount" placeholder="法币金额" step="0.01" required class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white">
                    <select id="fiat_currency" disabled class="bg-slate-800 border border-slate-700 rounded-xl p-3 text-white cursor-not-allowed" title="币种固定为 MYR">
                        <option value="MYR">MYR</option>
                    </select>
                </div>

                <input type="number" id="usdt_rate" placeholder="P2P 汇率" step="0.001" required class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white">
                
                <div class="p-4 bg-slate-950/50 border border-slate-800 rounded-2xl">
                    <div class="text-[10px] text-slate-500 uppercase font-bold mb-1">预计 USDT 变动</div>
                    <div id="preview_usdt" class="text-lg font-mono text-sky-400">0.0000</div>
                </div>

                <button type="submit" class="w-full py-4 bg-sky-600 hover:bg-sky-500 rounded-xl font-bold text-white shadow-lg shadow-sky-600/20 transition-all">
                    同步至账本
                </button>
            </form>
        </section>

        <section class="lg:col-span-8 bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden">
            <div class="p-6 border-b border-slate-800 flex justify-between items-center">
                <h2 class="text-lg font-bold text-white">流水审计日志</h2>
                <button id="refreshBtn" class="p-2 text-slate-500 hover:text-white"><i class="fa-solid fa-rotate"></i></button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-800/50 text-[10px] uppercase text-slate-500 tracking-widest">
                        <tr>
                            <th class="px-6 py-4">日期</th>
                            <th class="px-6 py-4">方向</th>
                            <th class="px-6 py-4 text-right">法币数额</th>
                            <th class="px-6 py-4 text-right text-sky-400">USDT 变动</th>
                        </tr>
                    </thead>
                    <tbody id="history-body" class="divide-y divide-slate-800/50">
                        </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script type="module" src="{{ asset('js/pages/capital-page.js') }}"></script>
@endsection