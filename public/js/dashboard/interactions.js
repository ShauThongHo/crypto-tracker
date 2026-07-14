/**
 * interactions.js — DOMContentLoaded 初始化、所有 window.* 交互处理器、同步器
 * 依赖: globals.js, formatting.js, services.js, renderers.js
 * 加载顺序: 第 5 个 (最后)
 */

// ==========================================
// 表单绑定
// ==========================================

function bindAssetModalForms() {
    const addForm = document.getElementById('addAssetForm');
    if (addForm && !addForm.dataset.bound) {
        addForm.dataset.bound = 'true';
        addForm.addEventListener('submit', (event) => {
            event.preventDefault();
            void window.submitNewAsset(event);
        });
    }

    const editForm = document.getElementById('editAssetForm');
    if (editForm && !editForm.dataset.bound) {
        editForm.dataset.bound = 'true';
        editForm.addEventListener('submit', (event) => {
            event.preventDefault();
            void window.submitEditAsset(event);
        });
    }
}

// ==========================================
// DOMContentLoaded 主入口
// ==========================================

document.addEventListener('DOMContentLoaded', async () => {
    console.log('HST Dashboard starting...');

    bindAssetModalForms();
    await refreshLiveExchangeRate();
    initCurrencyToggle();

    Promise.all([
        loadAllData().catch(e => console.error('Portfolio data load failed:', e)),
        loadHistoryData().catch(e => console.error('History data load failed:', e)),
        loadCategories().catch(e => console.error('Categories load failed:', e)),
        loadTrackedTokens().catch(e => console.error('Tracked tokens load failed:', e)),
        loadWallets().catch(e => console.error('Wallets load failed:', e)),
        loadExchangeAccounts().catch(e => console.error('Exchange accounts load failed:', e)),
        loadLowValueAssetFilterSetting().catch(e => console.error('Low value filter load failed:', e))
    ]).then(() => {
        console.log('All background data preloaded');
    });

    initAlignedTimer();
    updateSyncBadgeAndCheckUpdate();
    setInterval(updateSyncBadgeAndCheckUpdate, 5000);

    document.addEventListener('click', (e) => {
        const res = document.getElementById('tracked_search_results');
        if (res && !e.target.closest('#addTokenRow')) res.classList.add('hidden');
    });
});

// ==========================================
// 弹窗控制
// ==========================================

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

// ==========================================
// 搜索代币 (Add Asset Modal)
// ==========================================

window.searchToken = async (query) => {
    const resDiv = document.getElementById('token_suggestions');
    if (query.length < 1) return resDiv.classList.add('hidden');
    try {
        const tokens = await fetch('/api/tracked-tokens').then(res => res.json());
        const filtered = tokens.filter(t =>
            t.name.toLowerCase().includes(query.toLowerCase()) ||
            t.symbol.toLowerCase().includes(query.toLowerCase())
        ).slice(0, 5);
        if (filtered.length > 0) {
            resDiv.innerHTML = filtered.map(t =>
                `<div onclick="selectToken('${t.coingecko_id}', '${t.name}')" class="p-2 hover:bg-slate-800 cursor-pointer text-white text-sm">${t.name} (${t.symbol})</div>`
            ).join('');
            resDiv.classList.remove('hidden');
        } else {
            resDiv.classList.add('hidden');
        }
    } catch (e) {
        console.error('Token search failed', e);
    }
};

window.selectToken = (id, name) => {
    document.getElementById('add_coingecko_id').value = id;
    document.getElementById('add_token_name').value = name;
    document.getElementById('add_token_search').value = name;
    document.getElementById('token_suggestions').classList.add('hidden');
};

// ==========================================
// 搜索来源 (Add Asset Modal)
// ==========================================

window.searchSource = async (query) => {
    const resDiv = document.getElementById('source_suggestions');
    if (query.length < 1) return resDiv.classList.add('hidden');
    try {
        const wallets = await fetch('/api/wallets').then(res => res.json());
        const filtered = wallets.filter(w => w.name.toLowerCase().includes(query.toLowerCase())).slice(0, 5);
        if (filtered.length > 0) {
            resDiv.innerHTML = filtered.map(w =>
                `<div onclick="selectSource('${w.name}')" class="p-2 hover:bg-slate-800 cursor-pointer text-white text-sm">${w.name}</div>`
            ).join('');
            resDiv.classList.remove('hidden');
        } else {
            resDiv.classList.add('hidden');
        }
    } catch (e) {
        console.error('Source search failed', e);
    }
};

window.selectSource = (name) => {
    document.getElementById('add_source_name').value = name;
    document.getElementById('source_suggestions').classList.add('hidden');
};

// ==========================================
// 时间范围切换
// ==========================================

window.changeRange = async (range) => {
    currentRange = range;
    const btns = document.querySelectorAll('.range-btn');
    btns.forEach(b => b.classList.toggle('bg-sky-500', b.innerText === range));
    btns.forEach(b => b.classList.toggle('text-white', b.innerText === range));
    await loadAllData();
};

// ==========================================
// 资产 CRUD
// ==========================================

window.submitEditAsset = async (event) => {
    event?.preventDefault?.();
    const id = document.getElementById('edit_asset_id').value;
    const amount = document.getElementById('edit_token_amount').value;
    const network = document.getElementById('edit_network').value;
    const source = document.getElementById('edit_source_name').value;
    const label = document.getElementById('edit_label').value;

    try {
        const res = await fetch(`/api/assets/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify({ token_amount: parseFloat(amount), network, source_name: source, label })
        });

        if (res.ok) {
            window.closeEditModal();
            CacheManager.clear('portfolioData');
            CacheManager.clearAllSnapshotCache();
            CacheManager.clear('statsData');
            await loadAllData();
        } else {
            const err = await res.json();
            alert('Update failed: ' + (err.message || 'Server rejected'));
        }
    } catch (e) {
        console.error('Edit request error:', e);
        alert('Network request failed');
    }
    return false;
};

window.submitNewAsset = async (event) => {
    event?.preventDefault?.();
    const source_name = document.getElementById('add_source_name').value;
    const token_name = document.getElementById('add_token_name').value;
    const coingecko_id = document.getElementById('add_coingecko_id').value;
    const token_amount = document.getElementById('add_token_amount').value;
    const network = document.getElementById('add_network').value;
    const label = document.getElementById('add_label').value;

    if (!source_name || !token_name || !coingecko_id || !token_amount || !network) {
        alert('请填写所有必填字段');
        return false;
    }

    const res = await fetch('/api/assets', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ source_name, token_name, coingecko_id, token_amount: parseFloat(token_amount), network, label })
    });
    if (res.ok) {
        window.closeAddModal();
        CacheManager.clear('portfolioData');
        CacheManager.clearAllSnapshotCache();
        CacheManager.clear('statsData');
        await loadAllData();
    } else {
        alert('添加失败');
    }
    return false;
};

window.deleteAsset = async (id) => {
    if (!confirm('确认从看板移除此资产？')) return;
    const res = await fetch(`/api/assets/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() }
    });
    if (res.ok) {
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

// ==========================================
// 钱包操作 (Settings)
// ==========================================

window.submitWallet = async () => {
    const name = document.getElementById('newWalletName').value;
    const type = document.getElementById('newWalletType').value;
    if (!name) return alert('请输入名字');
    const res = await fetch('/api/wallets', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ name, type })
    });
    if (res.ok) {
        document.getElementById('newWalletName').value = '';
        CacheManager.clear('wallets');
        loadWallets();
    }
};

window.deleteWallet = async (id) => {
    if (!confirm('确定删除此钱包？')) return;
    await fetch(`/api/wallets/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': getCsrfToken() } });
    CacheManager.clear('wallets');
    loadWallets();
};

// ==========================================
// 交易所账户操作 (Settings)
// ==========================================

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
        ['newExchangeLabel', 'newExchangeApiKey', 'newExchangeApiSecret', 'newExchangePassphrase',
         'exchangeAccountLabel', 'exchangeApiKey', 'exchangeApiSecret', 'exchangePassphrase']
            .forEach((id) => { const el = document.getElementById(id); if (el) el.value = ''; });
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
        method: 'DELETE', headers: { 'X-CSRF-TOKEN': getCsrfToken() }
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

// ==========================================
// 全量同步 (Settings)
// ==========================================

window.triggerManualSync = async () => {
    const btn = document.getElementById('manual-sync-btn');
    const text = document.getElementById('sync-text');
    if (btn) btn.disabled = true;
    if (text) text.innerText = '同步中...';

    try {
        const res = await fetch('/api/assets/sync', {
            method: 'POST', headers: { 'X-CSRF-TOKEN': getCsrfToken() }
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

// ==========================================
// 危险区域 (Danger Zone)
// ==========================================

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
// 法币切换
// ==========================================

function initCurrencyToggle() {
    const toggle = document.getElementById('currency-toggle');
    const knob = document.getElementById('currency-toggle-knob');
    if (!toggle || !knob) return;

    if (isMYR) {
        toggle.classList.add('bg-sky-500'); toggle.classList.remove('bg-slate-700');
        knob.classList.add('translate-x-6');
    } else {
        toggle.classList.add('bg-slate-700'); toggle.classList.remove('bg-sky-500');
        knob.classList.remove('translate-x-6');
    }

    toggle.onclick = () => {
        isMYR = !isMYR;
        localStorage.setItem('preferred_currency', isMYR ? 'MYR' : 'USD');
        toggle.classList.toggle('bg-sky-500', isMYR);
        toggle.classList.toggle('bg-slate-700', !isMYR);
        knob.classList.toggle('translate-x-6', isMYR);
        console.log('Currency switched to:', isMYR ? 'MYR' : 'USD');
        refreshMyChart();
    };
}

function refreshMyChart() {
    if (document.getElementById('grid-container')) {
        renderPortfolio(globalPortfolioData);
    }
    if (document.getElementById('echarts-container') && globalSnapshotData) {
        renderChart(globalSnapshotData);
    }
    if (document.getElementById('calendar-echarts-container') && globalSnapshotData) {
        renderCalendarHistory(globalSnapshotData);
    }
    if (globalStats && globalPortfolioData) {
        calculateROI(globalPortfolioData.value, globalStats);
    }
}

// ==========================================
// 同步状态 & 定时器
// ==========================================

function updateSyncBadgeAndCheckUpdate() {
    fetch('/api/sync-status').then(res => res.json()).then(data => {
        const badge = document.getElementById('sync-badge');
        if (!badge) return;
        if (data.status === 'running') {
            badge.innerText = '◌ 同步中...';
        } else {
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

// ==========================================
// CoinGecko 代币搜索 (Settings)
// ==========================================

window.searchCoinGeckoTracked = (query) => {
    const resDiv = document.getElementById('tracked_search_results');
    if (query.length < 2) return resDiv.classList.add('hidden');
    clearTimeout(trackedSearchTimer);
    trackedSearchTimer = setTimeout(async () => {
        const r = await fetch(`https://api.coingecko.com/api/v3/search?query=${query}`);
        const d = await r.json();
        if (d.coins) {
            resDiv.innerHTML = d.coins.slice(0, 5).map(c =>
                `<div onclick="window.selectTrackedToken('${c.id}', '${c.name}')" class="p-2 hover:bg-slate-800 cursor-pointer text-white text-sm">${c.name} (${c.symbol})</div>`
            ).join('');
            resDiv.classList.remove('hidden');
        }
    }, 300);
};

window.selectTrackedToken = (id, name) => {
    document.getElementById('newTokenId').value = id;
    document.getElementById('search_tracked_input').value = name;
    document.getElementById('tracked_search_results').classList.add('hidden');
};

window.submitTrackedToken = async () => {
    const id = document.getElementById('newTokenId').value;
    const name = document.getElementById('search_tracked_input').value;

    if (!id) return alert('请先通过搜索框选择一个代币');

    try {
        const res = await fetch('/api/tracked-tokens', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify({ coingecko_id: id, name })
        });

        if (res.ok) {
            document.getElementById('newTokenId').value = '';
            document.getElementById('search_tracked_input').value = '';
            CacheManager.clear('trackedTokens');
            loadTrackedTokens();
        } else {
            let errorMessage = '请求被服务器拒绝';
            try {
                const err = await res.json();
                errorMessage = err.message || '后端处理失败 (可能是 CoinGecko 接口请求过于频繁)';
            } catch (parseError) {
                console.error('Backend returned non-JSON error');
            }
            alert('添加失败: ' + errorMessage);
        }
    } catch (e) {
        console.error('Submit tracked token error:', e);
        alert('网络请求发生异常');
    }
};

window.deleteTrackedToken = async (id) => {
    if (!confirm('确定要停止追踪此代币吗？(相关的资产数据可能会受影响)')) return;

    try {
        const res = await fetch(`/api/tracked-tokens/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': getCsrfToken() }
        });

        if (res.ok) {
            CacheManager.clear('trackedTokens');
            loadTrackedTokens();
        }
    } catch (e) {
        console.error('Delete tracked token error:', e);
    }
};
