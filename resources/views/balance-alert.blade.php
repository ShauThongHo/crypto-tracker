@extends('layouts.app')

@section('content')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <section class="content-view p-4 md:p-8 lg:p-12">
        <header class="mb-10">
            <h2 class="text-slate-500 text-sm font-medium tracking-widest uppercase mb-2">Risk Control</h2>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-3xl font-light text-white">平衡提醒</div>
                    <p class="text-slate-400 mt-2 text-sm">规则窗口：每年 1 / 4 / 7 / 10 月下旬（21 号到月末）</p>
                </div>
                <button id="openReminderSettingsBtn" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm border border-slate-700">
                    提醒设置
                </button>
            </div>
        </header>

        <div class="w-full max-w-none grid grid-cols-1 xl:grid-cols-12 gap-6">
            <section class="xl:col-span-12 bg-[#0f172a] border border-slate-800 rounded-3xl p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                    <h3 class="text-lg font-bold text-white">平衡列表</h3>
                    <div id="windowBadge" class="text-xs px-3 py-1 rounded-full border border-slate-700 text-slate-300">窗口状态：检测中</div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                        <div id="totalValueLabel" class="text-[10px] uppercase tracking-wider text-slate-500">总资产 (USD)</div>
                        <div id="totalValue" class="text-white text-xl font-semibold mt-1">0.00</div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                        <div class="text-[10px] uppercase tracking-wider text-slate-500">币种数量</div>
                        <div id="tokenCount" class="text-white text-xl font-semibold mt-1">0</div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                        <div class="text-[10px] uppercase tracking-wider text-slate-500">默认均分占比</div>
                        <div id="defaultTargetWeight" class="text-white text-xl font-semibold mt-1">0%</div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                        <div class="text-[10px] uppercase tracking-wider text-slate-500">最大偏离</div>
                        <div id="maxDeviation" class="text-white text-xl font-semibold mt-1">0%</div>
                    </div>
                </div>

                                <div class="flex flex-wrap items-center justify-between gap-3 mb-4 bg-slate-900 border border-slate-800 rounded-2xl p-3">
                    <div class="text-xs text-slate-400">目标占比合计：<span id="targetSum" class="font-bold text-white">0.00%</span></div>
                    <div class="flex gap-2">
                        <button id="openAllocationSettingsBtn" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs">格子设置</button>
                    </div>
                </div>

                <div id="levelBanner" class="rounded-2xl border border-slate-700 bg-slate-900 p-4 mb-5">
                    <div class="text-xs uppercase tracking-wider text-slate-400 mb-1">提醒等级</div>
                    <div id="levelTitle" class="text-white text-xl font-bold">等待检查</div>
                    <p id="levelMessage" class="text-slate-400 text-sm mt-1">请先点击“检查偏离”。</p>
                </div>

                <div class="text-sm text-slate-300 mb-3">比例明细</div>

                <div id="detailsSection" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-800/50 text-[10px] uppercase text-slate-500 tracking-widest">
                            <tr>
                                <th class="px-4 py-3">币种</th>
                                <th id="detailsValueHeader" class="px-4 py-3 text-right">价值 (USD)</th>
                                <th class="px-4 py-3 text-right">当前占比</th>
                                <th class="px-4 py-3 text-right">目标占比</th>
                                <th class="px-4 py-3 text-right">偏离修正比例</th>
                                <th class="px-4 py-3 text-right">绝对偏离</th>
                                <th class="px-4 py-3 text-right">调仓建议 (USD)</th>
                            </tr>
                        </thead>
                        <tbody id="tokensBody" class="divide-y divide-slate-800">
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-slate-500">暂无数据</td>
                            </tr>
                        </tbody>
                        <tfoot id="tokensFooter" class="bg-slate-900 border-t border-slate-700">
                            <tr>
                                <td class="px-4 py-3 text-slate-300 font-semibold">汇总</td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td id="summaryCell" class="px-4 py-3 text-right text-slate-300 font-semibold"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div id="helperText" class="mt-6 text-xs text-slate-500">这里会同时展示单币和组合，统一按一张列表计算偏离。</div>
            </section>
        </div>

        <div id="reminderSettingsModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 backdrop-blur-sm px-4">
            <div class="w-full max-w-2xl rounded-3xl border border-slate-800 bg-[#0f172a] shadow-2xl shadow-black/40">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                    <div>
                        <div class="text-lg font-bold text-white">提醒设置</div>
                        <div class="text-xs text-slate-500 mt-1">不常用的配置收在弹窗里</div>
                    </div>
                    <button id="closeReminderSettingsBtn" class="text-slate-400 hover:text-white text-sm">关闭</button>
                </div>

                <div class="p-6 space-y-4">
                    <div>
                        <label for="webhookUrl" class="block text-xs text-slate-400 mb-2 uppercase tracking-wider">Discord Webhook</label>
                        <input id="webhookUrl" type="url" placeholder="https://discord.com/api/webhooks/..."
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:border-sky-500 outline-none transition-all" />
                        <p class="text-[11px] text-slate-500 mt-2">仅保存在当前浏览器 localStorage，不会写入数据库。</p>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div>
                            <label for="prepareThreshold" class="block text-xs text-slate-400 mb-2 uppercase tracking-wider">准备资金阈值 (%)</label>
                            <input id="prepareThreshold" type="number" step="0.1" min="0" value="3"
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:border-emerald-500 outline-none transition-all" />
                        </div>

                        <div>
                            <label for="rebalanceThreshold" class="block text-xs text-slate-400 mb-2 uppercase tracking-wider">平衡阈值 (%)</label>
                            <input id="rebalanceThreshold" type="number" step="0.1" min="0" value="5"
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:border-amber-500 outline-none transition-all" />
                        </div>

                        <div>
                            <label for="forceThreshold" class="block text-xs text-slate-400 mb-2 uppercase tracking-wider">强制平衡阈值 (%)</label>
                            <input id="forceThreshold" type="number" step="0.1" min="0" value="7.5"
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:border-red-500 outline-none transition-all" />
                        </div>
                    </div>

                    <div class="bg-slate-900/70 border border-slate-800 rounded-xl p-3">
                        <div class="text-xs text-slate-300">平衡方式：单一列表</div>
                        <p class="text-[11px] text-slate-500 mt-1">每一行都可以是单币或组合，右侧填目标占比。删除组合时会把币种拆回单币行并平均分配目标占比。</p>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button id="checkBtn" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold text-sm transition-all">检查偏离</button>
                        <button id="sendBtn" class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-sm transition-all">发送图片提醒</button>
                    </div>

                    <div id="statusBox" class="hidden rounded-xl border p-3 text-sm"></div>
                </div>
            </div>
        </div>
        
        <div id="symbolTargetsModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/80 backdrop-blur-sm px-4">
            <div class="w-full max-w-lg rounded-3xl border border-slate-800 bg-[#0f172a] shadow-2xl shadow-black/40">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                    <div>
                        <div id="symbolTargetsTitle" class="text-lg font-bold text-white">细分设置</div>
                        <div class="text-xs text-slate-500 mt-1">为组合内每个币种设置相对权重（只影响该格子内分配）</div>
                    </div>
                    <button id="closeSymbolTargetsBtn" class="text-slate-400 hover:text-white text-sm">关闭</button>
                </div>
                <div class="p-6 space-y-4">
                    <div id="symbolTargetsList" class="grid gap-3"></div>
                    <div class="flex gap-2 justify-end">
                        <button id="saveSymbolTargetsBtn" class="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-sm">保存</button>
                        <button id="cancelSymbolTargetsBtn" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm">取消</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="allocationSettingsModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 backdrop-blur-sm px-4">
            <div class="w-full max-w-6xl max-h-[88vh] rounded-3xl border border-slate-800 bg-[#0f172a] shadow-2xl shadow-black/40 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                    <div>
                        <div class="text-lg font-bold text-white">格子设置</div>
                        <div class="text-xs text-slate-500 mt-1">把币种拖进格子，设置目标占比</div>
                    </div>
                    <button id="closeAllocationSettingsBtn" class="text-slate-400 hover:text-white text-sm">关闭</button>
                </div>

                <div id="allocationBuilder" class="p-6 overflow-y-auto">
                                        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div class="text-sm text-white font-semibold">创建格子</div>
                        <div class="flex gap-2">
                            <button id="equalizeBtn" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs">一键均分</button>
                            <button id="syncWeightBtn" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs">按当前占比填充</button>
                            <button id="addAllocationBtn" class="px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-xs">新增格子</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_420px] gap-6 items-start">
                        <div class="rounded-2xl border border-slate-800 overflow-hidden bg-slate-950">
                            <div class="grid grid-cols-[minmax(0,1fr)_140px_90px] gap-3 items-center px-4 py-3 bg-slate-900 border-b border-slate-800 text-xs font-semibold text-slate-300">
                                <div>名称</div>
                                <div class="text-right">目标占比</div>
                                <div class="text-center">操作</div>
                            </div>
                            <div id="allocationList" class="divide-y divide-slate-800 max-h-[56vh] overflow-y-auto"></div>
                        </div>

                        <div class="xl:sticky xl:top-6 self-start">
                            <div class="bg-slate-950 border border-slate-800 rounded-2xl p-4 shadow-2xl shadow-black/20">
                                <div class="flex flex-col gap-1 mb-3">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500">暂时未分配币种</div>
                                    <div class="text-[11px] text-slate-400">拖回这里即可取消分配</div>
                                </div>
                                <div id="tokenPool" class="h-[380px] overflow-y-auto bg-slate-950 border border-dashed border-slate-700 rounded-xl p-2 flex flex-wrap content-start gap-2"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script type="module" src="{{ asset('js/pages/balance-alert-page.js') }}"></script>
@endsection
