/**
 * globals.js — 全局状态变量 + CacheManager + CSRF 工具
 * 依赖: 无
 * 加载顺序: 第 1 个
 */

// ==========================================
// 全局变量
// ==========================================
let isMYR = localStorage.getItem('preferred_currency') === 'MYR';
let MYR_RATE = 4.72; // placeholder — overwritten by refreshLiveExchangeRate() on DOM ready
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

// ==========================================
// CSRF Token 工具
// ==========================================
const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

// ==========================================
// 缓存管理
// ==========================================
const CacheManager = {
    set: (key, value) => {
        try {
            sessionStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            console.warn('Cache save failed:', e);
        }
    },
    get: (key) => {
        try {
            const item = sessionStorage.getItem(key);
            return item ? JSON.parse(item) : null;
        } catch (e) {
            console.warn('Cache read failed:', e);
            return null;
        }
    },
    clear: (key) => {
        sessionStorage.removeItem(key);
    },
    clearAllSnapshotCache: () => {
        ['1D', '7D', '30D', 'ALL'].forEach(range => {
            CacheManager.clear(`snapshotData_${range}`);
        });
    }
};
