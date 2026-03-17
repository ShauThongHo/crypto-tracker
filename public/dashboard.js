/**
 * HST Dashboard - Final Refined Logic (Integrated Version)
 */

// ==========================================
// 1. 全局变量定义
// ==========================================
let isMYR = localStorage.getItem('preferred_currency') === 'MYR';
let MYR_RATE = 4.2;
let globalPortfolioData = null;
let globalSnapshotData = null;
let myChart = null;
let historyCalendarChart = null;
let historyMonthChart = null;
let currentRange = '1D';
let lastKnownSync = null;
let currentViewMonthDate = new Date();
let trackedSearchTimer = null;

// CSRF Token 工具函数
const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

// --- 1. 全局工具函数 (置顶定义，确保全局可用) ---

/**
 * 格式化金额：自动识别 USD 或 MYR
 */
function formatMoney(usdValue) {
    const val = parseFloat(usdValue) || 0;
    if (isMYR) {
        return 'RM ' + (val * MYR_RATE).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    return '$' + val.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * 获取最新汇率
 */
async function refreshLiveExchangeRate() {
    try {
        const res = await fetch('/api/exchange-rate');
        const data = await res.json();
        if (data.rate) {
            MYR_RATE = parseFloat(data.rate);
            const hint = document.getElementById('rate-hint');
            if (hint) hint.innerText = `全局切换法币计价单位 (当前实时汇率: ${MYR_RATE.toFixed(2)})`;
            console.log("✅ 汇率已更新:", MYR_RATE);
        }
    } catch (e) {
        console.warn("⚠️ 汇率接口请求失败，使用默认值 4.72");
    }
}

// ==========================================
// 2. 页面初始化驱动
// ==========================================
document.addEventListener('DOMContentLoaded', async () => {
    console.log("🚀 HST Dashboard 正在启动...");

    // 核心修复：无论在哪个页面，优先加载汇率
    await refreshLiveExchangeRate();

    // 初始化通用 UI 交互
    initCurrencyToggle();

    // A. 自动检测首页容器
    if (document.getElementById('grid-container')) {
        await loadAllData();
    }

    // B. 自动检测设置页容器
    if (document.getElementById('tracked-tokens-list')) {
        loadTrackedTokens();
        loadWallets();
    }

    // C. 自动检测历史页容器
    if (document.getElementById('calendar-echarts-container')) {
        loadHistoryData();
    }

    // 通用背景任务
    initAlignedTimer();
    updateSyncBadgeAndCheckUpdate();
    setInterval(updateSyncBadgeAndCheckUpdate, 5000);

    // 点击外部关闭搜索结果
    document.addEventListener('click', (e) => {
        const res = document.getElementById('tracked_search_results');
        if (res && !e.target.closest('#addTokenRow')) res.classList.add('hidden');
    });
});

// ==========================================
// 3. 挂载到 window 的交互函数 (确保按钮 onclick 可用)
// ==========================================

// --- 弹窗控制 ---
window.openAddModal = async function () {
    try {
        const [wallets, tokens] = await Promise.all([
            fetch('/api/wallets').then(res => res.json()),
            fetch('/api/tracked-tokens').then(res => res.json())
        ]);
        const s_drop = document.getElementById('asset_source_dropdown');
        const t_drop = document.getElementById('asset_token_dropdown');
        if (s_drop) s_drop.innerHTML = wallets.map(w => `<option value="${w.name}">${w.name}</option>`).join('') || '<option value="">无钱包</option>';
        if (t_drop) t_drop.innerHTML = tokens.map(t => `<option value="${t.name}" data-id="${t.coingecko_id}">${t.name}</option>`).join('') || '<option value="">无追踪</option>';

        window.updateHiddenId(t_drop);
        const modal = document.getElementById('addAssetModal');
        modal.classList.remove('hidden');
        setTimeout(() => modal.classList.add('opacity-100'), 10);
    } catch (e) { console.error("加载弹窗失败", e); }
};

window.closeAddModal = () => {
    const modal = document.getElementById('addAssetModal');
    modal.classList.remove('opacity-100');
    setTimeout(() => modal.classList.add('hidden'), 300);
};

window.openEditModal = (id, amount, symbol, network, source) => {
    document.getElementById('edit_asset_id').value = id;
    document.getElementById('edit_token_amount').value = amount;
    document.getElementById('edit_network').value = network;
    document.getElementById('edit_source_name').value = source;
    document.getElementById('edit-token-label').innerText = symbol;
    const modal = document.getElementById('editAssetModal');
    modal.classList.remove('hidden');
    setTimeout(() => modal.classList.add('opacity-100'), 10);
};

window.closeEditModal = () => {
    const modal = document.getElementById('editAssetModal');
    modal.classList.remove('opacity-100');
    setTimeout(() => modal.classList.add('hidden'), 300);
};

window.updateHiddenId = (select) => {
    if (!select || select.selectedIndex === -1) return;
    const opt = select.options[select.selectedIndex];
    if (opt) document.getElementById('hidden_coingecko_id').value = opt.getAttribute('data-id');
};

// --- 操作函数 ---
window.changeRange = async (range) => {
    currentRange = range;
    const btns = document.querySelectorAll('.range-btn');
    btns.forEach(b => b.classList.toggle('bg-sky-500', b.innerText === range));
    btns.forEach(b => b.classList.toggle('text-white', b.innerText === range));
    await loadAllData();
};

// --- 补全：提交资产修改 ---
window.submitEditAsset = async (event) => {
    event.preventDefault(); // 阻止表单默认提交行为

    const id = document.getElementById('edit_asset_id').value;
    const amount = document.getElementById('edit_token_amount').value;
    const network = document.getElementById('edit_network').value;
    const source = document.getElementById('edit_source_name').value;

    try {
        const res = await fetch(`/api/assets/${id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                // 🎯 核心点：必须携带 CSRF Token，否则 Laravel 会报 419 错误并拦截请求
                'X-CSRF-TOKEN': getCsrfToken()
            },
            body: JSON.stringify({
                token_amount: parseFloat(amount),
                network: network,
                source_name: source
            })
        });

        if (res.ok) {
            console.log("✅ 资产数据更新成功");
            window.closeEditModal(); // 关闭弹窗
            await loadAllData();     // 重新加载并渲染首页数据
        } else {
            const err = await res.json();
            alert("❌ 更新失败: " + (err.message || "服务器拒绝了修改"));
        }
    } catch (e) {
        console.error("更新请求发生错误:", e);
        alert("网络请求失败，请检查 API 连通性");
    }
};

window.submitNewAsset = async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.target).entries());
    const res = await fetch('/api/assets', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify(data)
    });
    if (res.ok) { window.closeAddModal(); await loadAllData(); }
};

window.deleteAsset = async (id) => {
    if (!confirm('确认从看板移除此资产？')) return;
    const res = await fetch(`/api/assets/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() }
    });
    if (res.ok) await loadAllData();
};

window.submitWallet = async () => {
    const name = document.getElementById('newWalletName').value;
    const type = document.getElementById('newWalletType').value;
    if (!name) return alert("请输入名字");
    const res = await fetch('/api/wallets', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ name, type })
    });
    if (res.ok) { document.getElementById('newWalletName').value = ''; loadWallets(); }
};

window.deleteWallet = async (id) => {
    if (!confirm('确定删除此钱包？')) return;
    await fetch(`/api/wallets/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': getCsrfToken() } });
    loadWallets();
};

window.dangerAction = async (type) => {
    const word = type === 'wipe' ? 'WIPE' : 'DELETE';
    if (prompt(`⚠️ 危险操作！请输入 ${word} 确认：`) !== word) return;
    const urls = { 'snapshots': '/api/danger/snapshots', 'assets': '/api/danger/assets', 'wipe': '/api/danger/wipe' };
    const res = await fetch(urls[type], { method: 'DELETE', headers: { 'X-CSRF-TOKEN': getCsrfToken() } });
    if (res.ok) location.reload();
};

window.changeMonth = function (offset) {
    currentViewMonthDate.setMonth(currentViewMonthDate.getMonth() + offset);
    if (globalSnapshotData) renderCalendarHistory(globalSnapshotData);
};

// ==========================================
// 4. 数据加载与同步
// ==========================================
async function loadAllData() {
    try {
        const mapRes = await fetch('/api/assets/thinking-map');
        globalPortfolioData = await mapRes.json();
        renderPortfolio(globalPortfolioData);

        const snapRes = await fetch(`/api/assets/snapshots?range=${currentRange}`);
        globalSnapshotData = await snapRes.json();
        renderChart(globalSnapshotData);
    } catch (e) { console.error("首页数据加载失败", e); }
}

async function loadHistoryData() {
    try {
        const res = await fetch('/api/assets/snapshots?range=30D');
        globalSnapshotData = await res.json();
        renderCalendarHistory(globalSnapshotData);
    } catch (e) { console.error("历史快照加载失败", e); }
}

async function loadTrackedTokens() {
    const res = await fetch('/api/tracked-tokens');
    const tokens = await res.json();
    const list = document.getElementById('tracked-tokens-list');
    if (list) list.innerHTML = tokens.map(t => `
        <tr class="hover:bg-slate-800/30">
            <td class="px-6 py-4 text-sm text-white">${t.name}</td>
            <td class="px-6 py-4 text-sm text-slate-500 font-mono">${t.coingecko_id}</td>
            <td class="px-6 py-4 text-right">
                <button onclick="window.deleteTrackedToken('${t.id}')" class="text-red-500">停止</button>
            </td>
        </tr>`).join('');
}

async function loadWallets() {
    const res = await fetch('/api/wallets');
    const wallets = await res.json();
    const list = document.getElementById('wallets-list');
    if (list) list.innerHTML = wallets.map(w => `
        <tr class="hover:bg-slate-800/30">
            <td class="px-6 py-4 text-sm text-white">${w.name}</td>
            <td class="px-6 py-4 text-sm text-slate-500">${w.type}</td>
            <td class="px-6 py-4 text-right">
                <button onclick="window.deleteWallet('${w.id}')" class="text-red-500">删除</button>
            </td>
        </tr>`).join('');
}

// ==========================================
// 5. 绘图与渲染引擎
// ==========================================
function renderPortfolio(data) {
    const container = document.getElementById('grid-container');
    const totalElem = document.getElementById('total-value');
    if (!container || !data) return;

    totalElem.innerText = formatMoney(data.value || 0);
    container.innerHTML = '';

    (data.children || []).forEach((source, index) => {
        const isFull = (index % 2 === 0 && index === data.children.length - 1);
        const card = document.createElement('div');
        card.className = `bento-card ${isFull ? 'full-row-card' : ''}`;

        let html = `
            <div class="flex justify-between items-start">
                <h3 class="text-slate-300 font-semibold text-lg">${source.name}</h3>
                <span class="text-[10px] text-sky-400 font-bold uppercase">${source.children.length} Nets</span>
            </div>
            <div class="mt-2">
                <div class="text-2xl font-light text-white">${formatMoney(source.value)}</div>
                <div class="mt-4 border-t border-slate-800 pt-2">`;

        source.children.forEach(net => {
            html += `<div class="mt-2 mb-1 text-[9px] font-bold text-slate-600 uppercase px-2 tracking-widest">${net.name}</div>`;
            net.children.forEach(token => {
                html += `
                    <div class="flex justify-between items-center py-2 group hover:bg-slate-800/30 px-3 rounded-xl transition-all">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-50 text-sm font-bold">${token.amount}</span>
                            <span class="text-sky-400 text-[11px] font-mono font-black">${token.symbol}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-white text-sm font-mono">${formatMoney(token.value)}</span>
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-all">
                                <button onclick="window.openEditModal('${token.id}', '${token.amount}', '${token.symbol}', '${net.name}', '${source.name}')" class="p-1 text-slate-500 hover:text-sky-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <button onclick="window.deleteAsset('${token.id}')" class="p-1 text-slate-700 hover:text-red-500">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>`;
            });
        });
        card.innerHTML = html + `</div></div>`;
        container.appendChild(card);
    });
}

function renderChart(data) {
    if (!data || !data.times || data.times.length === 0) return;
    const chartDom = document.getElementById('echarts-container');
    if (!myChart) myChart = echarts.init(chartDom);
    const seriesData = data.times.map((t, i) => [t, isMYR ? data.values[i] * MYR_RATE : data.values[i]]);
    myChart.setOption({
        tooltip: { trigger: 'axis', backgroundColor: '#0f172a', textStyle: { color: '#fff' } },
        xAxis: { type: 'time', axisLabel: { color: '#64748b' } },
        yAxis: { type: 'value', scale: true, axisLabel: { color: '#64748b' }, splitLine: { lineStyle: { color: 'rgba(255,255,255,0.05)' } } },
        series: [{ data: seriesData, type: 'line', smooth: 0.4, itemStyle: { color: '#38bdf8' }, areaStyle: { color: 'rgba(56, 189, 248, 0.1)' } }]
    }, true);
}

// ==========================================
// 4. 盈亏日历渲染核心 (完全照搬 map.blade.php)
// ==========================================
// --- 覆盖: 盈亏日历渲染引擎 (高鲁棒性版本) ---
// --- 盈亏日历渲染核心 (1:1 还原 map.blade.php 逻辑) ---
function renderCalendarHistory(data) {
    // 即使 data 为空也不退出，确保渲染出空白日历格
    const yearDom = document.getElementById('calendar-echarts-container');
    const monthDom = document.getElementById('month-echarts-container');
    if (!yearDom || !monthDom) return;

    if (!historyCalendarChart) historyCalendarChart = echarts.init(yearDom);
    if (!historyMonthChart) historyMonthChart = echarts.init(monthDom);

    // 1. 数据按天分组
    const dailyDataMap = {};
    if (data && data.times && data.times.length > 0) {
        data.times.forEach((t, i) => {
            const d = new Date(t);
            const ds = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
            if (!dailyDataMap[ds]) dailyDataMap[ds] = [];
            dailyDataMap[ds].push(isMYR ? data.values[i] * MYR_RATE : data.values[i]);
        });
    }

    // 2. 补全计算逻辑：继承前一日收盘价 (让计算不中断)
    const calendarSeriesData = [];
    let previousDayClose = null;
    const currentYear = new Date().getFullYear();
    const startDate = new Date(currentYear, 0, 1);
    const today = new Date();

    for (let d = new Date(startDate); d <= today; d.setDate(d.getDate() + 1)) {
        const ds = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

        if (dailyDataMap[ds]) {
            const values = dailyDataMap[ds];
            // 盈亏 = 今天的最后一点 - (昨天的最后一点 或 今天的最初一点)
            const dayOpen = previousDayClose !== null ? previousDayClose : values[0];
            const dayClose = values[values.length - 1];
            const pnl = dayClose - dayOpen;
            const pct = dayOpen === 0 ? 0 : (pnl / dayOpen) * 100;

            calendarSeriesData.push([ds, pnl, pct, dayClose, true]);
            previousDayClose = dayClose;
        } else {
            // 无数据日：盈亏设为 0，继承资产，标记为 false
            calendarSeriesData.push([ds, 0, 0, previousDayClose || 0, false]);
        }
    }

    // --- 提取公共 Tooltip ---
    const commonTooltip = {
        backgroundColor: 'rgba(15, 23, 42, 0.95)', borderColor: '#334155', padding: 12, textStyle: { color: '#f8fafc' },
        formatter: function (p) {
            const [date, pnl, pct, total, hasData] = p.value;
            if (!hasData && total === 0) return `<div class="font-bold text-slate-500">${date}<br>暂无数据</div>`;
            const isUp = pnl > 0; const isDown = pnl < 0;
            const colorClass = isUp ? 'text-emerald-400' : (isDown ? 'text-rose-400' : 'text-slate-400');
            const sign = isUp ? '+' : (isDown ? '-' : '');
            return `
                <div class="font-bold text-slate-300 border-b border-slate-700 pb-2 mb-2">${date}</div>
                <div class="flex justify-between gap-6 mb-1 text-xs"><span>资产总额:</span><span class="font-bold">${formatMoney(total)}</span></div>
                <div class="flex justify-between gap-6 mb-1 text-xs"><span>当日盈亏:</span><span class="font-bold ${colorClass}">${sign}${formatMoney(Math.abs(pnl))}</span></div>
                <div class="flex justify-between gap-6 text-xs"><span>涨跌幅:</span><span class="font-bold ${colorClass}">${sign}${Math.abs(pct).toFixed(2)}%</span></div>`;
        }
    };

    // 3. 渲染年度热力图
    historyCalendarChart.setOption({
        tooltip: commonTooltip,
        visualMap: {
            dimension: 1, show: false, pieces: [
                { min: 0.01, color: '#10b981' },
                { min: -0.01, max: 0.01, color: '#1e293b' },
                { max: -0.01, color: '#f43f5e' }
            ]
        },
        calendar: {
            top: 25, range: currentYear.toString(), cellSize: [16, 16],
            itemStyle: { color: '#0f172a', borderWidth: 3, borderColor: '#020617' },
            dayLabel: { color: '#64748b', nameMap: 'ZH' }, monthLabel: { color: '#64748b', nameMap: 'ZH' }, yearLabel: { show: false }
        },
        series: { type: 'heatmap', coordinateSystem: 'calendar', data: calendarSeriesData, itemStyle: { borderRadius: 4 } }
    }, true);

    // 4. 渲染月度大日历 (强制星期在上方 + 盈亏背景渲染)
    const viewY = currentViewMonthDate.getFullYear(), viewM = currentViewMonthDate.getMonth() + 1;
    document.getElementById('month-view-title').innerText = `${viewY}年 ${viewM}月 资产走势`;
    const mData = calendarSeriesData.filter(i => { const dObj = new Date(i[0]); return dObj.getFullYear() === viewY && (dObj.getMonth() + 1) === viewM; });

    historyMonthChart.setOption({
        tooltip: commonTooltip,
        visualMap: {
            dimension: 1, show: false, pieces: [
                { min: 0.01, color: 'rgba(16, 185, 129, 0.1)' },      // 盈利背景：淡绿
                { min: -0.01, max: 0.01, color: 'rgba(15, 23, 42, 0.5)' }, // 持平背景：暗色
                { max: -0.01, color: 'rgba(244, 63, 94, 0.1)' }      // 亏损背景：淡红
            ]
        },
        calendar: {
            top: 50, left: 20, right: 20, bottom: 20,
            orient: 'vertical', // 🎯 关键：设置为纵向，星期就会出现在上方
            range: `${viewY}-${String(viewM).padStart(2, '0')}`,
            cellSize: ['auto', 'auto'],
            itemStyle: { color: '#0f172a', borderWidth: 1, borderColor: '#1e293b' },
            dayLabel: { color: '#94a3b8', margin: 15, nameMap: 'ZH', fontWeight: 'bold' },
            yearLabel: { show: false }, monthLabel: { show: false }
        },
        series: [
            { type: 'heatmap', coordinateSystem: 'calendar', data: mData },
            {
                type: 'scatter', coordinateSystem: 'calendar', data: mData, symbolSize: 0, silent: true,
                label: {
                    show: true, position: 'inside',
                    formatter: function (p) {
                        const [date, pnl, pct, total, has] = p.value;
                        if (!has && total === 0) return '{empty|--}';
                        const isUp = pnl > 0; const sign = isUp ? '+' : (pnl < 0 ? '-' : '');
                        const color = isUp ? '{up|' : '{down|';
                        return `${color}${sign}${formatMoney(Math.abs(pnl))}}`;
                    },
                    rich: {
                        up: { color: '#34d399', fontSize: 16, fontWeight: '900' },
                        down: { color: '#f43f5e', fontSize: 16, fontWeight: '900' },
                        empty: { color: '#1e293b', fontSize: 14 }
                    }
                }
            },
            // 添加日期角标
            {
                type: 'heatmap', coordinateSystem: 'calendar', data: mData,
                label: {
                    show: true, position: 'insideTopLeft', offset: [10, 10],
                    formatter: (p) => `{date|${new Date(p.value[0]).getDate()}}`,
                    rich: { date: { color: '#64748b', fontSize: 13, fontWeight: 'bold' } }
                },
                itemStyle: { color: 'transparent' }
            }
        ]
    }, true);
}

// ==========================================
// 6. 其他辅助功能
// ==========================================
async function refreshLiveExchangeRate() {
    try {
        const res = await fetch('/api/exchange-rate');
        const data = await res.json();
        if (data.rate) {
            MYR_RATE = parseFloat(data.rate);
            const hint = document.getElementById('rate-hint');
            if (hint) hint.innerText = `全局切换法币计价单位 (当前实时汇率: ${MYR_RATE.toFixed(2)})`;
        }
    } catch (e) { console.warn("汇率加载失败"); }
}

/**
 * 初始化法币切换逻辑 (带持久化与强制刷新)
 */
function initCurrencyToggle() {
    const toggle = document.getElementById('currency-toggle');
    const knob = document.getElementById('currency-toggle-knob');
    if (!toggle || !knob) return;

    // 🌟 A. 页面初始化：根据 localStorage 的状态设置开关的视觉位置
    if (isMYR) {
        toggle.classList.add('bg-sky-500');
        toggle.classList.remove('bg-slate-700');
        knob.classList.add('translate-x-6');
    } else {
        toggle.classList.add('bg-slate-700');
        toggle.classList.remove('bg-sky-500');
        knob.classList.remove('translate-x-6');
    }

    // 🌟 B. 点击处理逻辑
    toggle.onclick = () => {
        isMYR = !isMYR;
        
        // 1. 持久化保存到浏览器
        localStorage.setItem('preferred_currency', isMYR ? 'MYR' : 'USD');

        // 2. 更新开关 UI 动画
        toggle.classList.toggle('bg-sky-500', isMYR);
        toggle.classList.toggle('bg-slate-700', !isMYR);
        knob.classList.toggle('translate-x-6', isMYR);

        // 3. 🎯 核心修复：通知所有页面组件立即使用新汇率重绘
        console.log(`💱 汇率已切换至: ${isMYR ? 'MYR' : 'USD'}`);
        refreshMyChart(); 
    };
}

/**
 * 强制刷新当前页面所有可见的资产数据组件
 */
function refreshMyChart() {
    // 刷新首页金额与卡片
    if (document.getElementById('grid-container')) {
        renderPortfolio(globalPortfolioData);
    }
    
    // 刷新首页走势图
    if (document.getElementById('echarts-container') && globalSnapshotData) {
        renderChart(globalSnapshotData);
    }
    
    // 刷新盈亏历史页的两个日历
    if (document.getElementById('calendar-echarts-container') && globalSnapshotData) {
        renderCalendarHistory(globalSnapshotData);
    }
}

function updateSyncBadgeAndCheckUpdate() {
    fetch('/api/sync-status').then(res => res.json()).then(data => {
        const badge = document.getElementById('sync-badge');
        if (!badge) return;
        if (data.status === 'running') badge.innerText = "◌ 同步中...";
        else {
            const time = data.last_sync.split(' ')[1] || '无';
            badge.innerText = `● SYNC ALIGNED (${time})`;
            if (lastKnownSync && data.last_sync !== lastKnownSync) loadAllData();
            lastKnownSync = data.last_sync;
        }
    });
}

function initAlignedTimer() {
    const ms = 5 * 60 * 1000;
    setTimeout(() => {
        fetch('/api/assets/sync', { method: 'POST' });
        setInterval(() => fetch('/api/assets/sync', { method: 'POST' }), ms);
    }, ms - (Date.now() % ms));
}

window.searchCoinGeckoTracked = (query) => {
    const resDiv = document.getElementById('tracked_search_results');
    if (query.length < 2) return resDiv.classList.add('hidden');
    clearTimeout(trackedSearchTimer);
    trackedSearchTimer = setTimeout(async () => {
        const r = await fetch(`https://api.coingecko.com/api/v3/search?query=${query}`);
        const d = await r.json();
        if (d.coins) {
            resDiv.innerHTML = d.coins.slice(0, 5).map(c => `<div onclick="window.selectTrackedToken('${c.id}', '${c.name}')" class="p-2 hover:bg-slate-800 cursor-pointer text-white text-sm">${c.name} (${c.symbol})</div>`).join('');
            resDiv.classList.remove('hidden');
        }
    }, 300);
};

window.selectTrackedToken = (id, name) => {
    document.getElementById('newTokenId').value = id;
    document.getElementById('search_tracked_input').value = name;
    document.getElementById('tracked_search_results').classList.add('hidden');
};

// --- 补全：提交新增追踪代币 ---
window.submitTrackedToken = async () => {
    const id = document.getElementById('newTokenId').value;
    const name = document.getElementById('search_tracked_input').value;

    if (!id) {
        return alert("请先通过搜索框选择一个代币");
    }

    try {
        const res = await fetch('/api/tracked-tokens', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json', 
                'X-CSRF-TOKEN': getCsrfToken() 
            },
            body: JSON.stringify({ 
                coingecko_id: id, 
                name: name 
            })
        });

        if (res.ok) {
            console.log("✅ 成功添加追踪代币");
            // 清空输入框
            document.getElementById('newTokenId').value = '';
            document.getElementById('search_tracked_input').value = '';
            // 刷新列表
            loadTrackedTokens();
        } else {
            const err = await res.json();
            alert("❌ 添加失败: " + (err.message || "该代币可能已在追踪列表中"));
        }
    } catch (e) {
        console.error("提交追踪代币出错:", e);
    }
};

// --- 补全：删除/停止追踪代币 ---
window.deleteTrackedToken = async (id) => {
    if (!confirm('确定要停止追踪此代币吗？(相关的资产数据可能会受影响)')) return;

    try {
        const res = await fetch(`/api/tracked-tokens/${id}`, {
            method: 'DELETE',
            headers: { 
                'X-CSRF-TOKEN': getCsrfToken() 
            }
        });

        if (res.ok) {
            console.log("🗑️ 已停止追踪");
            loadTrackedTokens();
        }
    } catch (e) {
        console.error("删除追踪代币出错:", e);
    }
};