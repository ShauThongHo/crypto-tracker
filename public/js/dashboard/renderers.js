/**
 * renderers.js — 投资组合卡片渲染、ECharts 图表、资产占比计算
 * 依赖: globals.js, formatting.js
 * 加载顺序: 第 4 个
 */

// ==========================================
// Portfolio 渲染 (Index Page)
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

    // Use data as-is; server-side filtering already applied based on user preference.
    const filteredData = data;

    (filteredData.children || []).forEach((source, index) => {
        const isFull = (index % 2 === 0 && index === filteredData.children.length - 1);
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
        deactivate(walletsBtn); activate(assetsBtn); deactivate(categoriesBtn);
    } else if (currentBreakdownView === 'categories') {
        deactivate(walletsBtn); deactivate(assetsBtn); activate(categoriesBtn);
    } else {
        activate(walletsBtn); deactivate(assetsBtn); deactivate(categoriesBtn);
    }
}

// ==========================================
// 资产占比卡片
// ==========================================

function buildAssetAllocationCard(data) {
    const allocations = calculateAssetAllocations(data);
    const hideLowValue = localStorage.getItem('hide_low_value_assets_enabled') === '1';
    const LOW_VALUE_THRESHOLD = 1;
    const filtered = hideLowValue ? allocations.filter((item) => (parseFloat(item.value) || 0) >= LOW_VALUE_THRESHOLD) : allocations;
    if (filtered.length === 0) {
        return '<div class="text-center py-12"><div class="text-slate-400 text-sm">暂无资产可用于占比分析</div></div>';
    }

    const topPct = filtered[0].percentage;
    const rows = filtered.map((item, index) => {
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
        <div class="mt-4 flex flex-col gap-3">${rows}</div>`;
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

// ==========================================
// 类别占比卡片
// ==========================================

function buildCategoryAllocationCard(data) {
    const allocations = calculateCategoryAllocations(data);
    const uncategorizedSymbols = getUncategorizedSymbols(data);
    if (allocations.length === 0) {
        return '<div class="text-center py-12"><div class="text-slate-400 text-sm">暂无类别可用于占比分析</div></div>';
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
                ${canManage ? '<div class="mt-3 text-[11px] text-slate-500">将币种直接拖到这个类别卡片即可</div>' : ''}
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
                <p class="text-slate-400 text-xs md:text-sm mt-1">按你创建的类别汇总币种总占比，例如"激进"类别中的 BTC、CRO 会自动合并计算。</p>
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
        <div class="mt-4 flex flex-col gap-3">${rows}</div>`;
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
            id: categoryId, name, value,
            count: matchedSymbols.length,
            symbols: configuredSymbols,
            manageable: true,
        });
    });

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

// ==========================================
// ECharts 走势图 (Index Page)
// ==========================================

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
            trigger: 'axis',
            backgroundColor: '#0f172a',
            borderColor: '#1e293b',
            textStyle: { color: '#fff' },
            formatter: (params) => {
                const point = params && params[0] ? params[0] : null;
                if (!point) return '';
                const timestamp = formatChartTimestamp(point.value[0], granularity, true);
                const seriesRows = params.map(item =>
                    `<div class="flex justify-between gap-6"><span class="text-slate-400">${item.seriesName}</span><span class="font-mono">${formatChartMoney(item.value[1])}</span></div>`
                ).join('');
                return `<div class="font-bold mb-2">${timestamp}</div>${seriesRows}`;
            },
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
                showSymbol: false,
                symbol: 'circle',
                symbolSize: 8,
                emphasis: {
                    focus: 'series',
                    itemStyle: { color: '#38bdf8', borderColor: '#fff', borderWidth: 2 }
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
                showSymbol: false,
                symbol: 'circle',
                symbolSize: 8,
                emphasis: {
                    focus: 'series',
                    itemStyle: { color: '#f59e0b', borderColor: '#fff', borderWidth: 2 }
                },
                z: 1
            }
        ]
    }, true);
}

// ==========================================
// 盈亏日历渲染 (History Page)
// ==========================================

function renderCalendarHistory(data) {
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

    // Apply currency conversion if needed
    if (isMYR) {
        calendarSeriesData = calendarSeriesData.map(item => [
            item[0], item[1] * MYR_RATE, item[2], item[3] * MYR_RATE, item[4]
        ]);
    }

    const currentYear = new Date().getFullYear();

    const commonTooltip = {
        backgroundColor: '#0f172a',
        textStyle: { color: '#f8fafc' },
        formatter: (p) => {
            const [date, pnl, pct, total, has] = p.value;
            if (!has && total === 0) return `${date}<br>无数据`;
            const color = pnl >= 0 ? 'text-emerald-400' : 'text-rose-400';
            return `<div class="p-2"><b>${date}</b><br>总额: ${formatChartMoney(total)}<br>盈亏: <span class="${color}">${pnl >= 0 ? '+' : ''}${formatChartMoney(pnl)} (${pct.toFixed(2)}%)</span></div>`;
        }
    };

    // Year calendar heatmap
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
            dayLabel: { color: '#64748b', nameMap: 'ZH' },
            monthLabel: { color: '#64748b', nameMap: 'ZH' },
            yearLabel: { show: false }
        },
        series: { type: 'heatmap', coordinateSystem: 'calendar', data: calendarSeriesData, itemStyle: { borderRadius: 4 } }
    }, true);

    // Month calendar
    const viewY = currentViewMonthDate.getFullYear();
    const viewM = currentViewMonthDate.getMonth() + 1;
    const monthTitle = document.getElementById('month-view-title');
    if (monthTitle) monthTitle.innerText = `${viewY}年 ${viewM}月 资产走势`;
    const mData = calendarSeriesData.filter(i => {
        const dObj = new Date(i[0]);
        return dObj.getFullYear() === viewY && (dObj.getMonth() + 1) === viewM;
    });

    historyMonthChart.setOption({
        tooltip: commonTooltip,
        visualMap: {
            dimension: 1, show: false, pieces: [
                { min: 0.01, color: 'rgba(16, 185, 129, 0.1)' },
                { min: -0.01, max: 0.01, color: 'rgba(15, 23, 42, 0.5)' },
                { max: -0.01, color: 'rgba(244, 63, 94, 0.1)' }
            ]
        },
        calendar: {
            top: 50, left: 20, right: 20, bottom: 20,
            orient: 'vertical',
            range: `${viewY}-${String(viewM).padStart(2, '0')}`,
            cellSize: ['auto', 'auto'],
            itemStyle: { color: '#0f172a', borderWidth: 1, borderColor: '#1e293b' },
            dayLabel: { color: '#94a3b8', margin: 15, nameMap: 'ZH', fontWeight: 'bold' },
            yearLabel: { show: false },
            monthLabel: { show: false }
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
                    formatter: (p) => p.value[1] === 0 ? '' : (p.value[1] > 0 ? '+' : '') + formatChartMoney(p.value[1])
                }
            },
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
