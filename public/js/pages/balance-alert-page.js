class BalanceAlertPage {
    constructor() {
        this.storageKey = 'balance_alert_config_v4';
        this.symbolTargetsStorageKey = 'balance_alert_symbol_targets_v1';
        this.snapshot = null;
        this.allocations = [];
        this.symbolTargets = {};
        this.expandedDetailGroups = new Set();
        this.draggingSymbol = null;
        this.autoRefreshTimer = null;
        this.preferredCurrency = 'USD';
        this.exchangeRate = 4.2;
        this.currentTotalValueUsd = 0;
        this.categoryApiBase = '/api/asset-categories';
        this.settingsApiBase = '/api/balance-alert/settings';
        this.dirtyAllocationIds = new Set();
        this.pendingDeleteIds = new Set();
        this.persistTimer = null;
        this.isPersistingAllocations = false;
        this.persistQueued = false;

        this.bindElements();
        this.restoreConfig();
        this.initialize();
    }

    closeSymbolTargets() {
        if (!this.symbolTargetsModal) return;
        this.symbolTargetsModal.classList.add('hidden');
        this.symbolTargetsModal.classList.remove('flex');
        this.symbolTargetsModal.style.zIndex = '';
        this.currentEditingAllocationId = null;
    }

    toggleDetailGroup(groupId) {
        const id = String(groupId || '').trim();
        if (!id) return;

        if (this.expandedDetailGroups.has(id)) {
            this.expandedDetailGroups.delete(id);
        } else {
            this.expandedDetailGroups.add(id);
        }

        this.syncDetailGroupVisibility();
    }

    syncDetailGroupVisibility() {
        if (!this.tokensBody) return;

        const expanded = this.expandedDetailGroups;
        this.tokensBody.querySelectorAll('[data-detail-group-id]').forEach((groupRow) => {
            const groupId = groupRow.dataset.detailGroupId;
            const isExpanded = expanded.has(groupId);
            groupRow.classList.toggle('bg-slate-900/60', isExpanded);
            const toggleBtn = groupRow.querySelector('[data-detail-toggle]');
            if (toggleBtn) {
                toggleBtn.textContent = isExpanded ? '⌄' : '>';
                toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            }

            groupRow.querySelectorAll('[data-summary-cell]').forEach((cell) => {
                cell.classList.toggle('hidden', isExpanded);
            });
        });

        this.tokensBody.querySelectorAll('[data-detail-parent]').forEach((detailRow) => {
            const groupId = detailRow.dataset.detailParent;
            detailRow.classList.toggle('hidden', !expanded.has(groupId));
        });
    }

    saveSymbolTargets() {
        if (!this.symbolTargetsList || !this.currentEditingAllocationId) return;
        const inputs = Array.from(this.symbolTargetsList.querySelectorAll('input[data-symbol]'));
        let changed = false;
        inputs.forEach((inp) => {
            const sym = String(inp.dataset.symbol || '').toUpperCase();
            const val = inp.value === '' ? 0 : this.parseNumber(inp.value, 0);
            if (val > 0) {
                if (Number(this.symbolTargets?.[sym] || 0) !== val) {
                    changed = true;
                }
                this.symbolTargets[sym] = val;
            } else {
                if (Object.prototype.hasOwnProperty.call(this.symbolTargets || {}, sym)) {
                    changed = true;
                }
                delete this.symbolTargets[sym];
            }
        });

        if (this.currentEditingAllocationId) {
            this.markAllocationDirty(this.currentEditingAllocationId);
        }

        // Keep DB in sync even when the user resets all symbol targets to 0.
        if (changed) {
            this.schedulePersistAllocations();
        }

        this.persistSymbolTargets();
        this.setStatus('success', '已保存币种细分设置（本地）。');
        this.renderAllocationList();
        this.scheduleSnapshotRefresh();
        this.closeSymbolTargets();
    }

    async initialize() {
        await this.initCurrencyPreference();
        await this.loadCategoryDefaults();
        await this.syncWebhookSetting();
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
        this.symbolTargetsModal = document.getElementById('symbolTargetsModal');
        this.symbolTargetsList = document.getElementById('symbolTargetsList');
        this.symbolTargetsTitle = document.getElementById('symbolTargetsTitle');
        this.closeSymbolTargetsBtn = document.getElementById('closeSymbolTargetsBtn');
        this.saveSymbolTargetsBtn = document.getElementById('saveSymbolTargetsBtn');
        this.cancelSymbolTargetsBtn = document.getElementById('cancelSymbolTargetsBtn');
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
        if (this.closeSymbolTargetsBtn) this.closeSymbolTargetsBtn.addEventListener('click', () => this.closeSymbolTargets());
        if (this.cancelSymbolTargetsBtn) this.cancelSymbolTargetsBtn.addEventListener('click', () => this.closeSymbolTargets());
        if (this.saveSymbolTargetsBtn) this.saveSymbolTargetsBtn.addEventListener('click', () => this.saveSymbolTargets());
        this.allocationSettingsModal.addEventListener('click', (event) => {
            if (event.target === this.allocationSettingsModal) {
                this.closeAllocationSettings();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.closeReminderSettings();
                this.closeAllocationSettings();
                this.closeSymbolTargets();
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
            this.markAllocationDirty(allocation.id);
            this.schedulePersistAllocations();
            this.scheduleSnapshotRefresh();
        });

        this.allocationList.addEventListener('click', async (event) => {
            const deleteBtn = event.target.closest('.delete-allocation-btn');
            if (!deleteBtn) return;
            const allocationId = deleteBtn.dataset.allocationId;
            try {
                await this.deleteAllocation(allocationId);
                this.saveConfig();
                this.renderAllocationList();
                this.checkSnapshot();
            } catch (error) {
                this.setStatus('error', error.message || '删除格子失败');
            }
        });

        // 编辑组合内币种细分（本地保存）
        this.allocationList.addEventListener('click', (event) => {
            const editBtn = event.target.closest('.edit-symbol-targets-btn');
            if (!editBtn) return;
            const allocationId = editBtn.dataset.allocationId;
            this.editSymbolTargets(allocationId);
        });

        this.allocationList.addEventListener('dragover', (event) => {
            event.preventDefault();
        });

        this.allocationList.addEventListener('drop', async (event) => {
            event.preventDefault();
            const symbol = (event.dataTransfer && event.dataTransfer.getData('text/plain')) || this.draggingSymbol;
            const dropZone = event.target.closest('.allocation-drop-zone');
            if (dropZone) {
                const allocationId = dropZone.dataset.allocationId;
                await this.assignSymbolToAllocation(symbol, allocationId);
                return;
            }

            if (!symbol) return;
            await this.createSingleCoinAllocation(symbol);
        });

        if (this.tokensBody) {
            this.tokensBody.addEventListener('click', (event) => {
                const toggleBtn = event.target.closest('[data-detail-toggle]');
                if (!toggleBtn) return;
                this.toggleDetailGroup(toggleBtn.dataset.detailToggle);
            });
        }

        this.tokenPool.addEventListener('dragover', (event) => {
            event.preventDefault();
        });

        this.tokenPool.addEventListener('drop', async (event) => {
            event.preventDefault();
            const symbol = (event.dataTransfer && event.dataTransfer.getData('text/plain')) || this.draggingSymbol;
            await this.unassignSymbol(symbol);
        });

        this.webhookUrl.addEventListener('change', () => this.persistWebhookSetting());
        [this.prepareThreshold, this.rebalanceThreshold, this.forceThreshold].forEach((el) => {
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
            if (!raw) return;
            const data = JSON.parse(raw);
            this.webhookUrl.value = data.webhookUrl || '';
            this.prepareThreshold.value = data.prepareThreshold ?? 3;
            this.rebalanceThreshold.value = data.rebalanceThreshold ?? 5;
            this.forceThreshold.value = data.forceThreshold ?? 7.5;
            // restore symbol-level targets
            try {
                const rawTargets = localStorage.getItem(this.symbolTargetsStorageKey);
                if (rawTargets) this.symbolTargets = JSON.parse(rawTargets) || {};
            } catch (e) {
                this.symbolTargets = {};
            }
        } catch (error) {
            console.warn('读取平衡提醒配置失败', error);
        }
    }

    persistSymbolTargets() {
        try {
            localStorage.setItem(this.symbolTargetsStorageKey, JSON.stringify(this.symbolTargets || {}));
        } catch (e) {
            console.warn('保存币种细分配置失败', e);
        }
    }

    async loadCategoryDefaults() {
        try {
            const res = await fetch(this.categoryApiBase, {
                headers: {
                    'Accept': 'application/json',
                },
            });

            const categories = await res.json();
            if (!res.ok || !Array.isArray(categories)) return;

            // Merge persisted symbol_targets into local store
            try {
                (categories || []).forEach((category) => {
                    if (category && category.symbol_targets && typeof category.symbol_targets === 'object') {
                        Object.entries(category.symbol_targets).forEach(([sym, v]) => {
                            if (!sym) return;
                            const key = String(sym || '').toUpperCase();
                            const num = Number(v || 0);
                            if (Number.isFinite(num) && num > 0) this.symbolTargets[key] = num;
                        });
                    }
                });
            } catch (e) {
                console.warn('合并已存币种细分失败', e);
            }

            this.allocations = categories.map((category, index) => this.normalizeAllocation(category, index));
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
        }));
    }

    async syncWebhookSetting() {
        const localWebhook = this.webhookUrl.value.trim();

        try {
            const res = await fetch(this.settingsApiBase, {
                headers: {
                    'Accept': 'application/json',
                },
            });

            const data = await res.json();
            const storedWebhook = String(data?.data?.webhook_url || '').trim();

            if (!localWebhook && storedWebhook) {
                this.webhookUrl.value = storedWebhook;
                this.saveConfig();
                return;
            }

            if (localWebhook && localWebhook !== storedWebhook) {
                await this.persistWebhookSetting();
            }
        } catch (error) {
            if (localWebhook) {
                await this.persistWebhookSetting();
            }
        }
    }

    async persistWebhookSetting() {
        this.saveConfig();

        const webhookUrl = this.webhookUrl.value.trim();
        try {
            const res = await fetch(this.settingsApiBase, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({ webhook_url: webhookUrl }),
            });

            const data = await res.json();
            if (!res.ok || data.status !== 'success') {
                throw new Error(data.message || '保存 webhook 失败');
            }
        } catch (error) {
            console.warn('保存平衡提醒 webhook 失败', error);
        }
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

    normalizeSymbols(symbols) {
        return [...new Set((Array.isArray(symbols) ? symbols : [])
            .map((symbol) => String(symbol || '').toUpperCase().trim())
            .filter(Boolean))];
    }

    normalizeAllocation(category, index = 0) {
        const symbols = this.normalizeSymbols(category.symbols || []);
        const fallbackName = symbols.length === 1 ? symbols[0] : `格子 ${index + 1}`;

        return {
            id: String(category.id || ''),
            name: String(category.name || fallbackName).trim() || fallbackName,
            target_pct: this.parseNumber(category.target_pct, 0),
            symbols,
        };
    }

    editSymbolTargets(allocationId) {
        const allocation = this.findAllocation(String(allocationId));
        if (!allocation) return;
        const symbols = allocation.symbols || [];
        if (!Array.isArray(symbols) || symbols.length <= 1) {
            alert('仅包含多个币种的组合支持细分设置。');
            return;
        }

        // populate modal
        this.currentEditingAllocationId = allocationId;
        this.symbolTargetsTitle.textContent = `细分设置 · ${allocation.name || allocation.id}`;
        this.symbolTargetsList.innerHTML = '';
        symbols.forEach((sym) => {
            const val = Number(this.symbolTargets?.[sym] || 0);
            const row = document.createElement('div');
            row.className = 'flex items-center gap-3';
            row.innerHTML = `
                <div class="w-1/3 text-slate-300">${sym}</div>
                <input data-symbol="${sym}" type="number" step="0.01" min="0" value="${val > 0 ? val.toFixed(2) : ''}" class="w-2/3 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm" />
            `;
            this.symbolTargetsList.appendChild(row);
        });

        this.symbolTargetsModal.classList.remove('hidden');
        this.symbolTargetsModal.classList.add('flex');
        this.symbolTargetsModal.style.zIndex = '60';
    }

    markAllocationDirty(id) {
        if (!id) return;
        this.dirtyAllocationIds.add(String(id));
    }

    schedulePersistAllocations(delay = 700) {
        if (this.persistTimer) {
            clearTimeout(this.persistTimer);
        }

        this.persistTimer = setTimeout(() => {
            this.persistTimer = null;
            this.flushAllocationPersistence();
        }, delay);
    }

    async flushAllocationPersistence() {
        if (this.isPersistingAllocations) {
            this.persistQueued = true;
            return;
        }

        this.isPersistingAllocations = true;
        const dirtyIds = [...this.dirtyAllocationIds];
        const deleteIds = [...this.pendingDeleteIds];
        this.dirtyAllocationIds.clear();
        this.pendingDeleteIds.clear();

        try {
            for (const id of deleteIds) {
                if (!id) continue;

                const res = await fetch(`${this.categoryApiBase}/${encodeURIComponent(id)}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'Accept': 'application/json',
                    },
                });

                if (!res.ok) {
                    throw new Error('删除类别失败');
                }
            }

            for (const id of dirtyIds) {
                const allocation = this.findAllocation(id);
                if (!allocation) continue;

                // build symbol_targets payload for this allocation from local symbolTargets store
                const symbolTargetsForAllocation = {};
                (allocation.symbols || []).forEach((s) => {
                    const v = Number(this.symbolTargets?.[s] || 0);
                    if (Number.isFinite(v) && v > 0) symbolTargetsForAllocation[s] = v;
                });

                const res = await fetch(`${this.categoryApiBase}/${encodeURIComponent(id)}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({
                        name: (allocation.name || '').trim() || id,
                        target_pct: this.parseNumber(allocation.target_pct, 0),
                        symbols: this.normalizeSymbols(allocation.symbols || []),
                        symbol_targets: symbolTargetsForAllocation,
                    }),
                });

                if (!res.ok) {
                    throw new Error('更新类别失败');
                }
            }
        } catch (error) {
            this.setStatus('warn', '同步分类配置到数据库失败，已保留本地编辑。');
        } finally {
            this.isPersistingAllocations = false;
            if (this.persistQueued) {
                this.persistQueued = false;
                this.schedulePersistAllocations(80);
            }
        }
    }

    async createCategory(payload) {
        const res = await fetch(this.categoryApiBase, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                name: String(payload.name || '').trim(),
                target_pct: this.parseNumber(payload.target_pct, 0),
                symbols: this.normalizeSymbols(payload.symbols || []),
            }),
        });

        const data = await res.json();
        if (!res.ok || data.status !== 'success') {
            throw new Error(data.message || '新增类别失败');
        }

        return this.normalizeAllocation(data.data || payload, this.allocations.length);
    }

    async addAllocation() {
        try {
            const created = await this.createCategory({
                name: `格子 ${this.allocations.length + 1}`,
                target_pct: 0,
                symbols: [],
            });

            if (!created.id) {
                await this.loadCategoryDefaults();
            } else {
                this.allocations.push(created);
            }

            this.renderAllocationList();
            this.scheduleSnapshotRefresh(120);
        } catch (error) {
            this.setStatus('error', error.message || '新增格子失败');
        }
    }

    async createSingleCoinAllocation(symbol) {
        const clean = String(symbol).toUpperCase().trim();
        if (!clean) return;

        const changedIds = [];
        this.allocations.forEach((item) => {
            const before = this.normalizeSymbols(item.symbols || []);
            const after = before.filter((current) => current !== clean);
            if (before.length !== after.length) {
                item.symbols = after;
                changedIds.push(item.id);
            }
        });

        try {
            const created = await this.createCategory({
                name: clean,
                target_pct: 0,
                symbols: [clean],
            });
            this.allocations.push(created);
            this.compactEmptyAllocations();
            changedIds.forEach((id) => this.markAllocationDirty(id));
            this.schedulePersistAllocations(100);
            this.renderAllocationList();
            this.scheduleSnapshotRefresh(120);
        } catch (error) {
            this.setStatus('error', error.message || '创建单币格子失败');
            await this.loadCategoryDefaults();
            this.renderAllocationList();
        }
    }

    findAllocation(id) {
        return this.allocations.find((item) => item.id === id);
    }

    async deleteAllocation(id) {
        const index = this.allocations.findIndex((item) => item.id === id);
        if (index < 0) return;

        const res = await fetch(`${this.categoryApiBase}/${encodeURIComponent(id)}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Accept': 'application/json',
            },
        });

        if (!res.ok) {
            throw new Error('删除类别失败');
        }

        this.allocations.splice(index, 1);
    }

    compactEmptyAllocations() {
        const removedIds = [];
        this.allocations = this.allocations.filter((item) => {
            const hasSymbols = this.normalizeSymbols(item.symbols || []).length > 0;
            const hasName = String(item.name || '').trim() !== '';
            if (hasSymbols || hasName) {
                return true;
            }

            if (item.id) {
                removedIds.push(String(item.id));
            }

            return false;
        });

        removedIds.forEach((id) => this.pendingDeleteIds.add(id));
        return removedIds;
    }

    async assignSymbolToAllocation(symbol, allocationId) {
        if (!symbol) return;
        const clean = String(symbol).toUpperCase().trim();
        if (!clean) return;

        const changedIds = [];

        this.allocations.forEach((item) => {
            const before = this.normalizeSymbols(item.symbols || []);
            const after = before.filter((current) => current !== clean);
            if (before.length !== after.length) {
                item.symbols = after;
                changedIds.push(item.id);
            }
        });

        const allocation = this.findAllocation(allocationId);
        if (allocation) {
            const symbols = this.normalizeSymbols([...(allocation.symbols || []), clean]);
            if (symbols.join('|') !== this.normalizeSymbols(allocation.symbols || []).join('|')) {
                allocation.symbols = symbols;
                changedIds.push(allocation.id);
            }
            if (!allocation.name || allocation.name.startsWith('格子 ') || allocation.name.startsWith('单币格子')) {
                allocation.name = allocation.symbols.length === 1 ? allocation.symbols[0] : allocation.name;
                changedIds.push(allocation.id);
            }
        }

        this.compactEmptyAllocations();
        changedIds.forEach((id) => this.markAllocationDirty(id));
        this.schedulePersistAllocations();
        this.saveConfig();
        this.renderAllocationList();
        this.scheduleSnapshotRefresh();
    }

    async unassignSymbol(symbol) {
        if (!symbol) return;
        const clean = String(symbol).toUpperCase().trim();
        if (!clean) return;

        const changedIds = [];

        this.allocations.forEach((item) => {
            const before = this.normalizeSymbols(item.symbols || []);
            const after = before.filter((current) => current !== clean);
            if (before.length !== after.length) {
                item.symbols = after;
                changedIds.push(item.id);
            }
        });

        this.compactEmptyAllocations();
        changedIds.forEach((id) => this.markAllocationDirty(id));
        this.schedulePersistAllocations();
        this.saveConfig();
        this.renderAllocationList();
        this.scheduleSnapshotRefresh();
    }

    getAllocationsPayload() {
        return this.allocations
            .map((item) => ({
                id: String(item.id || '').trim(),
                name: (item.name || '').trim() || (item.symbols.length === 1 ? item.symbols[0] : String(item.id || '')),
                target_pct: this.parseNumber(item.target_pct, 0),
                symbols: this.normalizeSymbols(item.symbols || []),
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
                    ${ (item.symbols || []).length > 1 ? `<div class="mt-2"><button data-allocation-id="${item.id}" class="edit-symbol-targets-btn px-2 py-1 rounded-md bg-slate-800 text-xs text-slate-200">细分</button></div>` : '' }
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
        this.allocations.forEach((item) => {
            item.target_pct = Number(equal.toFixed(4));
            this.markAllocationDirty(item.id);
        });
        this.saveConfig();
        this.renderAllocationList();
        this.schedulePersistAllocations();
        this.checkSnapshot();
    }

    applyCurrentWeightsAsTargets() {
        if (!this.snapshot || !Array.isArray(this.snapshot.items)) return;
        const currentMap = new Map(this.snapshot.items.map((item) => [item.name, item.weight_pct]));
        this.allocations.forEach((item) => {
            if (currentMap.has(item.name)) {
                item.target_pct = Number(currentMap.get(item.name) || 0);
                this.markAllocationDirty(item.id);
            }
        });
        this.saveConfig();
        this.renderAllocationList();
        this.schedulePersistAllocations();
        this.checkSnapshot();
    }

    buildSnapshotPayload() {
        return {
            prepare_threshold: this.parseNumber(this.prepareThreshold.value, 3),
            rebalance_threshold: this.parseNumber(this.rebalanceThreshold.value, 5),
            force_threshold: this.parseNumber(this.forceThreshold.value, 7.5),
            allocations: this.getAllocationsPayload(),
            target_allocations: this.getSymbolTargetsPayload(),
        };
    }

    getSymbolTargetsPayload() {
        const rows = [];
        for (const [symbol, pct] of Object.entries(this.symbolTargets || {})) {
            const num = Number(pct || 0);
            if (Number.isFinite(num) && num > 0) {
                rows.push({ symbol: String(symbol).toUpperCase(), target_pct: Number(num) });
            }
        }
        return rows;
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

        const buildRow = (item, options = {}) => {
            const name = item.name || '-';
            const valueUsd = Number(item.value || 0);
            const valueDisplay = this.formatMoneyByPreference(valueUsd);
            const weightPct = Number(item.weight_pct || 0).toFixed(2);
            const targetPctNum = Number(item.target_pct || 0);
            const targetPct = targetPctNum.toFixed(2);
            const deviationNum = Number(item.deviation_pct || 0);
            const absDeviationNum = Number(item.abs_deviation_pct || 0);
            const children = Array.isArray(item.children) ? item.children : [];

            const deviationPct = `${deviationNum > 0 ? '+' : ''}${deviationNum.toFixed(2)}`;
            const deviationClass = deviationNum > 0
                ? 'text-emerald-300'
                : deviationNum < 0
                    ? 'text-red-300'
                    : 'text-slate-300';
            const absDeviationPct = absDeviationNum.toFixed(2);
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
            const childTag = options.isChild ? '<span class="ml-2 text-[10px] rounded-full border border-slate-700 px-2 py-0.5 text-slate-500">明细</span>' : '';
            // Use flex with fixed-width container for toggle button to ensure alignment
            const toggleButton = options.hasChildren
                ? `<button type="button" data-detail-toggle="${item.id}" class="inline-flex h-5 w-5 items-center justify-center rounded border border-slate-700 text-[11px] text-slate-300 hover:border-slate-500 hover:text-white flex-shrink-0">${this.expandedDetailGroups.has(String(item.id)) ? '⌄' : '>'}</button>`
                : '<span class="inline-block w-5 flex-shrink-0"></span>';

            return `
                <tr data-detail-group-id="${item.id}" class="hover:bg-slate-900/50 ${this.expandedDetailGroups.has(String(item.id)) ? 'bg-slate-900/40' : ''}">
                    <td class="px-4 py-3 text-slate-200">
                        <div class="flex items-center gap-2">
                            ${toggleButton}
                            <span class="font-medium">${name}</span>
                            ${childTag}
                        </div>
                    </td>
                    <td data-summary-cell class="px-4 py-3 text-right text-slate-300">${valueDisplay}</td>
                    <td data-summary-cell class="px-4 py-3 text-right text-slate-300">${weightPct}%</td>
                    <td data-summary-cell class="px-4 py-3 text-right text-slate-300">${targetPct}%</td>
                    <td data-summary-cell class="px-4 py-3 text-right ${deviationClass}">${deviationPct}%</td>
                    <td data-summary-cell class="px-4 py-3 text-right text-slate-300">${absDeviationPct}%</td>
                    <td data-summary-cell class="px-4 py-3 text-right ${rebalanceClass}">${adviceText}</td>
                </tr>
            `;
        };

        const rows = [];
        items.forEach((item) => {
            const children = Array.isArray(item.children) ? item.children : [];
            // hasChildren should be based on whether there are actually multiple children (breakdown), not just symbols count
            const hasChildren = children.length > 1;
            rows.push(buildRow(item, { hasChildren }));

            if (hasChildren) {
                children.forEach((child) => {
                    const childName = child.name || '-';
                    const childValueUsd = Number(child.value || 0);
                    const childValueDisplay = this.formatMoneyByPreference(childValueUsd);
                    const childWeightPct = Number(child.weight_pct || 0).toFixed(2);
                    const childTargetPct = Number(child.target_pct || 0).toFixed(2);
                    const childDeviationNum = Number(child.deviation_pct || 0);
                    const childDeviationPct = `${childDeviationNum > 0 ? '+' : ''}${childDeviationNum.toFixed(2)}`;
                    const childDeviationClass = childDeviationNum > 0
                        ? 'text-emerald-300'
                        : childDeviationNum < 0
                            ? 'text-red-300'
                            : 'text-slate-300';
                    const childAbsDeviationPct = Number(child.abs_deviation_pct || 0).toFixed(2);
                    const childAdviceUsd = Number(child.advice_usd ?? 0);
                    const childAdviceAction = String(child.advice_action || (childAdviceUsd > 0 ? 'buy' : (childAdviceUsd < 0 ? 'sell' : 'hold')));
                    const childAdviceText = childAdviceAction === 'buy'
                        ? `买入 ${Math.abs(childAdviceUsd).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                        : childAdviceAction === 'sell'
                            ? `卖出 ${Math.abs(childAdviceUsd).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                            : '无需调仓';
                    const childRebalanceClass = childAdviceAction === 'buy'
                        ? 'text-emerald-300'
                        : childAdviceAction === 'sell'
                            ? 'text-amber-300'
                            : 'text-slate-400';

                    rows.push(`
                        <tr data-detail-parent="${item.id}" class="hidden bg-slate-950/70">
                            <td class="px-4 py-3 pl-8 text-slate-400">
                                <span class="inline-flex items-center gap-2">
                                    <span class="text-slate-600">└</span>
                                    ${childName}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-400">${childValueDisplay}</td>
                            <td class="px-4 py-3 text-right text-slate-400">${childWeightPct}%</td>
                            <td class="px-4 py-3 text-right text-slate-400">${childTargetPct}%</td>
                            <td class="px-4 py-3 text-right ${childDeviationClass}">${childDeviationPct}%</td>
                            <td class="px-4 py-3 text-right text-slate-400">${childAbsDeviationPct}%</td>
                            <td class="px-4 py-3 text-right ${childRebalanceClass}">${childAdviceText}</td>
                        </tr>
                    `);
                });
            }
        });

        this.tokensBody.innerHTML = rows.join('');
        this.syncDetailGroupVisibility();

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
