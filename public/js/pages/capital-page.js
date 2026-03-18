import { CapitalService } from '../services/CapitalService.js';
import { CapitalUI } from '../components/CapitalUI.js';

class CapitalPage {
    constructor() {
        this.currentType = 'DEPOSIT';
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        this.init();
    }

    async init() {
        await this.loadDropdown();
        await this.refreshData();
        this.bindEvents();
    }

    async loadDropdown() {
        try {
            const data = await CapitalService.fetchAssets();
            const select = document.getElementById('cap_asset_id');
            let html = '<option value="">-- 选择存入目标 (仅 USDT) --</option>';

            data.children.forEach(source => {
                source.children.forEach(net => {
                    net.children.forEach(token => {
                        // 只展示 USDT 资产
                        if ((token.symbol || '').toUpperCase() !== 'USDT') return;

                        html += `<option value="${token.id}">${source.name} - ${token.symbol} (${token.label || '默认'})</option>`;
                    });
                });
            });

            if (html === '<option value="">-- 选择存入目标 (仅 USDT) --</option>') {
                html += '<option value="" disabled>未找到 USDT 资产</option>';
            }

            select.innerHTML = html;
        } catch (e) { console.error("加载下拉框失败", e); }
    }

    // public/js/pages/capital-page.js

    async refreshData() {
        try {
            const history = await CapitalService.fetchHistory();
            const body = document.getElementById('history-body');
            const statsContainer = document.getElementById('stats-container');

            if (!history || history.length === 0) {
                body.innerHTML = '<tr><td colspan="5" class="text-center py-10 text-slate-500">暂无流水记录</td></tr>';
                return;
            }

            let dep = 0, wit = 0;
            body.innerHTML = history.map(log => {
                // 安全转换数值
                const amount = parseFloat(log.fiat_amount) || 0;
                if (log.type === 'DEPOSIT') dep += amount;
                else wit += amount;

                // 🎯 调用 UI 渲染单行
                return CapitalUI.renderTableRow(log);
            }).join('');

            // 更新顶部统计
            if (statsContainer) {
                statsContainer.innerHTML = CapitalUI.renderStats(dep, wit);
            }
        } catch (e) {
            console.error("刷新列表失败:", e);
            document.getElementById('history-body').innerHTML = '<tr><td colspan="5" class="text-center py-10 text-red-500">加载失败，请检查 API 响应</td></tr>';
        }
    }

    bindEvents() {
        // 1. 方向切换逻辑
        document.querySelectorAll('.cap-type-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const type = e.currentTarget.dataset.type;
                this.currentType = type;
                document.getElementById('cap_type').value = type;

                document.querySelectorAll('.cap-type-btn').forEach(b => {
                    b.classList.remove('bg-emerald-500', 'bg-red-500', 'text-white');
                    b.classList.add('text-slate-400');
                });

                if (type === 'DEPOSIT') {
                    btn.classList.add('bg-emerald-500', 'text-white');
                    btn.classList.remove('text-slate-400');
                } else {
                    btn.classList.add('bg-red-500', 'text-white');
                    btn.classList.remove('text-slate-400');
                }
            });
        });

        // 2. 实时预览计算
        const updatePreview = () => {
            const amount = parseFloat(document.getElementById('fiat_amount').value) || 0;
            const rate = parseFloat(document.getElementById('usdt_rate').value) || 0;
            document.getElementById('preview_usdt').innerText = rate > 0 ? (amount / rate).toFixed(4) : "0.0000";
        };
        document.getElementById('fiat_amount').addEventListener('input', updatePreview);
        document.getElementById('usdt_rate').addEventListener('input', updatePreview);

        // 3. 提交表单
        const form = document.getElementById('capitalForm');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerText = '正在同步...';

            const payload = {
                asset_id: document.getElementById('cap_asset_id').value,
                type: this.currentType,
                fiat_amount: document.getElementById('fiat_amount').value,
                usdt_rate: document.getElementById('usdt_rate').value,
                fiat_currency: document.getElementById('fiat_currency').value,
                transaction_date: new Date().toISOString().split('T')[0]
            };

            try {
                const res = await fetch('/api/capital/record', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken
                    },
                    body: JSON.stringify(payload)
                });

                if (res.ok) {
                    alert('同步成功！');
                    form.reset();
                    updatePreview();
                    await this.refreshData();
                } else {
                    const err = await res.json();
                    alert('同步失败: ' + (err.message || '未知错误'));
                }
            } catch (err) {
                alert('网络请求失败，请检查服务器');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerText = '同步至账本';
            }
        });

        // 4. 🎯 删除记录逻辑 (使用事件委托)
        const historyBody = document.getElementById('history-body');
        historyBody.addEventListener('click', async (e) => {
            // 检查点击的是否是删除按钮或其图标
            const deleteBtn = e.target.closest('.delete-cap-btn');
            if (!deleteBtn) return;

            const id = deleteBtn.dataset.id;
            if (confirm('确定要删除这条流水记录吗？\n注意：删除流水不会自动回滚资产余额，需手动修正。')) {
                try {
                    const res = await CapitalService.deleteRecord(id);
                    if (res.ok) {
                        await this.refreshData(); // 重新加载数据
                    } else {
                        const err = await res.json();
                        alert('删除失败: ' + (err.message || '未知错误'));
                    }
                } catch (err) {
                    console.error(err);
                    alert('网络请求失败');
                }
            }
        });

        // 5. 刷新按钮逻辑
        const refreshBtn = document.getElementById('refreshBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.refreshData());
        }
    }
}

new CapitalPage();