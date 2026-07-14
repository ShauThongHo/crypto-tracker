/**
 * formatting.js — 货币格式化、时间格式化、汇率刷新
 * 依赖: globals.js (isMYR, MYR_RATE)
 * 加载顺序: 第 2 个
 */

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
 * 图表专用格式化（数据已在传入前转换过汇率，只加符号和保留2位小数）
 */
function formatChartMoney(value) {
    const val = parseFloat(value) || 0;
    const prefix = isMYR ? 'RM ' : '$';
    return prefix + val.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * 格式化图表时间戳
 */
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
            console.log('Exchange rate updated:', MYR_RATE);
        }
    } catch (e) {
        console.warn('Rate fetch failed, using default 4.72');
    }
}
