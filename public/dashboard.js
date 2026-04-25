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
let globalStats = null;
let globalCategories = null;
let myChart = null;
let historyCalendarChart = null;
let historyMonthChart = null;
let currentRange = '1D';
let lastKnownSync = null;
let currentViewMonthDate = new Date();
let trackedSearchTimer = null;
let currentBreakdownView = 'wallets';
let categoryDragContext = null;

// CSRF Token 工具函数
const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

// --- 1. 全局工具函数 (置顶定义，确保全局可用) ---

/**
 * 格式化金额：自动识别 USD 或 MYR
 */
// public/dashboard.js
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
 * 🎯 新增：图表专用格式化（由于图表数据已在传入前转换过汇率，这里只负责加符号和保留2位小数）
 */
function formatChartMoney(value) {
    const val = parseFloat(value) || 0;
    const prefix = isMYR ? 'RM ' : '$';
    return prefix + val.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatChartTimestamp(value, granularity = '5m', detailed = false) {
    const date = new Date(value);
    const pad = (number) => String(number).padStart(2, '0');
    const year = date.getFullYear();
    const month = pad(date.getMonth() + 1);
    const day = pad(date.getDate());
    const hours = pad(date.getHours());
    const minutes = pad(date.getMinutes());

    if (granularity === 'day') {
        return detailed ? `${year}-${month}-${day} 00:00` : `${month}-${day}`;
    }

    if (granularity === 'hour') {
        return detailed ? `${year}-${month}-${day} ${hours}:00` : `${month}-${day} ${hours}:00`;
    }

    return detailed ? `${year}-${month}-${day} ${hours}:${minutes}` : `${hours}:${minutes}`;
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

    // 🎯 核心改进：后台预加载所有主要数据，不用等待用户进入页面
    Promise.all([
        loadAllData().catch(e => console.error("投资组合数据加载失败:", e)),
        loadHistoryData().catch(e => console.error("历史日历数据加载失败:", e)),
        loadCategories().catch(e => console.error("类别数据加载失败:", e)),
        loadTrackedTokens().catch(e => console.error("追踪代币加载失败:", e)),
        loadWallets().catch(e => console.error("钱包加载失败:", e)),
        loadExchangeAccounts().catch(e => console.error("交易所账户加载失败:", e))
    ]).then(() => {
        console.log("✅ 所有后台数据预加载完毕");
    });

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
    document.getElementById('addAssetForm')?.reset();
    const modal = document.getElementById('addAssetModal');
    modal.classList.remove('hidden');
    setTimeout(() => modal.classList.add('opacity-100'), 10);
};

window.closeAddModal = () => {
    const modal = document.getElementById('addAssetModal');
    modal.classList.remove('opacity-100');
    setTimeout(() => modal.classList.add('hidden'), 300);
};

window.openEditModal = (id, amount, symbol, network, source, label, labelId = '', isAutoSynced = false) => {
    if (String(isAutoSynced) === 'true' || isAutoSynced === true) {
        alert('自动同步资产不支持手动编辑，请在设置页调整 API 账户后重新同步。');
        return;
    }

    document.getElementById('edit_asset_id').value = id;
    document.getElementById('edit_token_amount').value = amount;
    document.getElementById('edit_network').value = network;
    document.getElementById('edit_source_name').value = source;
    document.getElementById('edit_label').value = label || '';
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

// --- 搜索代币 ---
window.searchToken = async (query) => {
    const resDiv = document.getElementById('token_suggestions');
    if (query.length < 1) return resDiv.classList.add('hidden');
    try {
        const tokens = await fetch('/api/tracked-tokens').then(res => res.json());
        const filtered = tokens.filter(t => t.name.toLowerCase().includes(query.toLowerCase()) || t.symbol.toLowerCase().includes(query.toLowerCase())).slice(0, 5);
        if (filtered.length > 0) {
            resDiv.innerHTML = filtered.map(t => `<div onclick="selectToken('${t.coingecko_id}', '${t.name}')" class="p-2 hover:bg-slate-800 cursor-pointer text-white text-sm">${t.name} (${t.symbol})</div>`).join('');
            resDiv.classList.remove('hidden');
        } else {
            resDiv.classList.add('hidden');
        }
    } catch (e) {
        console.error('搜索代币失败', e);
    }
};

window.selectToken = (id, name) => {
    document.getElementById('add_coingecko_id').value = id;
    document.getElementById('add_token_name').value = name;
    document.getElementById('add_token_search').value = name;
    document.getElementById('token_suggestions').classList.add('hidden');
};

// --- 搜索来源 ---
window.searchSource = async (query) => {
    const resDiv = document.getElementById('source_suggestions');
    if (query.length < 1) return resDiv.classList.add('hidden');
    try {
        const wallets = await fetch('/api/wallets').then(res => res.json());
        const filtered = wallets.filter(w => w.name.toLowerCase().includes(query.toLowerCase())).slice(0, 5);
        if (filtered.length > 0) {
            resDiv.innerHTML = filtered.map(w => `<div onclick="selectSource('${w.name}')" class="p-2 hover:bg-slate-800 cursor-pointer text-white text-sm">${w.name}</div>`).join('');
            resDiv.classList.remove('hidden');
        } else {
            resDiv.classList.add('hidden');
        }
    } catch (e) {
        console.error('搜索来源失败', e);
    }
};

window.selectSource = (name) => {
    document.getElementById('add_source_name').value = name;
    document.getElementById('source_suggestions').classList.add('hidden');
};

// --- 操作函数 ---
window.changeRange = async (range) => {
    currentRange = range;
    const btns = document.querySelectorAll('.range-btn');
    btns.forEach(b => b.classList.toggle('bg-sky-500', b.innerText === range));
    btns.forEach(b => b.classList.toggle('text-white', b.innerText === range));
    
    // 🎯 不需要清除缓存，每个时间范围有独立的缓存键
    await loadAllData();
};

// --- 补全：提交资产修改 ---
window.submitEditAsset = async (event) => {
    event.preventDefault(); // 阻止表单默认提交行为

    const id = document.getElementById('edit_asset_id').value;
    const amount = document.getElementById('edit_token_amount').value;
    const network = document.getElementById('edit_network').value;
    const source = document.getElementById('edit_source_name').value;
    const label = document.getElementById('edit_label').value;

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
                source_name: source,
                label: label
            })
        });

        if (res.ok) {
            console.log("✅ 资产数据更新成功");
            window.closeEditModal(); // 关闭弹窗
            // 🎯 清除所有缓存以重新获取最新数据
            CacheManager.clear('portfolioData');
            CacheManager.clearAllSnapshotCache();
            CacheManager.clear('statsData');
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
    const source_name = document.getElementById('add_source_name').value;
    const token_name = document.getElementById('add_token_name').value;
    const coingecko_id = document.getElementById('add_coingecko_id').value;
    const token_amount = document.getElementById('add_token_amount').value;
    const network = document.getElementById('add_network').value;
    const label = document.getElementById('add_label').value;

    if (!source_name || !token_name || !coingecko_id || !token_amount || !network) {
        alert('请填写所有必填字段');
        return;
    }

    const res = await fetch('/api/assets', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ source_name, token_name, coingecko_id, token_amount: parseFloat(token_amount), network, label })
    });
    if (res.ok) { 
        window.closeAddModal(); 
        // 🎯 清除所有缓存以重新获取最新数据
        CacheManager.clear('portfolioData');
        CacheManager.clearAllSnapshotCache();
        CacheManager.clear('statsData');
        await loadAllData(); 
    }
    else { alert('添加失败'); }
};

window.deleteAsset = async (id) => {
    if (!confirm('确认从看板移除此资产？')) return;
    const res = await fetch(`/api/assets/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() }
    });
    if (res.ok) {
        // 🎯 清除所有缓存以重新获取最新数据
        CacheManager.clear('portfolioData');
        CacheManager.clearAllSnapshotCache();
        CacheManager.clear('statsData');
        await loadAllData();
    }
};

window.deleteCexAsset = async (id) => {
    if (!confirm('确认移除此自动同步资产？此操作仅删除当前记录，不会删除交易所账户。')) return;
    const res = await fetch(`/api/cex/assets/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() }
    });

    if (res.ok) {
        CacheManager.clear('portfolioData');
        await loadAllData();
    } else {
        const err = await res.json().catch(() => ({}));
        alert(err.message || '删除失败');
    }
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
    if (res.ok) { 
        document.getElementById('newWalletName').value = ''; 
        // 🎯 清除缓存
        CacheManager.clear('wallets');
        loadWallets(); 
    }
};

window.deleteWallet = async (id) => {
    if (!confirm('确定删除此钱包？')) return;
    await fetch(`/api/wallets/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': getCsrfToken() } });
    // 🎯 清除缓存
    CacheManager.clear('wallets');
    loadWallets();
};

async function loadExchangeAccounts() {
    const cachedAccounts = CacheManager.get('exchangeAccounts');
    const list = document.getElementById('exchange-accounts-list');
    if (list && cachedAccounts && Array.isArray(cachedAccounts)) {
        renderExchangeAccounts(cachedAccounts, list);
    }

    try {
        const res = await fetch('/api/exchange-accounts');
        const accounts = await res.json();
        if (!list) return;

        CacheManager.set('exchangeAccounts', accounts);
        renderExchangeAccounts(accounts, list);
    } catch (e) {
        console.error('交易所账户加载失败', e);
    }
}

function renderExchangeAccounts(accounts, list) {
    if (!accounts || accounts.length === 0) {
        list.innerHTML = '<tr><td colspan="7" class="px-6 py-6 text-center text-slate-500 text-sm">尚未添加交易所 API 账户</td></tr>';
        return;
    }

    list.innerHTML = accounts.map((acc) => {
        const statusColor = acc.last_sync_status === 'success' ? 'text-emerald-400' : (acc.last_sync_status === 'error' ? 'text-rose-400' : 'text-slate-400');
        const lastError = (acc.last_error || '').trim();
        const errorText = lastError ? lastError : '-';
        return `
            <tr class="hover:bg-slate-800/30">
                <td class="px-6 py-4 text-sm text-white uppercase">${acc.exchange}</td>
                <td class="px-6 py-4 text-sm text-white">${acc.label}</td>
                <td class="px-6 py-4 text-sm text-slate-300 font-mono">${acc.api_key_masked || '-'}</td>
                <td class="px-6 py-4 text-sm ${statusColor}">${acc.enabled ? '启用' : '停用'} / ${acc.last_sync_status || 'idle'}</td>
                <td class="px-6 py-4 text-sm text-slate-500">${acc.last_sync_at || '-'}</td>
                <td class="px-6 py-4 text-sm text-slate-500 max-w-[240px] truncate" title="${errorText.replace(/"/g, '&quot;')}">${errorText}</td>
                <td class="px-6 py-4 text-right">
                    <button onclick="window.triggerCexSync('${acc.exchange}')" class="text-sky-400 mr-4">同步</button>
                    <button onclick="window.toggleExchangeAccount('${acc.id}', ${acc.enabled ? 'false' : 'true'})" class="text-amber-400 mr-4">${acc.enabled ? '停用' : '启用'}</button>
                    <button onclick="window.deleteExchangeAccount('${acc.id}')" class="text-red-500">删除</button>
                </td>
            </tr>`;
    }).join('');
}

window.submitExchangeAccount = async () => {
    const exchange = document.getElementById('newExchangeName')?.value
        || document.getElementById('exchangeAccountExchange')?.value
        || 'okx';
    const label = document.getElementById('newExchangeLabel')?.value
        || document.getElementById('exchangeAccountLabel')?.value
        || '';
    const api_key = document.getElementById('newExchangeApiKey')?.value
        || document.getElementById('exchangeApiKey')?.value
        || '';
    const api_secret = document.getElementById('newExchangeApiSecret')?.value
        || document.getElementById('exchangeApiSecret')?.value
        || '';
    const passphrase = document.getElementById('newExchangePassphrase')?.value
        || document.getElementById('exchangePassphrase')?.value
        || '';
    const enabled = !!(
        document.getElementById('newExchangeEnabled')?.checked
        ?? document.getElementById('exchangeEnabled')?.checked
    );

    if (!label || !api_key || !api_secret) {
        alert('请填写账户标签、API Key 和 API Secret');
        return;
    }

    const res = await fetch('/api/exchange-accounts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ exchange, label, api_key, api_secret, passphrase, enabled })
    });

    if (res.ok) {
        CacheManager.clear('exchangeAccounts');
        const idsToClear = [
            'newExchangeLabel',
            'newExchangeApiKey',
            'newExchangeApiSecret',
            'newExchangePassphrase',
            'exchangeAccountLabel',
            'exchangeApiKey',
            'exchangeApiSecret',
            'exchangePassphrase',
        ];
        idsToClear.forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        await loadExchangeAccounts();
        alert('交易所账户已保存');
    } else {
        const err = await res.json().catch(() => ({}));
        alert(err.message || '保存失败');
    }
};

window.deleteExchangeAccount = async (id) => {
    if (!confirm('确定删除这个交易所账户？')) return;
    const res = await fetch(`/api/exchange-accounts/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() }
    });
    if (res.ok) {
        CacheManager.clear('exchangeAccounts');
        await loadExchangeAccounts();
        CacheManager.clear('portfolioData');
        await loadAllData();
    }
};

window.toggleExchangeAccount = async (id, enabled) => {
    const res = await fetch(`/api/exchange-accounts/${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ enabled })
    });

    if (res.ok) {
        CacheManager.clear('exchangeAccounts');
        await loadExchangeAccounts();
    }
};

window.triggerCexSync = async (exchange = '') => {
    const res = await fetch('/api/cex/sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify(exchange ? { exchange } : {})
    });

    if (res.ok) {
        CacheManager.clear('exchangeAccounts');
        await loadExchangeAccounts();
        CacheManager.clear('portfolioData');
        await loadAllData();
        alert('交易所资产同步已触发');
    } else {
        const err = await res.json().catch(() => ({}));
        alert(err.message || '同步触发失败');
    }
};

window.triggerManualSync = async () => {
    const btn = document.getElementById('manual-sync-btn');
    const text = document.getElementById('sync-text');
    if (btn) btn.disabled = true;
    if (text) text.innerText = '同步中...';

    try {
        const res = await fetch('/api/assets/sync', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': getCsrfToken() }
        });
        if (!res.ok) throw new Error('sync_failed');

        CacheManager.clear('portfolioData');
        CacheManager.clearAllSnapshotCache();
        CacheManager.clear('statsData');
        CacheManager.clear('exchangeAccounts');
        await loadAllData();
        await loadExchangeAccounts();
    } catch (e) {
        alert('同步失败，请稍后重试');
    } finally {
        if (btn) btn.disabled = false;
        if (text) text.innerText = '立即同步价格';
    }
};

window.dangerAction = async (type) => {
    const word = type === 'wipe' ? 'WIPE' : 'DELETE';
    if (prompt(`⚠️ 危险操作！请输入 ${word} 确认：`) !== word) return;
    const urls = { 'snapshots': '/api/danger/snapshots', 'assets': '/api/danger/assets', 'capital': '/api/capital/clear', 'wipe': '/api/danger/wipe' };
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

/**
 * 缓存管理工具类
 */
const CacheManager = {
    set: (key, value) => {
        try {
            sessionStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            console.warn('❌ 缓存保存失败:', e);
        }
    },
    get: (key) => {
        try {
            const item = sessionStorage.getItem(key);
            return item ? JSON.parse(item) : null;
        } catch (e) {
            console.warn('❌ 缓存读取失败:', e);
            return null;
        }
    },
    clear: (key) => {
        sessionStorage.removeItem(key);
    },
    // 🎯 新增：清除所有时间范围的快照缓存
    clearAllSnapshotCache: () => {
        ['1D', '7D', '30D', 'ALL'].forEach(range => {
            CacheManager.clear(`snapshotData_${range}`);
        });
    }
};

async function loadAllData() {
    // 🎯 第一步：立即从缓存加载并渲染（如果有缓存）
    // 为每个时间范围使用独立的缓存键
    const snapshotCacheKey = `snapshotData_${currentRange}`;
    const cachedPortfolioData = CacheManager.get('portfolioData');
    const cachedSnapshotData = CacheManager.get(snapshotCacheKey);
    const cachedStats = CacheManager.get('statsData');

    if (cachedPortfolioData && cachedSnapshotData && cachedStats) {
        console.log(`📦 使用缓存的${currentRange}时间范围数据`);
        globalPortfolioData = cachedPortfolioData;
        globalSnapshotData = cachedSnapshotData;
        globalStats = cachedStats;
        renderPortfolio(globalPortfolioData);
        renderChart(globalSnapshotData);
        calculateROI(globalPortfolioData.value, globalStats);
    }

    // 🎯 第二步：后台更新数据
    try {
        const [mapRes, snapRes, statRes] = await Promise.all([
            fetch('/api/assets/thinking-map').then(r => r.json()),
            fetch(`/api/assets/snapshots?range=${currentRange}`).then(r => r.json()),
            fetch('/api/portfolio-stats').then(r => r.json())
        ]);

        console.log('📊 API 数据加载成功:', { mapRes, snapRes, statRes });

        globalPortfolioData = mapRes;
        globalSnapshotData = snapRes;
        globalStats = statRes;

        // 保存到缓存（使用时间范围特定的键）
        CacheManager.set('portfolioData', mapRes);
        CacheManager.set(snapshotCacheKey, snapRes);
        CacheManager.set('statsData', statRes);

        renderPortfolio(globalPortfolioData);
        renderChart(globalSnapshotData);

        // 🎯 计算并显示 ROI
        calculateROI(globalPortfolioData.value, globalStats);
    } catch (e) { 
        console.error("💥 数据加载失败:", e);
        console.error("🔍 请检查浏览器 Network 标签，看各 API 返回的数据");
    }
}

// 🎯 新增的算 ROI 的函数
function calculateROI(currentValueUSD, stats) {
    const { net_invested } = stats;
    const badge = document.getElementById('roi-badge');
    const valueElem = document.getElementById('roi-value');

    console.log("📊 ROI 计算调试:", { currentValueUSD, net_invested, isMYR, MYR_RATE });

    if (!badge) {
        console.warn("⚠️ ROI badge 元素未找到");
        return;
    }

    // 如果还没入金，隐藏 ROI
    if (!net_invested || net_invested <= 0) {
        console.log("ℹ️ 净投入为 0 或负数，隐藏 ROI badge");
        badge.classList.add('hidden');
        return;
    }

    // 🎯 核心修复：统一币种后再计算
    let currentValueInInvestedCurrency;
    let netInvestedInSameCurrency;

    if (isMYR) {
        // 当显示 MYR 时：把资产(USD) 转回 MYR，对标本金(MYR)
        currentValueInInvestedCurrency = currentValueUSD * MYR_RATE;  // 转成 MYR
        netInvestedInSameCurrency = net_invested;  // 本金已经是 MYR
    } else {
        // 当显示 USD 时：把本金(MYR) 转成 USD，对标资产(USD)
        currentValueInInvestedCurrency = currentValueUSD;  // 资产已经是 USD
        netInvestedInSameCurrency = net_invested / MYR_RATE;  // 本金转成 USD
    }

    // 利润 = 当前总资产 - 净本金（两者币种统一）
    const profit = currentValueInInvestedCurrency - netInvestedInSameCurrency;
    const roi = (profit / netInvestedInSameCurrency) * 100;

    console.log("✅ ROI 计算成功:", { currentValueInInvestedCurrency, netInvestedInSameCurrency, profit, roi: roi.toFixed(2) + '%' });

    valueElem.innerText = `${roi > 0 ? '+' : ''}${roi.toFixed(2)}%`;
    badge.classList.remove('hidden', 'bg-emerald-500/20', 'text-emerald-500', 'bg-rose-500/20', 'text-rose-500');

    // 涨跌变色
    if (roi >= 0) {
        badge.classList.add('bg-emerald-500/20', 'text-emerald-500');
    } else {
        badge.classList.add('bg-rose-500/20', 'text-rose-500');
    }
}

async function loadHistoryData() {
    // 🎯 第一步：立即从缓存加载并渲染（如果有缓存）
    // 历史数据使用 ALL 范围的缓存键
    const cachedHistoryData = CacheManager.get('snapshotData_ALL');
    if (cachedHistoryData) {
        console.log('📦 使用缓存的历史日历数据');
        globalSnapshotData = cachedHistoryData;
        renderCalendarHistory(globalSnapshotData);
    }

    // 🎯 第二步：后台更新数据
    try {
        const res = await fetch('/api/assets/snapshots?range=ALL');
        const data = await res.json();
        globalSnapshotData = data;
        CacheManager.set('snapshotData_ALL', data);
        renderCalendarHistory(globalSnapshotData);
    } catch (e) { 
        console.error("历史快照加载失败", e); 
    }
}

async function loadTrackedTokens() {
    // 🎯 第一步：立即从缓存加载并渲染（如果有缓存）
    const cachedTokens = CacheManager.get('trackedTokens');
    if (cachedTokens && Array.isArray(cachedTokens)) {
        console.log('📦 使用缓存的追踪代币数据');
        const list = document.getElementById('tracked-tokens-list');
        if (list) {
            list.innerHTML = cachedTokens.map(t => {
                const rawId = (t._id && (t._id.$oid || t._id)) || t.id || t.coingecko_id;
                const id = rawId && typeof rawId === 'object' ? (rawId.$oid || rawId.toString()) : rawId;
                return `
                <tr class="hover:bg-slate-800/30">
                    <td class="px-6 py-4 text-sm text-white">${t.name}</td>
                    <td class="px-6 py-4 text-sm text-slate-500 font-mono">${t.coingecko_id}</td>
                    <td class="px-6 py-4 text-right">
                        <button onclick="window.deleteTrackedToken('${id}')" class="text-red-500">停止</button>
                    </td>
                </tr>`;
            }).join('');
        }
    }

    // 🎯 第二步：后台更新数据
    try {
        const res = await fetch('/api/tracked-tokens');
        const tokens = await res.json();
        CacheManager.set('trackedTokens', tokens);
        const list = document.getElementById('tracked-tokens-list');
        if (!list) return;

        list.innerHTML = tokens.map(t => {
            const rawId = (t._id && (t._id.$oid || t._id)) || t.id || t.coingecko_id;
            const id = rawId && typeof rawId === 'object' ? (rawId.$oid || rawId.toString()) : rawId;
            return `
            <tr class="hover:bg-slate-800/30">
                <td class="px-6 py-4 text-sm text-white">${t.name}</td>
                <td class="px-6 py-4 text-sm text-slate-500 font-mono">${t.coingecko_id}</td>
                <td class="px-6 py-4 text-right">
                    <button onclick="window.deleteTrackedToken('${id}')" class="text-red-500">停止</button>
                </td>
            </tr>`;
        }).join('');
    } catch (e) {
        console.error("追踪代币加载失败", e);
    }
}

async function loadWallets() {
    // 🎯 第一步：立即从缓存加载并渲染（如果有缓存）
    const cachedWallets = CacheManager.get('wallets');
    if (cachedWallets && Array.isArray(cachedWallets)) {
        console.log('📦 使用缓存的钱包数据');
        const list = document.getElementById('wallets-list');
        if (list) {
            list.innerHTML = cachedWallets.map(w => {
                const rawId = (w._id && (w._id.$oid || w._id)) || w.id;
                const id = rawId && typeof rawId === 'object' ? (rawId.$oid || rawId.toString()) : rawId;
                return `
                <tr class="hover:bg-slate-800/30">
                    <td class="px-6 py-4 text-sm text-white">${w.name}</td>
                    <td class="px-6 py-4 text-sm text-slate-500">${w.type}</td>
                    <td class="px-6 py-4 text-right">
                        <button onclick="window.deleteWallet('${id}')" class="text-red-500">删除</button>
                    </td>
                </tr>`;
            }).join('');
        }
    }

    // 🎯 第二步：后台更新数据
    try {
        const res = await fetch('/api/wallets');
        const wallets = await res.json();
        CacheManager.set('wallets', wallets);
        const list = document.getElementById('wallets-list');
        if (!list) return;

        list.innerHTML = wallets.map(w => {
            const rawId = (w._id && (w._id.$oid || w._id)) || w.id;
            const id = rawId && typeof rawId === 'object' ? (rawId.$oid || rawId.toString()) : rawId;
            return `
            <tr class="hover:bg-slate-800/30">
                <td class="px-6 py-4 text-sm text-white">${w.name}</td>
                <td class="px-6 py-4 text-sm text-slate-500">${w.type}</td>
                <td class="px-6 py-4 text-right">
                    <button onclick="window.deleteWallet('${id}')" class="text-red-500">删除</button>
                </td>
            </tr>`;
        }).join('');
    } catch (e) {
        console.error("钱包数据加载失败", e);
    }
}

function normalizeCategoryId(raw) {
    if (!raw) return '';
    if (typeof raw === 'string' || typeof raw === 'number') return String(raw);
    if (typeof raw === 'object') {
        if (raw.id) return String(raw.id);
        if (raw.$oid) return String(raw.$oid);
        if (raw._id) return normalizeCategoryId(raw._id);
        if (typeof raw.toString === 'function' && raw.toString !== Object.prototype.toString) {
            return String(raw.toString());
        }
    }
    return '';
}

function normalizeSymbolList(text) {
    return String(text || '')
        .split(',')
        .map(s => s.trim().toUpperCase())
        .filter(Boolean)
        .filter((symbol, index, arr) => arr.indexOf(symbol) === index);
}

function renderCategorySettingsList(categories) {
    const list = document.getElementById('asset-categories-list');
    if (!list) return;

    if (!categories || categories.length === 0) {
        list.innerHTML = `
            <tr>
                <td colspan="3" class="px-6 py-6 text-center text-slate-500 text-sm">暂无类别，请先创建一个类别</td>
            </tr>`;
        return;
    }

    list.innerHTML = categories.map((category) => {
        const name = category.name || '';
        const id = normalizeCategoryId(category.id || category._id);
        const encodedId = encodeURIComponent(id);
        const targetPct = Number(category.target_pct || 0).toFixed(2);
        return `
            <tr class="hover:bg-slate-800/30">
                <td class="px-6 py-4 text-sm text-white">${name}</td>
                <td class="px-6 py-4 text-right">
                    <input type="number" min="0" step="0.1" value="${targetPct}" id="category-target-${encodedId}"
                        class="w-28 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-right text-sm text-white" />
                </td>
                <td class="px-6 py-4 text-right">
                    <button type="button" onclick="window.saveCategoryTargetPct(decodeURIComponent('${encodedId}'), 'category-target-${encodedId}')" class="text-sky-400 mr-4">保存占比</button>
                    <button type="button" onclick="window.deleteCategory(decodeURIComponent('${encodedId}'))" class="text-red-500">删除</button>
                </td>
            </tr>`;
    }).join('');
}

function syncCategoryControls() {
    renderCategorySettingsList(globalCategories || []);
}

async function loadCategories() {
    const cachedCategories = CacheManager.get('assetCategories');
    if (cachedCategories && Array.isArray(cachedCategories)) {
        globalCategories = cachedCategories;
        syncCategoryControls();
    }

    try {
        const res = await fetch('/api/asset-categories');
        const categories = await res.json();
        globalCategories = categories;
        CacheManager.set('assetCategories', categories);
        syncCategoryControls();

        if (globalPortfolioData && currentBreakdownView === 'categories') {
            renderPortfolio(globalPortfolioData);
        }
    } catch (e) {
        console.error('类别数据加载失败', e);
    }
}

window.submitCategory = async () => {
    const input = document.getElementById('newCategoryName') || document.getElementById('category-name-dashboard');
    const targetInput = document.getElementById('newCategoryTargetPct') || document.getElementById('category-target-pct-dashboard');
    const name = input ? input.value.trim() : '';
    if (!name) return alert('请输入类别名称');
    const targetPct = targetInput ? (Number(targetInput.value) || 0) : 0;

    try {
        const res = await fetch('/api/asset-categories', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken()
            },
            body: JSON.stringify({ name, target_pct: targetPct })
        });

        if (res.ok) {
            if (input) input.value = '';
            if (targetInput) targetInput.value = '0';
            CacheManager.clear('assetCategories');
            await loadCategories();
        } else {
            const err = await res.json().catch(() => ({}));
            alert(err.message || '创建类别失败');
        }
    } catch (e) {
        console.error('创建类别失败', e);
        alert('创建类别失败');
    }
};

function getCategoryById(categoryId) {
    const normalizedId = normalizeCategoryId(categoryId);
    if (!normalizedId) return null;
    return (globalCategories || []).find((category) => {
        return normalizeCategoryId(category.id || category._id) === normalizedId;
    }) || null;
}

function getPortfolioSymbols(data) {
    const symbolSet = new Set();
    (data?.children || []).forEach((source) => {
        (source.children || []).forEach((net) => {
            (net.children || []).forEach((token) => {
                const symbol = String(token.symbol || token.name || '').trim().toUpperCase();
                if (symbol) symbolSet.add(symbol);
            });
        });
    });
    return Array.from(symbolSet.values()).sort();
}

function getUncategorizedSymbols(data) {
    const allSymbols = getPortfolioSymbols(data);
    const assignedSymbols = new Set();

    (globalCategories || []).forEach((category) => {
        (Array.isArray(category.symbols) ? category.symbols : []).forEach((symbol) => {
            const normalized = String(symbol || '').trim().toUpperCase();
            if (normalized) assignedSymbols.add(normalized);
        });
    });

    return allSymbols.filter((symbol) => !assignedSymbols.has(symbol));
}

async function updateCategorySymbols(categoryId, symbols, targetPct) {
    const normalizedId = normalizeCategoryId(categoryId);
    if (!normalizedId) {
        alert('保存失败：无效的类别 ID');
        return;
    }

    const shouldUpdateSymbols = typeof symbols !== 'undefined';
    const normalizedSymbols = shouldUpdateSymbols ? normalizeSymbolList(Array.isArray(symbols) ? symbols.join(',') : symbols) : undefined;
    const shouldUpdateTargetPct = typeof targetPct !== 'undefined';
    const normalizedTargetPct = shouldUpdateTargetPct ? Math.max(0, Number(targetPct) || 0) : undefined;

    const payload = {};
    if (shouldUpdateSymbols) payload.symbols = normalizedSymbols;
    if (shouldUpdateTargetPct) payload.target_pct = normalizedTargetPct;

    try {
        const res = await fetch(`/api/asset-categories/${encodeURIComponent(normalizedId)}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken()
            },
            body: JSON.stringify(payload)
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alert(err.message || '保存币种失败');
            return;
        }

        const target = getCategoryById(normalizedId);
        if (target) {
            if (shouldUpdateSymbols) target.symbols = normalizedSymbols;
            if (shouldUpdateTargetPct) target.target_pct = normalizedTargetPct;
        }

        CacheManager.set('assetCategories', globalCategories || []);
        syncCategoryControls();
        if (globalPortfolioData && currentBreakdownView === 'categories') {
            renderPortfolio(globalPortfolioData);
        }
    } catch (e) {
        console.error('保存币种失败', e);
        alert('保存币种失败');
    }
}

window.saveCategorySymbols = async (id, inputId) => {
    const input = document.getElementById(inputId);
    const symbols = normalizeSymbolList(input ? input.value : '');
    await updateCategorySymbols(id, symbols);
};

window.saveCategoryTargetPct = async (id, inputId) => {
    const input = document.getElementById(inputId);
    const targetPct = Number(input ? input.value : 0) || 0;
    await updateCategorySymbols(id, undefined, targetPct);
};

window.addSymbolToCategoryByInput = async (id, inputId) => {
    const category = getCategoryById(id);
    const input = document.getElementById(inputId);
    const symbol = String(input?.value || '').trim().toUpperCase();
    if (!category || !symbol) return;
    const nextSymbols = Array.isArray(category.symbols) ? [...category.symbols] : [];
    if (!nextSymbols.includes(symbol)) {
        nextSymbols.push(symbol);
    }
    await updateCategorySymbols(id, nextSymbols);
    if (input) input.value = '';
};

window.removeSymbolFromCategory = async (id, symbol) => {
    const category = getCategoryById(id);
    if (!category) return;
    const nextSymbols = (Array.isArray(category.symbols) ? category.symbols : []).filter((s) => s !== symbol);
    await updateCategorySymbols(id, nextSymbols);
};

window.onCategorySymbolDragStart = (event, symbol) => {
    const normalizedSymbol = String(symbol || '').toUpperCase();
    categoryDragContext = {
        symbol: normalizedSymbol,
        sourceCategoryId: ''
    };
    if (!event || !event.dataTransfer) return;
    event.dataTransfer.setData('text/plain', normalizedSymbol);
    event.dataTransfer.setData('application/x-source-category-id', '');
    event.dataTransfer.effectAllowed = 'copy';
    event.target && event.target.addEventListener('dragend', () => { categoryDragContext = null; }, { once: true });
};

window.onCategoryTagDragStart = (event, symbol, sourceCategoryId) => {
    const normalizedSymbol = String(symbol || '').toUpperCase();
    const normalizedSourceCategoryId = String(sourceCategoryId || '');
    categoryDragContext = {
        symbol: normalizedSymbol,
        sourceCategoryId: normalizedSourceCategoryId
    };
    if (!event || !event.dataTransfer) return;
    event.dataTransfer.setData('text/plain', normalizedSymbol);
    event.dataTransfer.setData('application/x-source-category-id', normalizedSourceCategoryId);
    event.dataTransfer.effectAllowed = 'move';
    event.target && event.target.addEventListener('dragend', () => { categoryDragContext = null; }, { once: true });
};

window.onCategoryDragOver = (event) => {
    if (!event) return;
    event.preventDefault();
    if (event.dataTransfer) {
        const sourceCategoryId = String(
            event.dataTransfer.getData('application/x-source-category-id') ||
            categoryDragContext?.sourceCategoryId ||
            ''
        );

        // Dragging from a category tag means "move"; dragging from pool means "copy"
        event.dataTransfer.dropEffect = sourceCategoryId ? 'move' : 'copy';
    }
};

async function moveSymbolBetweenCategories(sourceCategoryId, targetCategoryId, symbol) {
    const sourceId = normalizeCategoryId(sourceCategoryId);
    const targetId = normalizeCategoryId(targetCategoryId);
    const normalizedSymbol = String(symbol || '').trim().toUpperCase();
    if (!normalizedSymbol || !targetId) return;

    if (!sourceId) {
        const targetCategory = getCategoryById(targetId);
        if (!targetCategory) return;
        const nextSymbols = Array.isArray(targetCategory.symbols) ? [...targetCategory.symbols] : [];
        if (!nextSymbols.includes(normalizedSymbol)) {
            nextSymbols.push(normalizedSymbol);
            await updateCategorySymbols(targetId, nextSymbols);
        }
        return;
    }

    if (sourceId === targetId) return;

    const sourceCategory = getCategoryById(sourceId);
    const targetCategory = getCategoryById(targetId);
    if (!sourceCategory || !targetCategory) return;

    const sourceSymbols = (Array.isArray(sourceCategory.symbols) ? sourceCategory.symbols : []).filter((s) => s !== normalizedSymbol);
    const targetSymbols = Array.isArray(targetCategory.symbols) ? [...targetCategory.symbols] : [];
    if (!targetSymbols.includes(normalizedSymbol)) {
        targetSymbols.push(normalizedSymbol);
    }

    await updateCategorySymbols(sourceId, sourceSymbols);
    await updateCategorySymbols(targetId, targetSymbols);
}

window.onCategoryDrop = async (event, id) => {
    if (!event) return;
    event.preventDefault();
    const symbol = String(event.dataTransfer?.getData('text/plain') || categoryDragContext?.symbol || '').trim().toUpperCase();
    const sourceCategoryId = String(event.dataTransfer?.getData('application/x-source-category-id') || categoryDragContext?.sourceCategoryId || '');
    if (!symbol) return;
    await moveSymbolBetweenCategories(sourceCategoryId, id, symbol);
    categoryDragContext = null;
};

window.onPoolDrop = async (event) => {
    if (!event) return;
    event.preventDefault();
    const symbol = String(event.dataTransfer?.getData('text/plain') || categoryDragContext?.symbol || '').trim().toUpperCase();
    const sourceCategoryId = String(event.dataTransfer?.getData('application/x-source-category-id') || categoryDragContext?.sourceCategoryId || '');
    if (!symbol || !sourceCategoryId) return;

    const sourceCategory = getCategoryById(sourceCategoryId);
    if (!sourceCategory) return;
    const sourceSymbols = (Array.isArray(sourceCategory.symbols) ? sourceCategory.symbols : []).filter((s) => s !== symbol);
    await updateCategorySymbols(sourceCategoryId, sourceSymbols);
    categoryDragContext = null;
};

window.deleteCategory = async (id) => {
    if (!confirm('确定删除这个类别吗？该类别下的资产会变为未分类。')) return;

    const categoryId = normalizeCategoryId(id);
    if (!categoryId) {
        console.error('删除类别失败: 无效的类别 ID', id);
        alert('删除类别失败：无效的类别 ID');
        return;
    }

    try {
        const res = await fetch(`/api/asset-categories/${encodeURIComponent(categoryId)}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': getCsrfToken() }
        });

        if (res.ok) {
            CacheManager.clear('assetCategories');
            await loadCategories();
            await loadAllData();
        }
    } catch (e) {
        console.error('删除类别失败', e);
    }
};

// ==========================================
// 5. 绘图与渲染引擎
// ==========================================
function renderPortfolio(data) {
    const container = document.getElementById('grid-container');
    const totalElem = document.getElementById('total-value');
    if (!container || !data) return;

    totalElem.innerText = formatMoney(data.value || 0);
    container.innerHTML = '';

    const switchCard = document.createElement('div');
    switchCard.className = 'bento-card full-row-card';
    switchCard.innerHTML = `
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h3 class="text-white text-xl font-semibold tracking-tight">Portfolio Breakdown</h3>
                <p class="text-slate-400 text-xs md:text-sm mt-1">选择更适合当前分析的视图：钱包便当盒、资产占比表或类别占比表。</p>
            </div>
            <div class="inline-flex bg-slate-900/80 border border-slate-700 rounded-2xl p-1.5 self-start md:self-auto shadow-lg">
                <button id="breakdown-wallets-btn" onclick="window.switchBreakdownView('wallets')" class="px-4 py-2 rounded-xl text-xs md:text-sm font-bold transition-all">钱包视图</button>
                <button id="breakdown-assets-btn" onclick="window.switchBreakdownView('assets')" class="px-4 py-2 rounded-xl text-xs md:text-sm font-bold transition-all">资产占比</button>
                <button id="breakdown-categories-btn" onclick="window.switchBreakdownView('categories')" class="px-4 py-2 rounded-xl text-xs md:text-sm font-bold transition-all">类别占比</button>
            </div>
        </div>`;
    container.appendChild(switchCard);
    updateBreakdownToggleUI();

    if (currentBreakdownView === 'assets') {
        const assetCard = document.createElement('div');
        assetCard.className = 'bento-card full-row-card';
        assetCard.innerHTML = buildAssetAllocationCard(data);
        container.appendChild(assetCard);
        return;
    }

    if (currentBreakdownView === 'categories') {
        const categoryCard = document.createElement('div');
        categoryCard.className = 'bento-card full-row-card';
        categoryCard.innerHTML = buildCategoryAllocationCard(data);
        container.appendChild(categoryCard);
        return;
    }

    (data.children || []).forEach((source, index) => {
        const isFull = (index % 2 === 0 && index === data.children.length - 1);
        const card = document.createElement('div');
        card.className = `bento-card ${isFull ? 'full-row-card' : ''}`;

        let html = `
            <div class="flex justify-between items-start">
                <div class="flex items-center gap-2">
                    <h3 class="text-slate-300 font-semibold text-lg">${source.name}</h3>
                    <span class="text-[9px] px-1.5 py-0.5 rounded border ${(source.source_type || 'manual') === 'manual' ? 'text-emerald-300 border-emerald-400/40 bg-emerald-500/10' : 'text-amber-300 border-amber-400/40 bg-amber-500/10'}">${(source.source_type || 'manual').toUpperCase()}</span>
                </div>
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
                <div class="flex flex-col items-start">
                    <div class="flex items-center gap-2">
                        <span class="text-slate-50 text-sm font-bold">${token.amount}</span>
                        <span class="text-sky-400 text-[11px] font-mono font-black">${token.symbol || 'TOKEN'}</span>
                        <span class="text-[9px] px-1.5 py-0.5 rounded border ${token.is_auto_synced ? 'text-amber-300 border-amber-400/40 bg-amber-500/10' : 'text-emerald-300 border-emerald-400/40 bg-emerald-500/10'}">
                            ${token.is_auto_synced ? 'API' : 'MANUAL'}
                        </span>
                    </div>
                    ${token.label ? `
                        <span class="text-[9px] text-slate-500 font-medium uppercase tracking-wider mt-0.5 bg-slate-800/50 px-1.5 py-0.5 rounded border border-slate-700/50">
                            #${token.label}
                        </span>` : ''}
                    ${token.label_id ? `
                        <span class="text-[9px] text-slate-400 font-medium uppercase tracking-wider mt-0.5 bg-slate-800/50 px-1.5 py-0.5 rounded border border-slate-700/50">
                            ID:${token.label_id}
                        </span>` : ''}
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <span class="text-white text-sm font-mono">${formatMoney(token.value)}</span>
                ${token.is_auto_synced ? `
                    <span class="text-[10px] text-slate-500">自动同步</span>
                    <button onclick="window.deleteCexAsset('${token.id}')" class="p-1 text-slate-700 hover:text-red-500" title="移除自动同步记录">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                ` : `
                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-all">
                    <button onclick="window.openEditModal('${token.id}', '${token.amount}', '${token.symbol}', '${net.name}', '${source.name}', '${(token.label || '').replace(/'/g, "\\'")}', '${(token.label_id || '').replace(/'/g, "\\'")}', ${token.is_auto_synced ? 'true' : 'false'})" class="p-1 text-slate-500 hover:text-sky-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <button onclick="window.deleteAsset('${token.id}')" class="p-1 text-slate-700 hover:text-red-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>`}
            </div>
        </div>`;
            });
        });
        card.innerHTML = html + `</div></div>`;
        container.appendChild(card);
    });
}

function updateBreakdownToggleUI() {
    const walletsBtn = document.getElementById('breakdown-wallets-btn');
    const assetsBtn = document.getElementById('breakdown-assets-btn');
    const categoriesBtn = document.getElementById('breakdown-categories-btn');
    if (!walletsBtn || !assetsBtn || !categoriesBtn) return;

    const activate = (btn) => {
        btn.classList.add('bg-sky-500', 'text-white', 'shadow-lg', 'shadow-sky-500/25');
        btn.classList.remove('text-slate-400', 'hover:text-slate-200');
    };
    const deactivate = (btn) => {
        btn.classList.remove('bg-sky-500', 'text-white', 'shadow-lg', 'shadow-sky-500/25');
        btn.classList.add('text-slate-400', 'hover:text-slate-200');
    };

    if (currentBreakdownView === 'assets') {
        deactivate(walletsBtn);
        activate(assetsBtn);
        deactivate(categoriesBtn);
    } else if (currentBreakdownView === 'categories') {
        deactivate(walletsBtn);
        deactivate(assetsBtn);
        activate(categoriesBtn);
    } else {
        activate(walletsBtn);
        deactivate(assetsBtn);
        deactivate(categoriesBtn);
    }
}

function buildAssetAllocationCard(data) {
    const allocations = calculateAssetAllocations(data);
    if (allocations.length === 0) {
        return `
            <div class="text-center py-12">
                <div class="text-slate-400 text-sm">暂无资产可用于占比分析</div>
            </div>`;
    }

    const topPct = allocations[0].percentage;
    const rows = allocations.map((item, index) => {
        const barWidth = Math.max(2, Math.round(item.percentage));
        const ratio = topPct > 0 ? (item.percentage / topPct) * 100 : 0;
        return `
            <div class="py-3 px-3 rounded-2xl border border-slate-800/70 bg-slate-900/45 hover:border-sky-500/40 transition-all">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0" style="background:${item.color}"></span>
                        <div class="min-w-0">
                            <div class="text-white text-sm font-semibold truncate">${item.symbol}</div>
                            <div class="text-[11px] text-slate-500">持仓总量: ${item.amount.toLocaleString(undefined, { maximumFractionDigits: 6 })}</div>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-white text-sm font-semibold">${formatMoney(item.value)}</div>
                        <div class="text-[11px] font-bold" style="color:${item.color}">${item.percentage.toFixed(2)}%</div>
                    </div>
                </div>
                <div class="mt-2.5 h-2.5 rounded-full bg-slate-800/90 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500" style="width:${barWidth}%; background:linear-gradient(90deg, ${item.color} 0%, rgba(255,255,255,0.95) ${Math.max(35, ratio).toFixed(0)}%, ${item.color} 100%);"></div>
                </div>
                <div class="mt-2 text-[10px] text-slate-500 uppercase tracking-widest">Rank #${index + 1}</div>
            </div>`;
    }).join('');

    return `
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 pb-4 border-b border-slate-800">
            <div>
                <h3 class="text-white text-xl font-semibold tracking-tight">Asset Allocation</h3>
                <p class="text-slate-400 text-xs md:text-sm mt-1">聚合所有钱包中的资产，按市值展示占比结构。</p>
            </div>
            <div class="inline-flex items-center gap-2 bg-slate-900/70 border border-slate-700 rounded-xl px-3 py-2">
                <span class="text-[10px] text-slate-500 uppercase tracking-widest">Total</span>
                <span class="text-white text-sm font-semibold">${formatMoney(data.value || 0)}</span>
            </div>
        </div>
        <div class="mt-4 flex flex-col gap-3">
            ${rows}
        </div>`;
}

function buildCategoryAllocationCard(data) {
    const allocations = calculateCategoryAllocations(data);
    const uncategorizedSymbols = getUncategorizedSymbols(data);
    if (allocations.length === 0) {
        return `
            <div class="text-center py-12">
                <div class="text-slate-400 text-sm">暂无类别可用于占比分析</div>
            </div>`;
    }

    const topValue = allocations[0].value || 0;
    const rows = allocations.map((item, index) => {
        const barWidth = topValue > 0 ? Math.max(2, Math.round((item.value / topValue) * 100)) : 0;
        const symbolPreview = item.symbols.length > 0 ? item.symbols.slice(0, 4).join(', ') : '尚未分配币种';
        const overflowText = item.symbols.length > 4 ? ` 等 ${item.symbols.length} 个币种` : '';
        const canManage = item.manageable === true;
        const encodedId = encodeURIComponent(item.id || '');
        const symbolTags = (item.symbols || []).map((symbol) => {
            const encodedSymbol = encodeURIComponent(symbol);
            return canManage
                ? `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-800 border border-slate-700 text-[11px] text-slate-200" draggable="true" ondragstart="window.onCategoryTagDragStart(event, decodeURIComponent('${encodedSymbol}'), decodeURIComponent('${encodedId}'))">${symbol}<button type="button" onclick="window.removeSymbolFromCategory(decodeURIComponent('${encodedId}'), decodeURIComponent('${encodedSymbol}'))" class="text-rose-400">x</button></span>`
                : `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-slate-800 border border-slate-700 text-[11px] text-slate-200">${symbol}</span>`;
        }).join('');
        return `
            <div class="py-3 px-3 rounded-2xl border border-slate-800/70 bg-slate-900/45 hover:border-sky-500/40 transition-all"
                ondragover="window.onCategoryDragOver(event)"
                ondrop="window.onCategoryDrop(event, decodeURIComponent('${encodedId}'))">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0" style="background:${item.color}"></span>
                        <div class="min-w-0">
                            <div class="text-white text-sm font-semibold truncate">${item.name}</div>
                            <div class="text-[11px] text-slate-500 truncate">包含: ${symbolPreview}${overflowText}</div>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-white text-sm font-semibold">${formatMoney(item.value)}</div>
                        <div class="text-[11px] font-bold" style="color:${item.color}">${item.percentage.toFixed(2)}%</div>
                    </div>
                </div>
                <div class="mt-2.5 h-2.5 rounded-full bg-slate-800/90 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500" style="width:${barWidth}%; background:linear-gradient(90deg, ${item.color} 0%, rgba(255,255,255,0.95) 55%, ${item.color} 100%);"></div>
                </div>
                <div class="mt-2 text-[10px] text-slate-500 uppercase tracking-widest">${item.count} 个持仓</div>
                <div class="mt-3 flex flex-wrap gap-2">${symbolTags || '<span class="text-[11px] text-slate-500">暂无币种</span>'}</div>
                ${canManage ? `<div class="mt-3 text-[11px] text-slate-500">将币种直接拖到这个类别卡片即可</div>` : ''}
            </div>`;
    }).join('');

    const draggablePool = uncategorizedSymbols.map((symbol) => {
        const encodedSymbol = encodeURIComponent(symbol);
        return `<button type="button"
            draggable="true"
            ondragstart="window.onCategorySymbolDragStart(event, decodeURIComponent('${encodedSymbol}'))"
            class="px-2.5 py-1 rounded-full bg-slate-800 border border-slate-700 text-[11px] text-slate-200 hover:border-sky-500">
            ${symbol}
        </button>`;
    }).join('');

    return `
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 pb-4 border-b border-slate-800">
            <div>
                <h3 class="text-white text-xl font-semibold tracking-tight">Category Allocation</h3>
                <p class="text-slate-400 text-xs md:text-sm mt-1">按你创建的类别汇总币种总占比，例如“激进”类别中的 BTC、CRO 会自动合并计算。</p>
            </div>
            <div class="inline-flex items-center gap-2 bg-slate-900/70 border border-slate-700 rounded-xl px-3 py-2">
                <span class="text-[10px] text-slate-500 uppercase tracking-widest">Total</span>
                <span class="text-white text-sm font-semibold">${formatMoney(data.value || 0)}</span>
            </div>
        </div>
        <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
            <input id="category-name-dashboard" type="text" placeholder="新增类别，例如 激进"
                class="md:col-span-2 bg-slate-900 border border-slate-700 rounded-xl px-4 py-2.5 text-white text-sm">
            <button type="button" onclick="window.submitCategory()" class="bg-sky-500 hover:bg-sky-400 text-white rounded-xl px-4 py-2.5 text-sm font-bold">添加类别</button>
        </div>
        <div class="mt-3 p-3 rounded-xl border border-slate-800 bg-slate-950/40"
            ondragover="window.onCategoryDragOver(event)"
            ondrop="window.onPoolDrop(event)">
            <div class="text-[11px] text-slate-400 mb-2">未分类币种池（可拖拽到下方类别，或把标签拖回这里）</div>
            <div class="flex flex-wrap gap-2">${draggablePool || '<span class="text-[11px] text-slate-500">暂无币种</span>'}</div>
        </div>
        <div class="mt-4 flex flex-col gap-3">
            ${rows}
        </div>`;
}

function calculateAssetAllocations(data) {
    const totalValue = Number(data.value || 0);
    const assetMap = new Map();

    (data.children || []).forEach(source => {
        (source.children || []).forEach(net => {
            (net.children || []).forEach(token => {
                const symbol = (token.symbol || token.name || 'UNKNOWN').toUpperCase();
                const current = assetMap.get(symbol) || { symbol, value: 0, amount: 0 };
                current.value += Number(token.value || 0);
                current.amount += Number(token.amount || 0);
                assetMap.set(symbol, current);
            });
        });
    });

    const palette = ['#38bdf8', '#10b981', '#f59e0b', '#fb7185', '#22d3ee', '#a78bfa', '#f97316', '#60a5fa'];

    return Array.from(assetMap.values())
        .sort((a, b) => b.value - a.value)
        .map((item, index) => ({
            ...item,
            color: palette[index % palette.length],
            percentage: totalValue > 0 ? (item.value / totalValue) * 100 : 0
        }));
}

function calculateCategoryAllocations(data) {
    const totalValue = Number(data.value || 0);
    const symbolTotals = new Map();
    const categoryMap = new Map();
    const palette = ['#38bdf8', '#10b981', '#f59e0b', '#fb7185', '#22d3ee', '#a78bfa', '#f97316', '#60a5fa', '#34d399', '#f472b6'];

    (data.children || []).forEach((source) => {
        (source.children || []).forEach((net) => {
            (net.children || []).forEach((token) => {
                const symbol = (token.symbol || token.name || 'UNKNOWN').toUpperCase();
                const current = symbolTotals.get(symbol) || 0;
                symbolTotals.set(symbol, current + Number(token.value || 0));
            });
        });
    });

    const usedSymbols = new Set();
    (globalCategories || []).forEach((category) => {
        const name = (category.name || '').trim();
        if (!name) return;
        const categoryId = normalizeCategoryId(category.id || category._id);

        const configuredSymbols = (Array.isArray(category.symbols) ? category.symbols : [])
            .map((s) => String(s || '').trim().toUpperCase())
            .filter(Boolean);

        let value = 0;
        const matchedSymbols = [];
        configuredSymbols.forEach((symbol) => {
            if (symbolTotals.has(symbol) && !usedSymbols.has(symbol)) {
                value += Number(symbolTotals.get(symbol) || 0);
                matchedSymbols.push(symbol);
                usedSymbols.add(symbol);
            }
        });

        categoryMap.set(name, {
            id: categoryId,
            name,
            value,
            count: matchedSymbols.length,
            symbols: configuredSymbols,
            manageable: true,
        });
    });

    // 未分类币种通过顶部“未分类币种池”展示，不在类别卡中渲染。

    return Array.from(categoryMap.values())
        .map((item, index) => ({
            ...item,
            color: palette[index % palette.length],
            percentage: totalValue > 0 ? (item.value / totalValue) * 100 : 0,
            symbols: item.symbols.sort(),
        }))
        .sort((a, b) => b.value - a.value);
}

window.switchBreakdownView = (view) => {
    currentBreakdownView = view === 'assets' ? 'assets' : (view === 'categories' ? 'categories' : 'wallets');
    renderPortfolio(globalPortfolioData);
};

// public/dashboard.js

function renderChart(data) {
    if (!data || !data.times || data.times.length === 0) return;
    const chartDom = document.getElementById('echarts-container');
    if (!myChart) myChart = echarts.init(chartDom);

    const granularity = data.granularity || '5m';
    const assetData = data.times.map((t, i) => [t, isMYR ? data.values[i] * MYR_RATE : data.values[i]]);
    const investedData = data.times.map((t, i) => {
        const investedMYR = parseFloat(data.invested[i] || 0);
        const invested = isMYR ? investedMYR : investedMYR / MYR_RATE;
        return [t, invested];
    });

    myChart.setOption({
        legend: { show: true, textStyle: { color: '#64748b' }, bottom: 0 },
        tooltip: {
            trigger: 'axis', // 🎯 必须是 axis，悬停在垂直线上时触发
            backgroundColor: '#0f172a',
            borderColor: '#1e293b',
            textStyle: { color: '#fff' },
            formatter: (params) => {
                const point = params && params[0] ? params[0] : null;
                if (!point) return '';
                const timestamp = formatChartTimestamp(point.value[0], granularity, true);
                const seriesRows = params.map(item => `<div class="flex justify-between gap-6"><span class="text-slate-400">${item.seriesName}</span><span class="font-mono">${formatChartMoney(item.value[1])}</span></div>`).join('');
                return `<div class="font-bold mb-2">${timestamp}</div>${seriesRows}`;
            },
            valueFormatter: (value) => formatChartMoney(value),
            // 增加指示线，方便对准
            axisPointer: {
                type: 'line',
                lineStyle: { color: 'rgba(255, 255, 255, 0.1)', type: 'dashed' }
            }
        },
        xAxis: {
            type: 'time',
            axisLabel: {
                color: '#64748b',
                formatter: (value) => formatChartTimestamp(value, granularity, false)
            }
        },
        yAxis: {
            type: 'value',
            scale: true,
            axisLabel: {
                color: '#64748b',
                formatter: (value) => formatChartMoney(value)
            },
            splitLine: { lineStyle: { color: 'rgba(255,255,255,0.05)' } }
        },
        series: [
            {
                name: '资产市值 (Value)',
                data: assetData,
                type: 'line',
                smooth: 0.4,
                itemStyle: { color: '#38bdf8' },
                areaStyle: { color: 'rgba(56, 189, 248, 0.1)' },
                // 🎯 关键修改 1：平时不显示 symbol
                showSymbol: false, 
                // 🎯 关键修改 2：定义点的样式和大小
                symbol: 'circle',
                symbolSize: 8,
                // 🎯 关键修改 3：悬停时的强化状态
                emphasis: {
                    focus: 'series',
                    itemStyle: {
                        color: '#38bdf8',
                        borderColor: '#fff',
                        borderWidth: 2
                    }
                },
                z: 2
            },
            {
                name: '净投入本金 (Net Invested)',
                data: investedData,
                type: 'line',
                step: 'end',
                itemStyle: { color: '#f59e0b' },
                lineStyle: { type: 'dashed', width: 2, color: '#f59e0b' },
                // 🎯 同样处理本金线
                showSymbol: false,
                symbol: 'circle',
                symbolSize: 8,
                emphasis: {
                    focus: 'series',
                    itemStyle: {
                        color: '#f59e0b',
                        borderColor: '#fff',
                        borderWidth: 2
                    }
                },
                z: 1
            }
        ]
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

    let calendarSeriesData = Array.isArray(data?.calendar) && data.calendar.length > 0 ? data.calendar : (() => {
        const dailyDataMap = {};
        if (data && data.times && data.times.length > 0) {
            data.times.forEach((t, i) => {
                const d = new Date(t);
                const ds = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
                if (!dailyDataMap[ds]) dailyDataMap[ds] = [];
                dailyDataMap[ds].push(isMYR ? data.values[i] * MYR_RATE : data.values[i]);
            });
        }

        const fallbackSeries = [];
        let previousDayClose = null;
        const currentYear = new Date().getFullYear();
        const startDate = new Date(currentYear, 0, 1);
        const today = new Date();

        for (let d = new Date(startDate); d <= today; d.setDate(d.getDate() + 1)) {
            const ds = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

            if (dailyDataMap[ds]) {
                const values = dailyDataMap[ds];
                const dayOpen = previousDayClose !== null ? previousDayClose : values[0];
                const dayClose = values[values.length - 1];
                const pnl = dayClose - dayOpen;
                const pct = dayOpen === 0 ? 0 : (pnl / dayOpen) * 100;

                fallbackSeries.push([ds, pnl, pct, dayClose, true]);
                previousDayClose = dayClose;
            } else {
                fallbackSeries.push([ds, 0, 0, previousDayClose || 0, false]);
            }
        }

        return fallbackSeries;
    })();

    // Apply currency conversion to calendar data if needed
    if (isMYR) {
        calendarSeriesData = calendarSeriesData.map(item => [
            item[0],                    // date
            item[1] * MYR_RATE,        // pnl (converted)
            item[2],                    // pct (unchanged)
            item[3] * MYR_RATE,        // dayClose (converted)
            item[4]                     // hasData
        ]);
    }

    const currentYear = new Date().getFullYear();

    // --- 提取公共 Tooltip ---
    const commonTooltip = {
        backgroundColor: '#0f172a', textStyle: { color: '#f8fafc' },
        formatter: (p) => {
            const [date, pnl, pct, total, has] = p.value;
            if (!has && total === 0) return `${date}<br>无数据`;
            const color = pnl >= 0 ? 'text-emerald-400' : 'text-rose-400';
            // 🎯 替换为 formatChartMoney，防止 RM 汇率被乘两次，且强制两位小数
            return `<div class="p-2"><b>${date}</b><br>总额: ${formatChartMoney(total)}<br>盈亏: <span class="${color}">${pnl >= 0 ? '+' : ''}${formatChartMoney(pnl)} (${pct.toFixed(2)}%)</span></div>`;
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
                type: 'scatter', 
                coordinateSystem: 'calendar', 
                data: mData, 
                symbolSize: 0, 
                label: { 
                    show: true, 
                    // 🎯 替换为 formatChartMoney
                    formatter: (p) => p.value[1] === 0 ? '' : (p.value[1]>0?'+':'') + formatChartMoney(p.value[1]) 
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

    // 🎯 核心修复：切换货币时重新计算 ROI
    if (globalStats && globalPortfolioData) {
        calculateROI(globalPortfolioData.value, globalStats);
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
                'Accept': 'application/json', // 🎯 核心修复 1：强制要求 Laravel 无论如何都返回 JSON
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
            // 🎯 清除缓存
            CacheManager.clear('trackedTokens');
            // 刷新列表
            loadTrackedTokens();
        } else {
            // 🎯 核心修复 2：安全地解析错误信息，防止再次出现 HTML 解析崩溃
            let errorMessage = "请求被服务器拒绝";
            try {
                const err = await res.json();
                errorMessage = err.message || "后端处理失败 (可能是 CoinGecko 接口请求过于频繁)";
            } catch (parseError) {
                console.error("后端返回了非 JSON 格式的错误 (可能是 500 致命错误)");
            }
            alert("❌ 添加失败: " + errorMessage);
        }
    } catch (e) {
        console.error("提交追踪代币出错:", e);
        alert("网络请求发生异常");
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
            // 🎯 清除缓存
            CacheManager.clear('trackedTokens');
            loadTrackedTokens();
        }
    } catch (e) {
        console.error("删除追踪代币出错:", e);
    }
};