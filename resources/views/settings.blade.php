@extends('layouts.app')

@section('content')
    <section class="content-view p-4 md:p-8 lg:p-12">
        <header class="mb-10">
            <h2 class="text-slate-500 text-sm font-medium tracking-widest uppercase mb-2">Preferences</h2>
            <div class="text-3xl font-light text-white">系统设置</div>
        </header>

        <div class="max-w-4xl space-y-12">
            <div class="bg-[#0f172a] border border-slate-800 p-6 rounded-2xl flex justify-between items-center">
                <div>
                    <h4 class="text-white font-medium">USD / MYR 切换</h4>
                    <p id="rate-hint" class="text-sm text-slate-500 mt-1">全局切换法币计价单位 (当前实时汇率: 加载中...)</p>
                </div>
                <div id="currency-toggle" class="w-12 h-6 bg-slate-700 rounded-full relative cursor-pointer transition-all">
                    <div id="currency-toggle-knob"
                        class="w-5 h-5 bg-white rounded-full absolute top-0.5 left-0.5 transition-all"></div>
                </div>
            </div>

            <div>
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-white">📡 代币同步管理</h3>
                    <div class="flex gap-3">
                        <button id="manual-sync-btn" onclick="triggerManualSync()"
                            class="text-xs bg-emerald-500/10 hover:bg-emerald-500 text-emerald-500 hover:text-white border border-emerald-500/20 px-4 py-2 rounded-lg transition-all flex items-center gap-2 group">
                            <span id="sync-text">立即同步价格</span>
                        </button>
                        <button onclick="document.getElementById('addTokenRow').classList.toggle('hidden')"
                            class="text-xs bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 px-4 py-2 rounded-lg">+
                            搜索并添加追踪</button>
                    </div>
                </div>
                <div class="bg-[#0f172a] border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-slate-800/50 text-slate-500 text-[10px] uppercase font-bold tracking-wider">
                            <tr>
                                <th class="px-6 py-4">代币名称</th>
                                <th class="px-6 py-4">CoinGecko ID</th>
                                <th class="px-6 py-4 text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody id="tracked-tokens-list" class="divide-y divide-slate-800"></tbody>
                    </table>
                    <div id="addTokenRow" class="hidden p-6 bg-slate-800/30 border-t border-slate-700">
                        <div class="flex gap-4">
                            <input type="text" id="search_tracked_input" oninput="searchCoinGeckoTracked(this.value)"
                                placeholder="输入代币名称"
                                class="flex-1 bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-white">
                            <input type="text" id="newTokenId" placeholder="ID" readonly
                                class="w-32 bg-slate-900/50 border border-slate-700 rounded-xl px-4 py-2 text-slate-500">
                            <button onclick="submitTrackedToken()"
                                class="bg-sky-500 text-white px-6 py-2 rounded-xl">确认添加</button>
                        </div>
                        <div id="tracked_search_results"
                            class="mt-2 bg-slate-900 border border-slate-700 rounded-xl hidden max-h-40 overflow-y-auto">
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-white">👛 钱包来源管理</h3>
                    <button onclick="document.getElementById('addWalletRow').classList.toggle('hidden')"
                        class="text-xs bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 px-4 py-2 rounded-lg">+
                        新增钱包</button>
                </div>
                <div class="bg-[#0f172a] border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                    <table class="w-full text-left">
                        <thead class="bg-slate-800/50 text-slate-500 text-[10px] uppercase font-bold tracking-wider">
                            <tr>
                                <th class="px-6 py-4">钱包名称</th>
                                <th class="px-6 py-4">类型</th>
                                <th class="px-6 py-4 text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody id="wallets-list" class="divide-y divide-slate-800"></tbody>
                    </table>
                    <div id="addWalletRow" class="hidden p-6 bg-slate-800/30 border-t border-slate-700">
                        <div class="flex gap-4">
                            <input type="text" id="newWalletName" placeholder="钱包名称"
                                class="flex-1 bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-white">
                            <select id="newWalletType"
                                class="bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-white">
                                <option value="Hot Wallet">热钱包</option>
                                <option value="Cold Wallet">冷钱包</option>
                                <option value="Exchange">交易所</option>
                            </select>
                            <button onclick="submitWallet()" class="bg-sky-500 text-white px-6 py-2 rounded-xl">保存</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-20 border-t border-red-900/30 pt-10">
                <h3 class="text-red-500 text-xl font-bold mb-6 flex items-center gap-2">危险区域 (Danger Zone)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-red-500/5 border border-red-500/20 p-6 rounded-2xl flex justify-between items-center">
                        <div>
                            <h4 class="text-white font-medium">清空历史快照</h4>
                            <p class="text-xs text-slate-500 mt-1">仅删除走势，保留资产</p>
                        </div>
                        <button onclick="dangerAction('snapshots')"
                            class="px-4 py-2 bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-white border border-red-500/30 rounded-xl text-xs font-bold transition-all">确认清空</button>
                    </div>
                    <div class="bg-red-500/5 border border-red-500/20 p-6 rounded-2xl flex justify-between items-center">
                        <div>
                            <h4 class="text-white font-medium">移除所有资产</h4>
                            <p class="text-xs text-slate-500 mt-1">清空看板上的代币持仓</p>
                        </div>
                        <button onclick="dangerAction('assets')"
                            class="px-4 py-2 bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-white border border-red-500/30 rounded-xl text-xs font-bold transition-all">全部移除</button>
                    </div>
                    <div class="bg-red-500/5 border border-red-500/20 p-6 rounded-2xl flex justify-between items-center">
                        <div>
                            <h4 class="text-white font-medium">清空流水账本</h4>
                            <p class="text-xs text-slate-500 mt-1">仅删除 P2P 记录，不影响/回滚资产余额</p>
                        </div>
                        <button onclick="dangerAction('capital')"
                            class="px-4 py-2 bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-white border border-red-500/30 rounded-xl text-xs font-bold transition-all">确认清空</button>
                    </div>
                    <div
                        class="md:col-span-2 bg-red-600/10 border border-red-600/30 p-8 rounded-2xl flex justify-between items-center mt-4">
                        <div>
                            <h4 class="text-red-500 text-lg font-bold">出厂重置 (Wipe Everything)</h4>
                            <p class="text-sm text-slate-500 mt-1">删除一切数据，不可逆！</p>
                        </div>
                        <button onclick="dangerAction('wipe')"
                            class="px-8 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl font-black transition-all">全
                            部 毁 灭</button>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection