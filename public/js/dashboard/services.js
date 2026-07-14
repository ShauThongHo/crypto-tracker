/**
 * services.js — 所有数据加载、API 请求、类别管理逻辑
 * 依赖: globals.js, formatting.js
 * 加载顺序: 第 3 个
 */

// ==========================================
// 交易所账户
// ==========================================

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
        console.error('Exchange accounts load failed', e);
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

// ==========================================
// 低价值资产过滤设置
// ==========================================

function applyLowValueFilterToggleUi(enabled) {
    const status = document.getElementById('low-value-filter-status');
    const track = document.getElementById('low-value-filter-track');
    const knob = document.getElementById('low-value-filter-knob');

    if (status) {
        status.textContent = enabled ? '开启' : '关闭';
        status.className = enabled ? 'text-xs text-sky-300' : 'text-xs text-slate-400';
    }
    if (track) track.classList.toggle('bg-slate-700', !enabled) || track.classList.toggle('bg-sky-500', enabled);
    if (knob) knob.classList.toggle('translate-x-6', enabled);
}

function getLowValueFilterLocalState() {
    const raw = String(localStorage.getItem('hide_low_value_assets_enabled') || '').toLowerCase();
    return raw === '1' || raw === 'true';
}

function setLowValueFilterLocalState(enabled) {
    localStorage.setItem('hide_low_value_assets_enabled', enabled ? '1' : '0');
}

async function saveLowValueAssetFilterSetting(enabled) {
    const res = await fetch('/api/balance-alert/settings', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ hide_low_value_assets: !!enabled }),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || '保存低价值资产过滤设置失败');
    }
    return res.json().catch(() => ({}));
}

async function loadLowValueAssetFilterSetting() {
    const toggle = document.getElementById('low-value-filter-toggle');
    if (!toggle) return;

    const localEnabled = getLowValueFilterLocalState();
    toggle.checked = localEnabled;
    applyLowValueFilterToggleUi(localEnabled);

    try {
        const res = await fetch('/api/balance-alert/settings', { headers: { 'Accept': 'application/json' } });
        if (!res.ok) throw new Error('Failed to read filter setting');

        const payload = await res.json().catch(() => ({}));
        const enabled = !!payload?.data?.hide_low_value_assets;

        toggle.checked = enabled;
        applyLowValueFilterToggleUi(enabled);
        setLowValueFilterLocalState(enabled);
    } catch (error) {
        toggle.checked = localEnabled;
        applyLowValueFilterToggleUi(localEnabled);
    }

    toggle.onchange = async () => {
        const nextValue = !!toggle.checked;
        toggle.disabled = true;
        applyLowValueFilterToggleUi(nextValue);
        try {
            await saveLowValueAssetFilterSetting(nextValue);
            setLowValueFilterLocalState(nextValue);
            applyLowValueFilterToggleUi(nextValue);
            CacheManager.clear('portfolioData');
            CacheManager.clearAllSnapshotCache();
            CacheManager.clear('statsData');
            await loadAllData();
        } catch (error) {
            toggle.checked = !nextValue;
            applyLowValueFilterToggleUi(!nextValue);
            alert(error.message || '保存失败');
        } finally {
            toggle.disabled = false;
        }
    };
}

// ==========================================
// 首页数据加载 (Portfolio + Chart + Stats)
// ==========================================

async function loadAllData() {
    const snapshotCacheKey = `snapshotData_${currentRange}`;
    const cachedPortfolioData = CacheManager.get('portfolioData');
    const cachedSnapshotData = CacheManager.get(snapshotCacheKey);
    const cachedStats = CacheManager.get('statsData');

    if (cachedPortfolioData && cachedSnapshotData && cachedStats) {
        console.log(`Using cached ${currentRange} data`);
        globalPortfolioData = cachedPortfolioData;
        globalSnapshotData = cachedSnapshotData;
        globalStats = cachedStats;
        renderPortfolio(globalPortfolioData);
        renderChart(globalSnapshotData);
        calculateROI(globalPortfolioData.value, globalStats);
    }

    try {
        const [mapRes, snapRes, statRes] = await Promise.all([
            fetch('/api/assets/thinking-map').then(r => r.json()),
            fetch(`/api/assets/snapshots?range=${currentRange}`).then(r => r.json()),
            fetch('/api/portfolio-stats').then(r => r.json())
        ]);

        console.log('API data loaded:', { mapRes, snapRes, statRes });

        globalPortfolioData = mapRes;
        globalSnapshotData = snapRes;
        globalStats = statRes;

        CacheManager.set('portfolioData', mapRes);
        CacheManager.set(snapshotCacheKey, snapRes);
        CacheManager.set('statsData', statRes);

        renderPortfolio(globalPortfolioData);
        renderChart(globalSnapshotData);
        calculateROI(globalPortfolioData.value, globalStats);
    } catch (e) {
        console.error('Data load failed:', e);
    }
}

function calculateROI(currentValueUSD, stats) {
    const { net_invested } = stats;
    const badge = document.getElementById('roi-badge');
    const valueElem = document.getElementById('roi-value');

    if (!badge) return;
    if (!net_invested || net_invested <= 0) {
        badge.classList.add('hidden');
        return;
    }

    let currentValueInInvestedCurrency;
    let netInvestedInSameCurrency;

    if (isMYR) {
        currentValueInInvestedCurrency = currentValueUSD * MYR_RATE;
        netInvestedInSameCurrency = net_invested;
    } else {
        currentValueInInvestedCurrency = currentValueUSD;
        netInvestedInSameCurrency = net_invested / MYR_RATE;
    }

    const profit = currentValueInInvestedCurrency - netInvestedInSameCurrency;
    const roi = (profit / netInvestedInSameCurrency) * 100;

    valueElem.innerText = `${roi > 0 ? '+' : ''}${roi.toFixed(2)}%`;
    badge.classList.remove('hidden', 'bg-emerald-500/20', 'text-emerald-500', 'bg-rose-500/20', 'text-rose-500');

    if (roi >= 0) {
        badge.classList.add('bg-emerald-500/20', 'text-emerald-500');
    } else {
        badge.classList.add('bg-rose-500/20', 'text-rose-500');
    }
}

// ==========================================
// 历史数据加载 (History Page)
// ==========================================

async function loadHistoryData() {
    const cachedHistoryData = CacheManager.get('snapshotData_ALL');
    if (cachedHistoryData) {
        console.log('Using cached history data');
        globalSnapshotData = cachedHistoryData;
        renderCalendarHistory(globalSnapshotData);
    }

    try {
        const res = await fetch('/api/assets/snapshots?range=ALL');
        const data = await res.json();
        globalSnapshotData = data;
        CacheManager.set('snapshotData_ALL', data);
        renderCalendarHistory(globalSnapshotData);
    } catch (e) {
        console.error('History data load failed', e);
    }
}

// ==========================================
// 追踪代币 (Settings Page)
// ==========================================

async function loadTrackedTokens() {
    const cachedTokens = CacheManager.get('trackedTokens');
    if (cachedTokens && Array.isArray(cachedTokens)) {
        console.log('Using cached tracked tokens');
        const list = document.getElementById('tracked-tokens-list');
        if (list) renderTrackedTokensList(list, cachedTokens);
    }

    try {
        const res = await fetch('/api/tracked-tokens');
        const tokens = await res.json();
        CacheManager.set('trackedTokens', tokens);
        const list = document.getElementById('tracked-tokens-list');
        if (list) renderTrackedTokensList(list, tokens);
    } catch (e) {
        console.error('Tracked tokens load failed', e);
    }
}

function renderTrackedTokensList(list, tokens) {
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
}

// ==========================================
// 钱包 (Settings Page)
// ==========================================

async function loadWallets() {
    const cachedWallets = CacheManager.get('wallets');
    if (cachedWallets && Array.isArray(cachedWallets)) {
        console.log('Using cached wallets');
        const list = document.getElementById('wallets-list');
        if (list) renderWalletsList(list, cachedWallets);
    }

    try {
        const res = await fetch('/api/wallets');
        const wallets = await res.json();
        CacheManager.set('wallets', wallets);
        const list = document.getElementById('wallets-list');
        if (list) renderWalletsList(list, wallets);
    } catch (e) {
        console.error('Wallets load failed', e);
    }
}

function renderWalletsList(list, wallets) {
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
}

// ==========================================
// 资产类别 (Settings Page)
// ==========================================

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
        console.error('Categories load failed', e);
    }
}

function renderCategorySettingsList(categories) {
    const list = document.getElementById('asset-categories-list');
    if (!list) return;

    if (!categories || categories.length === 0) {
        list.innerHTML = '<tr><td colspan="3" class="px-6 py-6 text-center text-slate-500 text-sm">暂无类别，请先创建一个类别</td></tr>';
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

// ==========================================
// 类别工具函数
// ==========================================

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
                const symbol = String(token.symbol || '').trim().toUpperCase();
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

    const unassigned = allSymbols.filter((symbol) => !assignedSymbols.has(symbol));
    return Array.from(new Set(unassigned));
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
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
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
        console.error('Save symbols failed', e);
        alert('保存币种失败');
    }
}

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

// ==========================================
// Category window.* handlers
// ==========================================

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
    if (!nextSymbols.includes(symbol)) nextSymbols.push(symbol);
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
    categoryDragContext = { symbol: normalizedSymbol, sourceCategoryId: '' };
    if (!event || !event.dataTransfer) return;
    event.dataTransfer.setData('text/plain', normalizedSymbol);
    event.dataTransfer.setData('application/x-source-category-id', '');
    event.dataTransfer.effectAllowed = 'copy';
    event.target && event.target.addEventListener('dragend', () => { categoryDragContext = null; }, { once: true });
};

window.onCategoryTagDragStart = (event, symbol, sourceCategoryId) => {
    const normalizedSymbol = String(symbol || '').toUpperCase();
    const normalizedSourceCategoryId = String(sourceCategoryId || '');
    categoryDragContext = { symbol: normalizedSymbol, sourceCategoryId: normalizedSourceCategoryId };
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
            categoryDragContext?.sourceCategoryId || ''
        );
        event.dataTransfer.dropEffect = sourceCategoryId ? 'move' : 'copy';
    }
};

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
        console.error('Delete category failed', e);
    }
};

window.submitCategory = async () => {
    const input = document.getElementById('newCategoryName') || document.getElementById('category-name-dashboard');
    const targetInput = document.getElementById('newCategoryTargetPct') || document.getElementById('category-target-pct-dashboard');
    const name = input ? input.value.trim() : '';
    if (!name) return alert('请输入类别名称');
    const targetPct = targetInput ? (Number(targetInput.value) || 0) : 0;

    try {
        const res = await fetch('/api/asset-categories', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
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
        console.error('Create category failed', e);
        alert('创建类别失败');
    }
};
