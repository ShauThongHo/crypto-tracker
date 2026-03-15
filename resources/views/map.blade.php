<!DOCTYPE html>
<html lang="zh">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HST Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>
    <style>
        body {
            background-color: #020617;
            color: #f8fafc;
            font-family: 'Inter', system-ui, sans-serif;
            overflow: hidden;
        }

        .nav-active {
            background: rgba(56, 189, 248, 0.1);
            color: #38bdf8;
            border-right: 3px solid #38bdf8;
        }

        .nav-inactive {
            color: #94a3b8;
            border-right: 3px solid transparent;
        }

        .nav-inactive:hover {
            background: #0f172a;
            color: #e2e8f0;
        }

        .bento-grid {
            display: grid;
            /* 强制划分为两列 */
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            max-width: 1400px;
        }

        /* 铺满全行的特殊样式 */
        .full-row-card {
            grid-column: span 2;
            /* 给铺满的卡片一点特殊的渐变感，显得很大气 */
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        }

        /* 移动端适配：手机上依然全部垂直排列 */
        @media (max-width: 768px) {
            .bento-grid {
                grid-template-columns: 1fr;
            }

            .full-row-card {
                grid-column: span 1 !important;
            }
        }

        .bento-card {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 24px;
            padding: 1.5rem;
            min-height: 200px;
            transition: all 0.3s ease;
        }

        .bento-card:hover {
            border-color: #38bdf8;
            transform: translateY(-2px);
        }

        .hero-card {
            grid-column: span 2;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
    </style>
</head>

<body class="flex h-screen w-full">

    <aside class="w-64 bg-[#090e17] border-r border-slate-800/60 flex flex-col shrink-0">
        <div class="h-24 flex items-center px-8">
            <h1 class="text-2xl font-bold tracking-widest text-white">Crypto Tracker<span
                    class="text-sky-400 text-3xl">.</span></h1>
        </div>
        <nav class="flex-1 mt-4 space-y-2 px-3">
            <button data-target="view-portfolio"
                class="nav-btn nav-active w-full flex items-center gap-4 px-5 py-3.5 rounded-xl font-medium text-sm transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                    </path>
                </svg>
                资产总览
            </button>
            <button data-target="view-history"
                class="nav-btn nav-inactive w-full flex items-center gap-4 px-5 py-3.5 rounded-xl font-medium text-sm transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                盈亏历史
            </button>
            <button data-target="view-settings"
                class="nav-btn nav-inactive w-full flex items-center gap-4 px-5 py-3.5 rounded-xl font-medium text-sm transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                    </path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                系统设置
            </button>
        </nav>
    </aside>

    <main class="flex-1 h-full overflow-y-auto bg-[#020617] relative no-scrollbar">

        <section id="view-portfolio" class="content-view block p-8 lg:p-12">
            <header class="mb-8 flex items-end justify-between">
                <div>
                    <h2 class="text-slate-500 text-sm font-medium tracking-widest uppercase mb-2">My Portfolio</h2>
                    <div class="flex items-baseline gap-4">
                        <div id="total-value" class="text-5xl font-light text-white">$0.00</div>
                        <div
                            class="flex items-center gap-2 px-2 py-1 bg-green-500/10 rounded-full border border-green-500/20">
                            <span class="relative flex h-2 w-2">
                                <span
                                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                            </span>
                            <span class="text-[10px] text-green-500 font-bold uppercase tracking-tighter">Sync
                                Aligned</span>
                        </div>
                    </div>
                </div>
                <button onclick="openAddModal()"
                    class="bg-sky-500 hover:bg-sky-400 text-white text-sm font-bold py-2.5 px-6 rounded-xl shadow-lg transition-all flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd"
                            d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"
                            clip-rule="evenodd" />
                    </svg>
                    添加资产
                </button>
            </header>
            <div class="w-full h-64 md:h-80 bg-[#0f172a] border border-slate-800 rounded-3xl mb-8 p-4 relative">

                <div class="absolute top-6 right-8 z-20 flex gap-2">
                    <button id="btn-1D" onclick="changeRange('1D')"
                        class="range-btn bg-sky-500 text-white text-[10px] font-bold px-3 py-1 rounded-md shadow-lg shadow-sky-500/20 transition-all border border-sky-400/20">
                        1D
                    </button>
                    <button id="btn-7D" onclick="changeRange('7D')"
                        class="range-btn bg-slate-800/80 text-slate-400 text-[10px] font-bold px-3 py-1 rounded-md hover:bg-slate-700 transition-all border border-slate-700">
                        7D
                    </button>
                    <button id="btn-30D" onclick="changeRange('30D')"
                        class="range-btn bg-slate-800/80 text-slate-400 text-[10px] font-bold px-3 py-1 rounded-md hover:bg-slate-700 transition-all border border-slate-700">
                        30D
                    </button>
                </div>

                <div id="echarts-container" class="w-full h-full"></div>
            </div>
            <div class="bento-grid" id="grid-container"></div>
        </section>

        <section id="view-history" class="content-view hidden p-8 lg:p-12">
            <header class="mb-10">
                <h2 class="text-slate-500 text-sm font-medium tracking-widest uppercase mb-2">History</h2>
                <div class="text-3xl font-light text-white">盈亏历史</div>
            </header>
            <div
                class="w-full h-64 border border-dashed border-slate-700/50 rounded-2xl flex items-center justify-center text-slate-500">
                准备接入历史快照数据...
            </div>
        </section>

        <section id="view-settings" class="content-view hidden p-8 lg:p-12">
            <header class="mb-10">
                <h2 class="text-slate-500 text-sm font-medium tracking-widest uppercase mb-2">Preferences</h2>
                <div class="text-3xl font-light text-white">系统设置</div>
            </header>

            <div class="max-w-4xl space-y-12">
                <div
                    class="bg-[#0f172a] border border-slate-800 p-6 rounded-2xl flex justify-between items-center relative z-0">
                    <div>
                        <h4 class="text-white font-medium">USD / MYR 切换</h4>
                        <p id="rate-hint" class="text-sm text-slate-500 mt-1">全局切换法币计价单位 (当前实时汇率: 加载中...)</p>
                    </div>
                    <div id="currency-toggle"
                        class="w-12 h-6 bg-slate-700 rounded-full relative cursor-pointer transition-all">
                        <div id="currency-toggle-knob"
                            class="w-5 h-5 bg-white rounded-full absolute top-0.5 left-0.5 transition-all"></div>
                    </div>
                </div>

                <div class="relative z-20">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-white">📡 代币同步管理</h3>
                        <div class="flex gap-3">
                            <button id="manual-sync-btn" onclick="triggerManualSync()"
                                class="text-xs bg-emerald-500/10 hover:bg-emerald-500 text-emerald-500 hover:text-white border border-emerald-500/20 px-4 py-2 rounded-lg transition-all flex items-center gap-2 group">
                                <svg id="sync-icon"
                                    class="w-3.5 h-3.5 group-hover:rotate-180 transition-transform duration-500"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                    </path>
                                </svg>
                                <span id="sync-text">立即同步价格</span>
                            </button>

                            <button onclick="document.getElementById('addTokenRow').classList.toggle('hidden')"
                                class="text-xs bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 px-4 py-2 rounded-lg transition-all">
                                + 搜索并添加追踪
                            </button>
                        </div>
                    </div>
                    <div class="bg-[#0f172a] border border-slate-800 rounded-2xl shadow-xl relative">
                        <table class="w-full text-left rounded-t-2xl overflow-hidden">
                            <thead
                                class="bg-slate-800/50 text-slate-500 text-[10px] uppercase font-bold tracking-wider">
                                <tr>
                                    <th class="px-6 py-4">代币名称</th>
                                    <th class="px-6 py-4">CoinGecko ID</th>
                                    <th class="px-6 py-4 text-right">操作</th>
                                </tr>
                            </thead>
                            <tbody id="tracked-tokens-list" class="divide-y divide-slate-800"></tbody>
                        </table>
                        <div id="addTokenRow"
                            class="hidden p-6 bg-slate-800/30 border-t border-slate-700 rounded-b-2xl relative overflow-visible">
                            <div class="flex gap-4 relative">
                                <div class="flex-1 relative">
                                    <input type="text" id="search_tracked_input" placeholder="输入代币名称 (如: Bitcoin)"
                                        oninput="searchCoinGeckoTracked(this.value)" autocomplete="off"
                                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-sm text-white outline-none focus:border-sky-500">
                                    <div id="tracked_search_results"
                                        class="absolute left-0 top-full z-[999] w-full mt-2 bg-slate-900 border border-slate-700 rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.5)] hidden max-h-60 overflow-y-auto">
                                    </div>
                                </div>
                                <input type="text" id="newTokenId" placeholder="ID 自动填入" readonly
                                    class="flex-1 bg-slate-900/50 border border-slate-700 rounded-xl px-4 py-2 text-sm text-slate-500 outline-none">
                                <button onclick="submitTrackedToken()"
                                    class="bg-sky-500 text-white px-6 py-2 rounded-xl text-sm font-bold transition-all">确认添加</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative z-10">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-white">👛 钱包来源管理</h3>
                        <button onclick="document.getElementById('addWalletRow').classList.toggle('hidden')"
                            class="text-xs bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 px-4 py-2 rounded-lg">
                            + 新增钱包
                        </button>
                    </div>
                    <div class="bg-[#0f172a] border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                        <table class="w-full text-left">
                            <thead
                                class="bg-slate-800/50 text-slate-500 text-[10px] uppercase font-bold tracking-wider">
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
                                <input type="text" id="newWalletName" placeholder="如: 我的主钱包"
                                    class="flex-1 bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-sm text-white outline-none">
                                <select id="newWalletType"
                                    class="bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-sm text-white outline-none">
                                    <option value="Hot Wallet">热钱包</option>
                                    <option value="Cold Wallet">冷钱包</option>
                                    <option value="Exchange">交易所</option>
                                </select>
                                <button onclick="submitWallet()"
                                    class="bg-sky-500 text-white px-6 py-2 rounded-xl text-sm font-bold">保存</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-20 border-t border-red-900/30 pt-10">
                <h3 class="text-red-500 text-xl font-bold mb-6 flex items-center gap-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    危险区域 (Danger Zone)
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div
                        class="bg-red-500/5 border border-red-500/20 p-6 rounded-2xl flex justify-between items-center">
                        <div>
                            <h4 class="text-white font-medium">清空历史快照</h4>
                            <p class="text-xs text-slate-500 mt-1">仅删除图表走势数据，保留资产</p>
                        </div>
                        <button onclick="dangerAction('snapshots')"
                            class="px-4 py-2 bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-white border border-red-500/30 rounded-xl text-xs font-bold transition-all">确认清空</button>
                    </div>

                    <div
                        class="bg-red-500/5 border border-red-500/20 p-6 rounded-2xl flex justify-between items-center">
                        <div>
                            <h4 class="text-white font-medium">移除所有资产</h4>
                            <p class="text-xs text-slate-500 mt-1">清空看板上的所有代币持仓记录</p>
                        </div>
                        <button onclick="dangerAction('assets')"
                            class="px-4 py-2 bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-white border border-red-500/30 rounded-xl text-xs font-bold transition-all">全部移除</button>
                    </div>

                    <div
                        class="md:col-span-2 bg-red-600/10 border border-red-600/30 p-8 rounded-2xl flex justify-between items-center mt-4">
                        <div>
                            <h4 class="text-red-500 text-lg font-bold">出厂重置 (Wipe Everything)</h4>
                            <p class="text-sm text-slate-500 mt-1">删除资产、快照、钱包及追踪列表。此操作不可逆！</p>
                        </div>
                        <button onclick="dangerAction('wipe')"
                            class="px-8 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl font-black shadow-lg shadow-red-600/20 transition-all">全
                            部 毁 灭</button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <div id="addAssetModal"
        class="fixed inset-0 z-50 hidden flex items-center justify-center backdrop-blur-sm bg-black/60 transition-opacity duration-300 opacity-0">
        <div id="modalContent"
            class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl transform scale-95 transition-all duration-300 overflow-hidden">
            <div class="flex border-b border-slate-800">
                <button class="flex-1 py-4 text-sm font-bold border-b-2 border-sky-500 text-sky-500">✍️ 录入资产</button>
                <div class="flex-1 py-4 text-sm font-bold text-slate-600 text-center cursor-not-allowed">API 同步 (待开发)
                </div>
            </div>
            <form onsubmit="submitNewAsset(event)" class="p-6 space-y-5">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">资产来源</label>
                        <select id="asset_source_dropdown" name="source_name"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white outline-none"></select>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">所在网络</label>
                        <input type="text" name="network" placeholder="如: SOL"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white uppercase outline-none focus:border-sky-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">选择代币</label>
                        <select id="asset_token_dropdown" name="token_name" onchange="updateHiddenId(this)"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white outline-none focus:border-sky-500"></select>
                        <input type="hidden" id="hidden_coingecko_id" name="coingecko_id">
                    </div>
                    <div>
                        <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">持有数量</label>
                        <input type="number" step="any" name="token_amount" placeholder="0.00"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white outline-none focus:border-sky-500">
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeAddModal()"
                        class="text-slate-400 text-sm font-bold px-4">取消</button>
                    <button type="submit"
                        class="bg-sky-500 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-sky-500/20">保存到看板</button>
                </div>
            </form>
        </div>
    </div>
    <div id="editAssetModal"
        class="fixed inset-0 z-50 hidden flex items-center justify-center backdrop-blur-sm bg-black/60 transition-opacity duration-300 opacity-0">
        <div id="editModalContent"
            class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl transform scale-95 transition-all duration-300 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-800 flex justify-between items-center">
                <h3 class="text-sm font-bold text-white uppercase tracking-widest">📝 编辑资产信息</h3>
                <span id="edit-token-label"
                    class="text-[10px] bg-sky-500/10 text-sky-400 px-2 py-1 rounded-md font-mono"></span>
            </div>
            <form onsubmit="submitEditAsset(event)" class="p-6 space-y-5">
                <input type="hidden" id="edit_asset_id">
                <input type="hidden" id="edit_source_name">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">修改网络 (Chain)</label>
                        <input type="text" id="edit_network" name="network"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white uppercase outline-none focus:border-sky-500">
                    </div>
                    <div>
                        <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">修改持有数量</label>
                        <input type="number" step="any" id="edit_token_amount" name="token_amount"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white outline-none focus:border-sky-500">
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeEditModal()"
                        class="text-slate-400 text-sm font-bold px-4">取消</button>
                    <button type="submit"
                        class="bg-sky-500 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-sky-500/20">更新数据</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 1. 全局变量定义
        let isMYR = false;
        let MYR_RATE = 4.72;
        let globalPortfolioData = null;
        let globalSnapshotData = null;
        let myChart = null;
        let currentRange = '1D';

        // 2. 核心辅助函数
        function formatMoney(usdValue) {
            const val = parseFloat(usdValue) || 0;
            if (isMYR) return 'RM ' + (val * MYR_RATE).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return '$' + val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // 3. 页面初始化逻辑
        document.addEventListener('DOMContentLoaded', () => {
            loadAllData();
            loadTrackedTokens();
            loadWallets();
            initNavigation();
            initCurrencyToggle();
            initAlignedTimer();

            // 点击外部关闭搜索结果
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#addTokenRow')) {
                    const res = document.getElementById('tracked_search_results');
                    if (res) res.classList.add('hidden');
                }
            });
        });

        // --- 视图切换逻辑 ---
        function initNavigation() {
            document.querySelectorAll('.nav-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    // 切换按钮样式
                    document.querySelectorAll('.nav-btn').forEach(b => b.classList.replace('nav-active', 'nav-inactive'));
                    btn.classList.replace('nav-inactive', 'nav-active');

                    // 切换视图显示
                    document.querySelectorAll('.content-view').forEach(v => v.classList.add('hidden'));
                    const targetId = btn.getAttribute('data-target');
                    const targetView = document.getElementById(targetId);
                    if (targetView) targetView.classList.remove('hidden');
                });
            });
        }

        // --- 数据加载核心 ---
        async function loadAllData() {
            await refreshLiveExchangeRate();
            try {
                const mapRes = await fetch('/api/assets/thinking-map');
                const mapData = await mapRes.json();
                globalPortfolioData = mapData;
                renderPortfolio(mapData);

                const snapRes = await fetch(`/api/assets/snapshots?range=${currentRange}`);
                const snapData = await snapRes.json();
                globalSnapshotData = snapData;
                renderChart(snapData);
            } catch (e) { console.error("❌ 数据加载失败:", e); }
        }

        // --- 资产看板渲染 ---
        function renderPortfolio(data) {
            const container = document.getElementById('grid-container');
            const totalElem = document.getElementById('total-value');
            if (!container || !data) return;

            if (totalElem) totalElem.innerText = formatMoney(data.value || 0);
            container.innerHTML = '';

            const sources = data.children || [];
            sources.forEach((source, index) => {
                const card = document.createElement('div');
                const isFullWidth = (index % 2 === 0 && index === sources.length - 1);
                card.className = `bento-card ${isFullWidth ? 'full-row-card' : ''}`;

                let contentHtml = '';
                (source.children || []).forEach(network => {
                    contentHtml += `<div class="mt-4 mb-1 px-2"><span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">${network.name}</span></div>`;
                    (network.children || []).forEach(token => {
                        const amount = token.amount || 0;
                        const symbol = (token.symbol || 'UNT').toUpperCase();
                        const fullName = token.full_name || '';
                        const value = token.value || 0;
                        const tokenId = token._id || token.id || '';

                        contentHtml += `
                    <div class="flex justify-between items-center py-2 group hover:bg-slate-800/30 px-3 rounded-xl transition-all mb-1">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-50 text-sm font-bold">${amount}</span>
                            <div class="flex items-baseline gap-1">
                                <span class="text-sky-400 text-[11px] font-mono font-black">${symbol}</span>
                                <span class="text-slate-500 text-[10px] font-medium italic opacity-70">(${fullName})</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-white text-sm font-mono">${formatMoney(value)}</span>
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-all">
                                <button onclick="openEditModal('${tokenId}', '${amount}', '${symbol}', '${network.name}', '${source.name}')" class="p-1 text-slate-500 hover:text-sky-400 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                </button>
                                <button onclick="deleteAsset('${tokenId}')" class="p-1 text-slate-700 hover:text-red-500 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                </button>
                            </div>
                        </div>
                    </div>`;
                    });
                });

                card.innerHTML = `
                <div class="flex justify-between items-start">
                    <h3 class="text-slate-300 font-semibold text-lg">${source.name}</h3>
                    <span class="text-[10px] text-sky-400 font-bold uppercase">${(source.children || []).length} Nets</span>
                </div>
                <div class="mt-2">
                    <div class="${isFullWidth ? 'text-4xl' : 'text-2xl'} font-light text-white transition-all">${formatMoney(source.value)}</div>
                    <div class="mt-4 border-t border-slate-800 pt-2">${contentHtml}</div>
                </div>`;
                container.appendChild(card);
            });
        }

        // --- 图表渲染 (浅色白线优化版) ---
        function renderChart(data) {
            if (!data || !data.times || data.times.length === 0) return;
            const chartDom = document.getElementById('echarts-container');
            if (!myChart) {
                myChart = echarts.init(chartDom);
                window.addEventListener('resize', () => myChart.resize());
            }
            const seriesData = data.times.map((t, i) => [t, isMYR ? data.values[i] * MYR_RATE : data.values[i]]);
            myChart.setOption({
                tooltip: {
                    trigger: 'axis', backgroundColor: 'rgba(15, 23, 42, 0.9)', borderColor: '#334155', textStyle: { color: '#fff' },
                    formatter: (p) => {
                        // 🌟 1. 优化 Tooltip (鼠标悬浮提示)：手动提取时和分，去掉秒
                        let d = new Date(p[0].value[0]);
                        let timeStr = d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                        return `<div class="font-bold">${timeStr}</div><div class="text-sky-400">${formatMoney(p[0].value[1])}</div>`;
                    }
                },
                grid: { left: '4%', right: '4%', bottom: '10%', top: '15%', containLabel: true },
                xAxis: {
                    type: 'time', 
                    axisLabel: { 
                        color: '#64748b', 
                        fontSize: 10,
                        formatter: '{HH}:{mm}' // 🌟 2. 优化 X 轴：强制 ECharts 只显示 小时:分钟
                    },
                    axisLine: { lineStyle: { color: 'rgba(255, 255, 255, 0.1)' } },
                    splitLine: { show: true, lineStyle: { color: 'rgba(255, 255, 255, 0.03)', type: 'dashed' } }
                },
                yAxis: {
                    type: 'value', scale: true, axisLabel: { color: '#64748b', fontSize: 10 },
                    splitLine: { lineStyle: { color: 'rgba(255, 255, 255, 0.05)', type: 'solid' } }
                },
                series: [{
                    data: seriesData, type: 'line', smooth: 0.4, symbol: 'circle', symbolSize: 4,
                    itemStyle: { color: '#38bdf8' }, lineStyle: { width: 2 },
                    areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: 'rgba(56, 189, 248, 0.15)' }, { offset: 1, color: 'rgba(56, 189, 248, 0)' }]) }
                }]
            }, true);
        }

        // --- 编辑逻辑 ---
        function openEditModal(id, amount, symbol, network, source) {
            document.getElementById('edit_asset_id').value = id;
            document.getElementById('edit_token_amount').value = amount;
            document.getElementById('edit_network').value = network;
            document.getElementById('edit_source_name').value = source;
            document.getElementById('edit-token-label').innerText = symbol;
            const modal = document.getElementById('editAssetModal');
            modal.classList.remove('hidden');
            setTimeout(() => { modal.classList.add('opacity-100'); document.getElementById('editModalContent').classList.replace('scale-95', 'scale-100'); }, 10);
        }

        function closeEditModal() {
            const modal = document.getElementById('editAssetModal');
            modal.classList.remove('opacity-100');
            document.getElementById('editModalContent').classList.replace('scale-100', 'scale-95');
            setTimeout(() => modal.classList.add('hidden'), 300);
        }

        async function submitEditAsset(event) {
            event.preventDefault();
            const id = document.getElementById('edit_asset_id').value;
            const amount = document.getElementById('edit_token_amount').value;
            const network = document.getElementById('edit_network').value;
            const source = document.getElementById('edit_source_name').value;
            try {
                const res = await fetch(`/api/assets/${id}`, {
                    method: 'PUT', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token_amount: parseFloat(amount), network, source_name: source })
                });
                if (res.ok) { closeEditModal(); await loadAllData(); }
            } catch (e) { console.error("更新失败", e); }
        }

        // --- 设置页面管理逻辑 (Tracked Tokens) ---
        async function loadTrackedTokens() {
            try {
                const res = await fetch('/api/tracked-tokens');
                const tokens = await res.json();
                const list = document.getElementById('tracked-tokens-list');
                if (!list) return;
                list.innerHTML = tokens.map(t => `
                <tr class="hover:bg-slate-800/30 transition-colors">
                    <td class="px-6 py-4 text-sm text-white font-medium">${t.name}</td>
                    <td class="px-6 py-4 text-sm text-slate-500 font-mono">${t.coingecko_id}</td>
                    <td class="px-6 py-4 text-right">
                        <button onclick="deleteTrackedToken('${t.id}')" class="text-red-500/70 hover:text-red-500">停止追踪</button>
                    </td>
                </tr>`).join('');
            } catch (e) { }
        }

        let trackedSearchTimer;
        async function searchCoinGeckoTracked(query) {
            const resultsContainer = document.getElementById('tracked_search_results');
            if (query.length < 2) { resultsContainer.classList.add('hidden'); return; }
            clearTimeout(trackedSearchTimer);
            trackedSearchTimer = setTimeout(async () => {
                try {
                    const res = await fetch(`https://api.coingecko.com/api/v3/search?query=${query}`);
                    const data = await res.json();
                    if (data.coins) {
                        resultsContainer.innerHTML = data.coins.slice(0, 5).map(coin => `
                        <div onclick="selectTrackedToken('${coin.id}', '${coin.name}')" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-800 cursor-pointer border-b border-slate-800 last:border-0 transition-colors">
                            <img src="${coin.thumb}" class="w-5 h-5 rounded-full">
                            <span class="text-sm text-white font-medium">${coin.name} <span class="text-slate-500 uppercase text-xs">(${coin.symbol})</span></span>
                        </div>`).join('');
                        resultsContainer.classList.remove('hidden');
                    }
                } catch (e) { }
            }, 300);
        }

        function selectTrackedToken(id, name) {
            document.getElementById('newTokenId').value = id;
            document.getElementById('search_tracked_input').value = name;
            document.getElementById('tracked_search_results').classList.add('hidden');
        }

        async function submitTrackedToken() {
            const id = document.getElementById('newTokenId').value;
            if (!id) return alert("请先选择代币");
            await fetch('/api/tracked-tokens', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ coingecko_id: id }) });
            location.reload();
        }

        async function deleteTrackedToken(id) {
            if (!confirm('确定停止追踪？')) return;
            await fetch(`/api/tracked-tokens/${id}`, { method: 'DELETE' });
            loadTrackedTokens();
        }

        // --- 设置页面管理逻辑 (Wallets) ---
        async function loadWallets() {
            try {
                const res = await fetch('/api/wallets');
                const wallets = await res.json();
                const list = document.getElementById('wallets-list');
                if (!list) return;
                list.innerHTML = wallets.map(w => `
                <tr class="hover:bg-slate-800/30">
                    <td class="px-6 py-4 text-sm text-white font-medium">${w.name}</td>
                    <td class="px-6 py-4 text-sm text-slate-500">${w.type}</td>
                    <td class="px-6 py-4 text-right">
                        <button onclick="deleteWallet('${w.id}')" class="text-red-500/70 hover:text-red-500">删除</button>
                    </td>
                </tr>`).join('');
            } catch (e) { }
        }

        async function submitWallet() {
            const nameInput = document.getElementById('newWalletName');
            const typeInput = document.getElementById('newWalletType');

            const name = nameInput.value;
            const type = typeInput.value;

            if (!name) return alert("请输入钱包名称");

            // 禁用按钮防止重复点击
            const btn = event.target;
            btn.disabled = true;

            try {
                const res = await fetch('/api/wallets', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ name, type })
                });

                const result = await res.json();

                if (res.ok) {
                    nameInput.value = ''; // 清空输入框
                    await loadWallets();  // 刷新列表
                    document.getElementById('addWalletRow').classList.add('hidden');
                    console.log("✅ 钱包添加成功");
                } else {
                    // 如果后端报 500 或 422，这里会弹出具体错误
                    alert("❌ 保存失败: " + (result.message || "服务器内部错误"));
                }
            } catch (e) {
                console.error(e);
                alert("❌ 网络请求失败，请检查 API 路由是否存在");
            } finally {
                btn.disabled = false;
            }
        }

        async function deleteWallet(id) {
            if (!confirm('确定删除此钱包？')) return;
            await fetch(`/api/wallets/${id}`, { method: 'DELETE' });
            loadWallets();
        }

        // --- 通用功能 (汇率、同步、删除资产) ---
        async function refreshLiveExchangeRate() {
            try {
                const res = await fetch('/api/exchange-rate');
                const data = await res.json();
                if (data.rate) {
                    MYR_RATE = data.rate;
                    const hint = document.getElementById('rate-hint');
                    if (hint) hint.innerText = `全局切换法币计价单位 (当前实时汇率: ${MYR_RATE.toFixed(2)})`;
                }
            } catch (e) { }
        }

        function initCurrencyToggle() {
            const toggle = document.getElementById('currency-toggle');
            if (!toggle) return;
            toggle.addEventListener('click', function () {
                isMYR = !isMYR;
                this.classList.toggle('bg-slate-700'); this.classList.toggle('bg-sky-500');
                document.getElementById('currency-toggle-knob').classList.toggle('translate-x-6');
                renderPortfolio(globalPortfolioData);
                renderChart(globalSnapshotData);
            });
        }

        async function changeRange(range) {
            currentRange = range;
            document.querySelectorAll('.range-btn').forEach(btn => {
                const active = btn.innerText === range;
                btn.className = `range-btn ${active ? 'bg-sky-500 text-white' : 'bg-slate-800/80 text-slate-400'} text-[10px] font-bold px-3 py-1 rounded-md transition-all`;
            });
            await loadAllData();
        }

        async function deleteAsset(id) {
            if (!confirm('确认移除？')) return;
            await fetch(`/api/assets/${id}`, { method: 'DELETE' });
            loadAllData();
        }

        function initAlignedTimer() {
            const msInFiveMins = 5 * 60 * 1000;
            const msToNextTick = msInFiveMins - (Date.now() % msInFiveMins);
            setTimeout(async () => {
                await fetch('/api/assets/sync', { method: 'POST' });
                await loadAllData();
                setInterval(async () => { await fetch('/api/assets/sync', { method: 'POST' }); await loadAllData(); }, msInFiveMins);
            }, msToNextTick);
        }

        // 添加资产 Modal 控制 (复用你原本的逻辑)
        async function openAddModal() {
            const [wallets, tokens] = await Promise.all([
                fetch('/api/wallets').then(res => res.json()),
                fetch('/api/tracked-tokens').then(res => res.json())
            ]);
            const s_drop = document.getElementById('asset_source_dropdown');
            const t_drop = document.getElementById('asset_token_dropdown');
            if (s_drop) s_drop.innerHTML = wallets.map(w => `<option value="${w.name}">${w.name}</option>`).join('') || '<option value="">无钱包</option>';
            if (t_drop) t_drop.innerHTML = tokens.map(t => `<option value="${t.name}" data-id="${t.coingecko_id}">${t.name}</option>`).join('') || '<option value="">无追踪代币</option>';
            updateHiddenId(t_drop);
            const modal = document.getElementById('addAssetModal');
            modal.classList.remove('hidden');
            setTimeout(() => { modal.classList.add('opacity-100'); document.getElementById('modalContent').classList.replace('scale-95', 'scale-100'); }, 10);
        }

        function closeAddModal() {
            const modal = document.getElementById('addAssetModal');
            modal.classList.remove('opacity-100');
            document.getElementById('modalContent').classList.replace('scale-100', 'scale-95');
            setTimeout(() => modal.classList.add('hidden'), 300);
        }

        function updateHiddenId(select) {
            const opt = select.options[select.selectedIndex];
            if (opt) document.getElementById('hidden_coingecko_id').value = opt.getAttribute('data-id');
        }

        async function submitNewAsset(event) {
            event.preventDefault();
            const data = Object.fromEntries(new FormData(event.target).entries());
            try {
                const res = await fetch('/api/assets', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
                if (res.ok) { closeAddModal(); await loadAllData(); }
            } catch (e) { }
        }
        async function triggerManualSync() {
            const btn = document.getElementById('manual-sync-btn');
            const icon = document.getElementById('sync-icon');
            const text = document.getElementById('sync-text');

            // 1. 进入加载状态
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            icon.classList.add('animate-spin'); // 让图标转起来
            text.innerText = '同步中...';

            try {
                // 2. 调用后端同步接口
                const res = await fetch('/api/assets/sync', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' }
                });

                if (res.ok) {
                    // 3. 同步成功后，刷新全局数据（包括看板和图表）
                    await loadAllData();

                    // 绿色反馈
                    text.innerText = '同步成功！';
                    setTimeout(() => {
                        text.innerText = '立即同步价格';
                        icon.classList.remove('animate-spin');
                        btn.disabled = false;
                        btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    }, 2000);
                } else {
                    throw new Error("同步失败");
                }
            } catch (e) {
                console.error(e);
                alert("❌ 同步请求失败，请检查网络或 API 限制");
                icon.classList.remove('animate-spin');
                text.innerText = '立即同步价格';
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }
    </script>
</body>

</html>