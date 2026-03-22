# Crypto Tracker

一個基於 Laravel + ECharts 的加密貨幣資產追蹤系統。提供：

- 個人持倉總價、資產構成看板（bento grid）
- 時間序列資產淨值曲線圖（支持 1D/7D/30D）
- 盈虧日曆 / 月度熱力圖視圖
- 添加 / 編輯 / 刪除資產、錢包、追蹤代幣等 CRUD
- 法幣切換 USD / MYR 統一顯示
- 自動同步與實時匯率更新

## 模塊化改造（已完成）

- 主文件從 public/dashboard.js 變更為模塊入口 (	ype="module")。
- 新增模塊路徑：public/js/dashboard/app.js（原始邏輯整體移入）。
- 可以繼續細分為 public/js/dashboard/*.js（如 pi.js、ender.js、utils.js）以保持可維護性。

## 已刪除冗余文件

- 	ests/Unit/ExampleTest.php
- 	ests/Feature/ExampleTest.php
- outes/channels.php
- outes/console.php
- database/factories/UserFactory.php

## 本地開發

1. composer install
2. 
pm install
3. cp .env.example .env 並配置資料庫
4. php artisan key:generate
5. php artisan migrate --seed
6. 
pm run dev (或 
pm run build)
7. 啟動：php artisan serve

## 重要端點

- Web: / 
- API: /api/assets, /api/wallets, /api/tracked-tokens, /api/sync-status 等

## 開發備註

- 前端 ECharts 直接從布局載入：esources/views/layouts/app.blade.php。
- 為避免 CSRF 419，所有表單 API 請附 X-CSRF-TOKEN。

## 授權

MIT。
