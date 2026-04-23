class BalanceAlertPage {
    constructor() {
        this.storageKey = 'balance_alert_config_v4';
        this.snapshot = null;
        this.allocations = [];
        this.draggingSymbol = null;
        this.sequence = 1;
        this.autoRefreshTimer = null;
        this.preferredCurrency = 'USD';
        this.exchangeRate = 4.2;
        this.currentTotalValueUsd = 0;
        this.hasLocalAllocationConfig = false;

        this.bindElements();
        this.restoreConfig();
        this.initialize();
    }

    async initialize() {
        await this.initCurrencyPreference();
        await this.loadCategoryDefaults();
        this.bindEvents();
        this.renderAllocationList();
        this.checkSnapshot();
        this.startAutoRefresh();
    }

    startAutoRefresh() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
        }

        // Keep aligned with dashboard asset refresh frequency (5 minutes).
        this.refreshTimer = setInterval(() => {
            this.checkSnapshot();
        }, 300000);
    }

    bindElements() {
        this.webhookUrl = document.getElementById('webhookUrl');
        this.prepareThreshold = document.getElementById('prepareThreshold');
        this.rebalanceThreshold = document.getElementById('rebalanceThreshold');
        this.forceThreshold = document.getElementById('forceThreshold');

        this.checkBtn = document.getElementById('checkBtn');
        this.sendBtn = document.getElementById('sendBtn');
        this.statusBox = document.getElementById('statusBox');
        this.openReminderSettingsBtn = document.getElementById('openReminderSettingsBtn');
        this.reminderSettingsModal = document.getElementById('reminderSettingsModal');
        this.closeReminderSettingsBtn = document.getElementById('closeReminderSettingsBtn');
        this.openAllocationSettingsBtn = document.getElementById('openAllocationSettingsBtn');
        this.allocationSettingsModal = document.getElementById('allocationSettingsModal');
        this.closeAllocationSettingsBtn = document.getElementById('closeAllocationSettingsBtn');

        this.windowBadge = document.getElementById('windowBadge');
        this.totalValue = document.getElementById('totalValue');
        this.totalValueLabel = document.getElementById('totalValueLabel');
        this.tokenCount = document.getElementById('tokenCount');
        this.defaultTargetWeight = document.getElementById('defaultTargetWeight');
        this.maxDeviation = document.getElementById('maxDeviation');
        this.targetSum = document.getElementById('targetSum');

        this.equalizeBtn = document.getElementById('equalizeBtn');
        this.syncWeightBtn = document.getElementById('syncWeightBtn');

        this.levelBanner = document.getElementById('levelBanner');
        this.levelTitle = document.getElementById('levelTitle');
        this.levelMessage = document.getElementById('levelMessage');
        this.toggleDetailsBtn = document.getElementById('toggleDetailsBtn');
        this.detailsSection = document.getElementById('detailsSection');
        this.tokensBody = document.getElementById('tokensBody');
        this.detailsValueHeader = document.getElementById('detailsValueHeader');
        this.summaryCell = document.getElementById('summaryCell');

        this.allocationBuilder = document.getElementById('allocationBuilder');
        this.addAllocationBtn = document.getElementById('addAllocationBtn');
        this.tokenPool = document.getElementById('tokenPool');
        this.allocationList = document.getElementById('allocationList');
        this.helperText = document.getElementById('helperText');
    }

    bindEvents() {
        this.openReminderSettingsBtn.addEventListener('click', () => this.openReminderSettings());
        this.closeReminderSettingsBtn.addEventListener('click', () => this.closeReminderSettings());
        this.reminderSettingsModal.addEventListener('click', (event) => {
            if (event.target === this.reminderSettingsModal) {
                this.closeReminderSettings();
            }
        });
        this.openAllocationSettingsBtn.addEventListener('click', () => this.openAllocationSettings());
        this.closeAllocationSettingsBtn.addEventListener('click', () => this.closeAllocationSettings());
        this.allocationSettingsModal.addEventListener('click', (event) => {
            if (event.target === this.allocationSettingsModal) {
                this.closeAllocationSettings();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.closeReminderSettings();
                this.closeAllocationSettings();
            }
        });

        this.checkBtn.addEventListener('click', () => this.checkSnapshot());
        this.sendBtn.addEventListener('click', () => this.sendAlert());
        this.equalizeBtn.addEventListener('click', () => this.applyEqualTargets());
        this.syncWeightBtn.addEventListener('click', () => this.applyCurrentWeightsAsTargets());
        this.addAllocationBtn.addEventListener('click', () => this.addAllocation());
        if (this.toggleDetailsBtn && this.detailsSection) {
            this.toggleDetailsBtn.addEventListener('click', () => this.toggleDetails());
        }

        this.allocationList.addEventListener('input', (event) => {
            const row = event.target.closest('[data-allocation-id]');
            if (!row) return;
            const allocation = this.findAllocation(row.dataset.allocationId);
            if (!allocation) return;

            if (event.target.classList.contains('allocation-name-input')) {
                allocation.name = (event.target.value || '').trim() || allocation.name;
            }

            if (event.target.classList.contains('allocation-target-input')) {
                allocation.target_pct = this.parseNumber(event.target.value, 0);
            }

            this.saveConfig();
            this.updateTargetSum();
            this.scheduleSnapshotRefresh();
        });

        this.allocationList.addEventListener('click', (event) => {
            const deleteBtn = event.target.closest('.delete-allocation-btn');
            if (!deleteBtn) return;
            const allocationId = deleteBtn.dataset.allocationId;
            this.deleteAllocation(allocationId);
            this.saveConfig();
            this.renderAllocationList();
            this.checkSnapshot();
        });

        this.allocationList.addEventListener('dragover', (event) => {
            event.preventDefault();
        });

        this.allocationList.addEventListener('drop', (event) => {
            event.preventDefault();
            const symbol = (event.dataTransfer && event.dataTransfer.getData('text/plain')) || this.draggingSymbol;
            const dropZone = event.target.closest('.allocation-drop-zone');
            if (dropZone) {
                const allocationId = dropZone.dataset.allocationId;
                this.assignSymbolToAllocation(symbol, allocationId);
                return;
            }

            if (!symbol) return;
            this.createSingleCoinAllocation(symbol);
        });

        this.tokenPool.addEventListener('dragover', (event) => {
            event.preventDefault();
        });

        this.tokenPool.addEventListener('drop', (event) => {
            event.preventDefault();
            const symbol = (event.dataTransfer && event.dataTransfer.getData('text/plain')) || this.draggingSymbol;
            this.unassignSymbol(symbol);
        });

        [this.webhookUrl, this.prepareThreshold, this.rebalanceThreshold, this.forceThreshold].forEach((el) => {
            el.addEventListener('change', () => this.saveConfig());
        });
    }

    openReminderSettings() {
        this.reminderSettingsModal.classList.remove('hidden');
        this.reminderSettingsModal.classList.add('flex');
    }

    async initCurrencyPreference() {
        this.preferredCurrency = localStorage.getItem('preferred_currency') === 'MYR' ? 'MYR' : 'USD';
        this.updateCurrencyLabels();

        if (this.preferredCurrency !== 'MYR') {
            this.exchangeRate = 1;
            return;
        }

        try {
            const res = await fetch('/api/exchange-rate');
            const data = await res.json();
            if (res.ok && data && Number(data.rate) > 0) {
                this.exchangeRate = Number(data.rate);
            }
        } catch (error) {
            this.exchangeRate = 4.2;
        } finally {
            if (this.snapshot) {
                this.renderSnapshot(this.snapshot);
            }
        }
    }

    updateCurrencyLabels() {
        const symbol = this.preferredCurrency === 'MYR' ? 'MYR' : 'USD';
        if (this.totalValueLabel) {
            this.totalValueLabel.textContent = `总资产 (${symbol})`;
        }
        if (this.detailsValueHeader) {
            this.detailsValueHeader.textContent = `价值 (${symbol})`;
        }
    }

    formatMoneyByPreference(usdValue) {
        const value = Number(usdValue || 0);
        if (this.preferredCurrency === 'MYR') {
            const converted = value * this.exchangeRate;
            return converted.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    closeReminderSettings() {
        this.reminderSettingsModal.classList.add('hidden');
        this.reminderSettingsModal.classList.remove('flex');
    }

    openAllocationSettings() {
        this.allocationSettingsModal.classList.remove('hidden');
        this.allocationSettingsModal.classList.add('flex');
    }

    closeAllocationSettings() {
        this.allocationSettingsModal.classList.add('hidden');
        this.allocationSettingsModal.classList.remove('flex');
    }

    scheduleSnapshotRefresh(delay = 450) {
        if (this.autoRefreshTimer) {
            clearTimeout(this.autoRefreshTimer);
        }

        this.autoRefreshTimer = setTimeout(() => {
            this.autoRefreshTimer = null;
            this.checkSnapshot();
        }, delay);
    }

    toggleDetails() {
        if (!this.toggleDetailsBtn || !this.detailsSection) return;
        const isHidden = this.detailsSection.classList.contains('hidden');
        if (isHidden) {
            this.detailsSection.classList.remove('hidden');
            this.toggleDetailsBtn.textContent = '隐藏明细';
        } else {
            this.detailsSection.classList.add('hidden');
            this.toggleDetailsBtn.textContent = '查看明细';
        }
    }

    restoreConfig() {
        try {
            const raw = localStorage.getItem(this.storageKey);
            if (!raw) {
                this.hasLocalAllocationConfig = false;
                return;
            }
            this.hasLocalAllocationConfig = true;
            const data = JSON.parse(raw);
            this.webhookUrl.value = data.webhookUrl || '';
            this.prepareThreshold.value = data.prepareThreshold ?? 3;
            this.rebalanceThreshold.value = data.rebalanceThreshold ?? 5;
            this.forceThreshold.value = data.forceThreshold ?? 7.5;
            this.allocations = Array.isArray(data.allocations) ? data.allocations : [];
            this.sequence = Number(data.sequence || 1);
        } catch (error) {
            console.warn('读取平衡提醒配置失败', error);
        }
    }

    async loadCategoryDefaults() {
        if (this.hasLocalAllocationConfig || this.allocations.length > 0) {
            return;
        }

        try {
            const res = await fetch('/api/asset-categories', {
                headers: {
                    'Accept': 'application/json',
                },
            });

            const categories = await res.json();
            if (!res.ok || !Array.isArray(categories) || categories.length === 0) return;

            this.allocations = categories.map((category, index) => ({
                id: category.id || `category-${index + 1}`,
                name: category.name || `格子 ${index + 1}`,
                target_pct: this.parseNumber(category.target_pct, 0),
                symbols: Array.isArray(category.symbols) ? category.symbols : [],
            }));

            this.saveConfig();
        } catch (error) {
            console.warn('读取分类默认占比失败', error);
        }
    }

    saveConfig() {
        localStorage.setItem(this.storageKey, JSON.stringify({
            webhookUrl: this.webhookUrl.value.trim(),
            prepareThreshold: this.parseNumber(this.prepareThreshold.value, 3),
            rebalanceThreshold: this.parseNumber(this.rebalanceThreshold.value, 5),
            forceThreshold: this.parseNumber(this.forceThreshold.value, 7.5),
            allocations: this.allocations,
            sequence: this.sequence,
        }));
    }

    parseNumber(value, fallback) {
        const num = Number(value);
        return Number.isFinite(num) && num >= 0 ? num : fallback;
    }

    getThresholds() {
        const prepare = this.parseNumber(this.prepareThreshold.value, 3);
        const rebalance = this.parseNumber(this.rebalanceThreshold.value, 5);
        const force = this.parseNumber(this.forceThreshold.value, 7.5);

        if (!(prepare <= rebalance && rebalance <= force)) {
            throw new Error('阈值需满足：准备 <= 平衡 <= 强制平衡');
        }

        return { prepare_threshold: prepare, rebalance_threshold: rebalance, force_threshold: force };
    }

    createAllocation(name, symbols) {
        const normalizedSymbols = [...new Set((symbols || []).map((s) => String(s || '').toUpperCase().trim()).filter(Boolean))];
        return {
            id: `alloc-${this.sequence++}`,
            name: name || (normalizedSymbols.length === 1 ? normalizedSymbols[0] : `组合 ${this.sequence - 1}`),
            target_pct: 0,
            symbols: normalizedSymbols,
        };
    }

    addAllocation() {
        this.allocations.push(this.createAllocation(`格子 ${this.allocations.length + 1}`, []));
        this.saveConfig();
        this.renderAllocationList();
    }

    createSingleCoinAllocation(symbol) {
        const clean = String(symbol).toUpperCase().trim();
        if (!clean) return;

        this.allocations = this.allocations.filter((item) => {
            const symbols = (item.symbols || []).map((s) => String(s).toUpperCase().trim()).filter(Boolean);
            return !symbols.includes(clean);
        });

        this.allocations.push({
            id: `alloc-${this.sequence++}`,
            name: clean,
            target_pct: 0,
            symbols: [clean],
        });

        this.saveConfig();
        this.renderAllocationList();
    }

    findAllocation(id) {
        return this.allocations.find((item) => item.id === id);
    }

    deleteAllocation(id) {
        const index = this.allocations.findIndex((item) => item.id === id);
        if (index < 0) return;
        this.allocations.splice(index, 1);
    }

    assignSymbolToAllocation(symbol, allocationId) {
        if (!symbol) return;
        const clean = String(symbol).toUpperCase().trim();
        if (!clean) return;

        this.allocations.forEach((item) => {
            item.symbols = (item.symbols || []).filter((s) => String(s).toUpperCase() !== clean);
        });

        const allocation = this.findAllocation(allocationId);
        if (allocation) {
            allocation.symbols = [...new Set([...(allocation.symbols || []), clean])];
            if (!allocation.name || allocation.name.startsWith('格子 ') || allocation.name.startsWith('单币格子')) {
                allocation.name = allocation.symbols.length === 1 ? allocation.symbols[0] : allocation.name;
            }
        }

        this.allocations = this.allocations.filter((item) => (item.symbols || []).length > 0 || item.name.startsWith('格子'));
        this.saveConfig();
        this.renderAllocationList();
    }

    unassignSymbol(symbol) {
        if (!symbol) return;
        const clean = String(symbol).toUpperCase().trim();
        if (!clean) return;

        this.allocations.forEach((item) => {
            item.symbols = (item.symbols || []).filter((s) => String(s).toUpperCase() !== clean);
        });

        this.allocations = this.allocations.filter((item) => (item.symbols || []).length > 0 || (item.name || '').trim() !== '');
        this.saveConfig();
        this.renderAllocationList();
    }

    getAllocationsPayload() {
        return this.allocations
            .map((item) => ({
                id: item.id,
                name: (item.name || '').trim() || (item.symbols.length === 1 ? item.symbols[0] : item.id),
                target_pct: this.parseNumber(item.target_pct, 0),
                symbols: [...new Set((item.symbols || []).map((s) => String(s || '').toUpperCase().trim()).filter(Boolean))],
            }))
            .filter((item) => item.symbols.length > 0 || (item.name || '').startsWith('格子'));
    }

    getKnownSymbols() {
        if (!this.snapshot || !Array.isArray(this.snapshot.known_symbols)) return [];
        return this.snapshot.known_symbols.filter(Boolean);
    }

    getUnassignedSymbols() {
        const known = new Set(this.getKnownSymbols());
        this.getAllocationsPayload().forEach((allocation) => {
            allocation.symbols.forEach((symbol) => known.delete(symbol));
        });
        return [...known];
    }

    renderAllocationList() {
        const knownSymbols = this.getKnownSymbols();
        const assigned = new Set(this.getAllocationsPayload().flatMap((item) => item.symbols));
        const poolSymbols = knownSymbols.filter((symbol) => !assigned.has(symbol));

        this.tokenPool.innerHTML = poolSymbols.map((symbol) => this.renderTokenChip(symbol)).join('') || '<span class="text-xs text-slate-500">暂无未分配币种</span>';
        this.enableTokenDrag();

        this.allocationList.innerHTML = this.allocations.length > 0 ? this.allocations.map((item) => {
            const chips = (item.symbols || []).map((symbol) => this.renderTokenChip(symbol)).join('');
            return `
                <div data-allocation-id="${item.id}" class="px-4 py-3 bg-slate-950">
                    <div class="grid grid-cols-[minmax(0,1fr)_140px_90px] gap-3 items-center">
                        <input type="text" value="${item.name || ''}" class="allocation-name-input w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm" placeholder="单币/组合名称" />
                        <input type="number" step="0.1" min="0" value="${Number(item.target_pct || 0).toFixed(2)}" class="allocation-target-input w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm text-right" />
                        <button data-allocation-id="${item.id}" class="delete-allocation-btn px-2 py-1.5 rounded-lg border border-red-500/40 text-xs text-red-300 hover:bg-red-500/10">删除</button>
                    </div>
                    <div class="mt-3" data-allocation-id="${item.id}">
                        <div data-allocation-id="${item.id}" class="allocation-drop-zone min-h-12 border border-dashed border-slate-700 rounded-lg p-2 flex flex-wrap gap-2">
                        ${chips || '<span class="text-xs text-slate-500">拖币种到这里，或创建后再拖</span>'}
                        </div>
                    </div>
                    <div class="mt-2 text-[11px] text-slate-500">${(item.symbols || []).length <= 1 ? '单币' : '组合'} · ${(item.symbols || []).join(', ') || '空'}</div>
                </div>
            `;
        }).join('') : '<div class="px-4 py-6 text-slate-500 text-sm">暂无列表项。把未分配币种拖到这里，或点击“新增格子”创建单币/组合格子。</div>';

        this.enableTokenDrag();
        this.updateTargetSum();
    }

    renderTokenChip(symbol) {
        return `<span draggable="true" data-symbol="${symbol}" class="drag-token inline-flex items-center px-2 py-1 rounded-md bg-slate-800 text-slate-200 text-xs cursor-grab">${symbol}</span>`;
    }

    enableTokenDrag() {
        document.querySelectorAll('.drag-token').forEach((el) => {
            el.addEventListener('dragstart', (event) => {
                const symbol = event.target.dataset.symbol;
                this.draggingSymbol = symbol || null;
                if (event.dataTransfer && symbol) {
                    event.dataTransfer.setData('text/plain', symbol);
                    event.dataTransfer.effectAllowed = 'move';
                }
            });
            el.addEventListener('dragend', () => {
                this.draggingSymbol = null;
            });
        });
    }

    updateTargetSum() {
        const sum = this.getAllocationsPayload().reduce((acc, item) => acc + item.target_pct, 0);
        this.targetSum.textContent = `${sum.toFixed(2)}%`;
        this.targetSum.className = sum > 0 ? 'font-bold text-amber-300' : 'font-bold text-white';
    }

    applyEqualTargets() {
        if (!this.allocations.length) return;
        const equal = 100 / this.allocations.length;
        this.allocations.forEach((item) => { item.target_pct = Number(equal.toFixed(4)); });
        this.saveConfig();
        this.renderAllocationList();
        this.checkSnapshot();
    }

    applyCurrentWeightsAsTargets() {
        if (!this.snapshot || !Array.isArray(this.snapshot.items)) return;
        const currentMap = new Map(this.snapshot.items.map((item) => [item.name, item.weight_pct]));
        this.allocations.forEach((item) => {
            if (currentMap.has(item.name)) item.target_pct = Number(currentMap.get(item.name) || 0);
        });
        this.saveConfig();
        this.renderAllocationList();
        this.checkSnapshot();
    }

    buildSnapshotPayload() {
        return {
            prepare_threshold: this.parseNumber(this.prepareThreshold.value, 3),
            rebalance_threshold: this.parseNumber(this.rebalanceThreshold.value, 5),
            force_threshold: this.parseNumber(this.forceThreshold.value, 7.5),
            allocations: this.getAllocationsPayload(),
        };
    }

    setStatus(type, text) {
        const map = {
            success: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
            warn: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
            error: 'border-red-500/40 bg-red-500/10 text-red-300',
            info: 'border-slate-600 bg-slate-900 text-slate-300',
        };

        this.statusBox.className = `rounded-xl border p-3 text-sm ${map[type] || map.info}`;
        this.statusBox.textContent = text;
        this.statusBox.classList.remove('hidden');
    }

    async checkSnapshot() {
        try {
            this.saveConfig();
            const res = await fetch('/api/balance-alert/snapshot', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify(this.buildSnapshotPayload()),
            });

            const data = await res.json();
            if (!res.ok || data.status !== 'success') throw new Error(data.message || '获取快照失败');

            this.snapshot = data;
            this.renderSnapshot(data);
            this.setStatus('success', '列表已更新。');
        } catch (error) {
            this.setStatus('error', error.message || '检查失败');
        } finally {
            this.checkBtn.disabled = false;
            this.checkBtn.textContent = '检查偏离';
        }
    }

    renderSnapshot(data) {
        this.preferredCurrency = localStorage.getItem('preferred_currency') === 'MYR' ? 'MYR' : 'USD';
        this.updateCurrencyLabels();

        const p = data.portfolio || {};
        const w = data.window || {};
        this.currentTotalValueUsd = Number(p.total_value || 0);
        this.totalValue.textContent = this.formatMoneyByPreference(this.currentTotalValueUsd);
        this.tokenCount.textContent = String(p.allocation_count || 0);
        this.defaultTargetWeight.textContent = `${Number(p.default_target_pct || 0).toFixed(2)}%`;
        this.maxDeviation.textContent = `${Number(p.max_deviation_pct || 0).toFixed(2)}%`;
        this.updateTargetSum();

        if (w.in_rebalance_window) {
            this.windowBadge.className = 'text-xs px-3 py-1 rounded-full border border-emerald-500/40 text-emerald-300 bg-emerald-500/10';
            this.windowBadge.textContent = '窗口状态：季度下旬（可执行平衡）';
        } else {
            this.windowBadge.className = 'text-xs px-3 py-1 rounded-full border border-amber-500/40 text-amber-300 bg-amber-500/10';
            this.windowBadge.textContent = '窗口状态：非季度下旬';
        }

        const levelMap = {
            force: { title: '强制平衡', cls: 'border-red-500/40 bg-red-500/10' },
            rebalance: { title: '执行平衡', cls: 'border-amber-500/40 bg-amber-500/10' },
            prepare: { title: '准备资金', cls: 'border-sky-500/40 bg-sky-500/10' },
            none: { title: '无需提醒', cls: 'border-slate-700 bg-slate-900' },
        };

        const levelInfo = levelMap[data.level] || levelMap.none;
        this.levelBanner.className = `rounded-2xl border p-4 mb-5 ${levelInfo.cls}`;
        this.levelTitle.textContent = levelInfo.title;
        this.levelMessage.textContent = data.message || '';
        this.renderDetailsTable(data.items || [], data.advice || {});

        this.saveConfig();
        this.renderAllocationList();
    }

    renderDetailsTable(items, advice = {}) {
        if (!this.tokensBody) return;

        if (!Array.isArray(items) || items.length === 0) {
            this.tokensBody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">暂无数据</td></tr>';
            return;
        }

        this.tokensBody.innerHTML = items.map((item) => {
            const name = item.name || '-';
            const valueUsd = Number(item.value || 0);
            const valueDisplay = this.formatMoneyByPreference(valueUsd);
            const weightPct = Number(item.weight_pct || 0).toFixed(2);
            const targetPctNum = Number(item.target_pct || 0);
            const targetPct = targetPctNum.toFixed(2);
            const deviationPct = Number(item.deviation_pct || 0).toFixed(2);
            const absDeviationPct = Number(item.abs_deviation_pct || 0).toFixed(2);
            const adviceUsd = Number(item.advice_usd ?? 0);
            const adviceAction = String(item.advice_action || (adviceUsd > 0 ? 'buy' : (adviceUsd < 0 ? 'sell' : 'hold')));
            const adviceText = adviceAction === 'buy'
                ? `买入 ${Math.abs(adviceUsd).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                : adviceAction === 'sell'
                    ? `卖出 ${Math.abs(adviceUsd).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                    : '无需调仓';
            const rebalanceClass = adviceAction === 'buy'
                ? 'text-emerald-300'
                : adviceAction === 'sell'
                    ? 'text-amber-300'
                    : 'text-slate-400';

            return `
                <tr class="hover:bg-slate-900/50">
                    <td class="px-4 py-3 text-slate-200">${name}</td>
                    <td class="px-4 py-3 text-right text-slate-300">${valueDisplay}</td>
                    <td class="px-4 py-3 text-right text-slate-300">${weightPct}%</td>
                    <td class="px-4 py-3 text-right text-slate-300">${targetPct}%</td>
                    <td class="px-4 py-3 text-right text-slate-300">${deviationPct}%</td>
                    <td class="px-4 py-3 text-right text-slate-300">${absDeviationPct}%</td>
                    <td class="px-4 py-3 text-right ${rebalanceClass}">${adviceText}</td>
                </tr>
            `;
        }).join('');

        this.renderSummaryRow(advice);
    }

    renderSummaryRow(advice) {
        if (!this.summaryCell) return;

        const summary = advice?.summary || {};
        const totalBuy = Number(summary.buy_usd || 0);
        const totalSell = Number(summary.sell_usd || 0);
        const summaryText = summary.text || '无需调仓';

        this.summaryCell.textContent = summaryText;
        this.summaryCell.className = (totalBuy > 0 && totalSell > 0)
            ? 'px-4 py-3 text-right text-sky-300 font-semibold'
            : 'px-4 py-3 text-right text-slate-400 font-semibold';
    }

    buildThresholdRebalancePlan(items, triggerThreshold) {
        const rows = (Array.isArray(items) ? items : []).map((item) => {
            const valueUsd = Number(item.value || 0);
            const targetPct = Number(item.target_pct || 0);
            const targetValueUsd = this.currentTotalValueUsd * (targetPct / 100);
            const deltaUsd = targetValueUsd - valueUsd;
            return {
                id: item.id,
                deltaUsd,
                absDeviationPct: Number(item.abs_deviation_pct || 0),
            };
        });

        const triggered = rows.filter((row) => row.absDeviationPct >= triggerThreshold);
        const hasTrigger = triggered.length > 0;
        const result = new Map(rows.map((row) => [row.id, 0]));
        if (!hasTrigger) return result;

        let buyPool = triggered.filter((row) => row.deltaUsd > 0);
        let sellPool = triggered.filter((row) => row.deltaUsd < 0);

        // If trigger rows are one-sided, borrow the opposite side from all rows for proportional balancing.
        if (buyPool.length === 0) {
            buyPool = rows.filter((row) => row.deltaUsd > 0);
        }
        if (sellPool.length === 0) {
            sellPool = rows.filter((row) => row.deltaUsd < 0);
        }

        if (buyPool.length === 0 || sellPool.length === 0) {
            return result;
        }

        const totalBuyDemand = buyPool.reduce((sum, row) => sum + row.deltaUsd, 0);
        const totalSellSupply = sellPool.reduce((sum, row) => sum + Math.abs(row.deltaUsd), 0);
        const tradeVolume = Math.min(totalBuyDemand, totalSellSupply);

        if (tradeVolume <= 0) {
            return result;
        }

        buyPool.forEach((row) => {
            const amount = tradeVolume * (row.deltaUsd / totalBuyDemand);
            result.set(row.id, amount);
        });

        sellPool.forEach((row) => {
            const amount = -tradeVolume * (Math.abs(row.deltaUsd) / totalSellSupply);
            result.set(row.id, amount);
        });

        return result;
    }

    async sendAlert() {
        try {
            this.saveConfig();
            const webhook = this.webhookUrl.value.trim();
            if (!webhook) throw new Error('请先输入 Discord webhook');

            this.sendBtn.disabled = true;
            this.sendBtn.textContent = '发送中...';

            const res = await fetch('/api/balance-alert/notify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    webhook_url: webhook,
                    ...this.buildSnapshotPayload(),
                }),
            });

            const data = await res.json();
            if (!res.ok || data.status !== 'success') throw new Error(data.message || '发送失败');

            if (data.snapshot) {
                this.snapshot = data.snapshot;
                this.renderSnapshot(data.snapshot);
            }

            this.setStatus('success', 'Discord 提醒已发送。');
        } catch (error) {
            this.setStatus('error', error.message || '发送失败');
        } finally {
            this.sendBtn.disabled = false;
            this.sendBtn.textContent = '发送 Discord 提醒';
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new BalanceAlertPage();
});
