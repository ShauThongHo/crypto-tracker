// public/js/components/CapitalUI.js

export const CapitalUI = {
    /**
     * 渲染流水表格行
     */
    renderTableRow(log) {
        const isDep = log.type === 'DEPOSIT';
        const fiatAmount = parseFloat(log.fiat_amount) || 0;
        const usdtAmount = parseFloat(log.usdt_amount) || 0;

        return `
            <tr class="hover:bg-slate-800/30 group transition-colors border-b border-slate-800/50">
                <td class="px-6 py-4 font-mono text-xs text-slate-500">
                    ${log.transaction_date || '-'}
                </td>
                <td class="px-6 py-4">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold ${isDep ? 'bg-emerald-500/10 text-emerald-500' : 'bg-red-500/10 text-red-500'}">
                        ${isDep ? '入金' : '出金'}
                    </span>
                </td>
                <td class="px-6 py-4 text-right font-mono text-white">
                    ${fiatAmount.toLocaleString()} <span class="text-[10px] text-slate-500">${log.fiat_currency || 'MYR'}</span>
                </td>
                <td class="px-6 py-4 text-right font-mono font-bold ${isDep ? 'text-emerald-400' : 'text-red-400'}">
                    ${isDep ? '+' : '-'}${usdtAmount.toFixed(2)}
                </td>
                <td class="px-6 py-4 text-right">
                    <button data-id="${log.id}" class="delete-cap-btn p-1.5 text-slate-700 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </td>
            </tr>`;
    },

    /**
     * 渲染顶部统计卡片
     */
    renderStats(dep, wit) {
        const net = dep - wit;
        return `
            <div class="bg-slate-900/50 border border-slate-800 p-6 rounded-3xl">
                <div class="text-[10px] text-slate-500 uppercase font-bold mb-1">Total Invested (入金)</div>
                <div class="text-2xl font-mono text-emerald-400 font-bold">RM ${dep.toLocaleString()}</div>
            </div>
            <div class="bg-slate-900/50 border border-slate-800 p-6 rounded-3xl">
                <div class="text-[10px] text-slate-500 uppercase font-bold mb-1">Total Withdrawn (出金)</div>
                <div class="text-2xl font-mono text-red-400 font-bold">RM ${wit.toLocaleString()}</div>
            </div>
            <div class="bg-sky-500/10 border border-sky-500/20 p-6 rounded-3xl">
                <div class="text-[10px] text-sky-500 uppercase font-bold mb-1">Net Capital (净本金)</div>
                <div class="text-2xl font-mono text-white font-bold">RM ${net.toLocaleString()}</div>
            </div>
        `;
    }
};