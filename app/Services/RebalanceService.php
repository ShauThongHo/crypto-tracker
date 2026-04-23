<?php

namespace App\Services;

class RebalanceService
{
    private int $scale = 12;

    /**
     * 高精度等比縮放調倉算法。
     *
     * 入參資產格式：
     * - id
     * - name
     * - current_value
     * - target_pct   // 0-100
     *
     * 回傳：
     * - items[]: 加上 current_pct / new_target_pct / advice_usd / action 等欄位
     * - summary: 買入/賣出/淨額匯總
     */
    public function calculateProportional(array $assets, float $totalPortfolio, float $inactiveThresholdPct = 5.0): array
    {
        $portfolio = $this->normalizeNumber($totalPortfolio);
        $hundred = '100';
        $epsilon = '0.000000001';

        if ($this->compare($portfolio, '0') <= 0) {
            return [
                'k_factor' => 1.0,
                'inactive_threshold_pct' => (float) $inactiveThresholdPct,
                'items' => [],
                'summary' => $this->buildSummary(0, 0),
                'normalized_total_pct' => 0.0,
            ];
        }

        $threshold = $this->normalizeNumber($inactiveThresholdPct);
        $items = [];
        $inactiveCurrentPct = '0';
        $inactiveTargetPct = '0';
        $activeIndexes = [];

        // Step 1: inactive 判定（|current - target| < threshold）
        foreach ($assets as $index => $asset) {
            $currentValue = $this->normalizeNumber($asset['current_value'] ?? $asset['value'] ?? 0);
            $targetPct = $this->normalizeNumber($asset['target_pct'] ?? 0);
            $currentPct = $this->divide($this->multiply($currentValue, $hundred), $portfolio);
            $deviationPct = $this->abs($this->subtract($currentPct, $targetPct));
            $isInactive = $this->compare($deviationPct, $threshold) < 0;

            $items[$index] = [
                'id' => (string) ($asset['id'] ?? $index),
                'name' => (string) ($asset['name'] ?? $asset['id'] ?? '未命名'),
                'symbols' => array_values(array_unique(array_map(static function ($symbol) {
                    return strtoupper(trim((string) $symbol));
                }, $asset['symbols'] ?? []))),
                'current_value' => $currentValue,
                'current_pct' => $currentPct,
                'target_pct' => $targetPct,
                'deviation_pct' => $deviationPct,
                'is_active' => !$isInactive,
            ];

            if ($isInactive) {
                $inactiveCurrentPct = $this->add($inactiveCurrentPct, $currentPct);
                $inactiveTargetPct = $this->add($inactiveTargetPct, $targetPct);
            } else {
                $activeIndexes[] = $index;
            }
        }

        // Step 2: K = (1 - S_inactive) / (1 - T_inactive) (百分比形式等價為 (100 - S)/(100 - T))
        $activeCurrentSpace = $this->subtract($hundred, $inactiveCurrentPct);
        $activeTargetSpace = $this->subtract($hundred, $inactiveTargetPct);
        $kFactor = $this->compare($this->abs($activeTargetSpace), $epsilon) > 0
            ? $this->divide($activeCurrentSpace, $activeTargetSpace)
            : '1';

        $totalNewTargetPct = '0';

        // Step 3: 新目標（active 用 target * K；inactive 鎖定 current）
        foreach ($items as $index => $item) {
            if (!$item['is_active']) {
                $newTargetPct = $item['current_pct'];
                $newTargetValue = $item['current_value'];
            } else {
                $newTargetPct = $this->multiply($item['target_pct'], $kFactor);
                $newTargetValue = $this->divide($this->multiply($portfolio, $newTargetPct), $hundred);
            }

            // Step 4: Advice = New_Target_Value - Current_Value
            $adviceUsd = $this->subtract($newTargetValue, $item['current_value']);
            $item['new_target_pct'] = $newTargetPct;
            $item['new_target_value'] = $newTargetValue;
            $item['advice_usd'] = $adviceUsd;
            $item['advice_action'] = $this->compare($adviceUsd, '0') > 0 ? 'buy' : ($this->compare($adviceUsd, '0') < 0 ? 'sell' : 'hold');
            $totalNewTargetPct = $this->add($totalNewTargetPct, $newTargetPct);
            $items[$index] = $item;
        }

        // Step 5: 嚴格收斂到 100%
        $residualPct = $this->subtract($hundred, $totalNewTargetPct);
        if ($this->compare($this->abs($residualPct), $epsilon) > 0 && !empty($items)) {
            $adjustIndex = !empty($activeIndexes)
                ? $activeIndexes[count($activeIndexes) - 1]
                : array_key_last($items);

            $items[$adjustIndex]['new_target_pct'] = $this->add($items[$adjustIndex]['new_target_pct'], $residualPct);
            $items[$adjustIndex]['new_target_value'] = $this->divide($this->multiply($portfolio, $items[$adjustIndex]['new_target_pct']), $hundred);
            $items[$adjustIndex]['advice_usd'] = $this->subtract($items[$adjustIndex]['new_target_value'], $items[$adjustIndex]['current_value']);
            $items[$adjustIndex]['advice_action'] = $this->compare($items[$adjustIndex]['advice_usd'], '0') > 0 ? 'buy' : ($this->compare($items[$adjustIndex]['advice_usd'], '0') < 0 ? 'sell' : 'hold');

            $totalNewTargetPct = '0';
            foreach ($items as $row) {
                $totalNewTargetPct = $this->add($totalNewTargetPct, $row['new_target_pct']);
            }
        }

        $totalBuy = '0';
        $totalSell = '0';
        $maxDeviation = '0';
        foreach ($items as $item) {
            $maxDeviation = $this->compare($item['deviation_pct'], $maxDeviation) > 0 ? $item['deviation_pct'] : $maxDeviation;
            if ($this->compare($item['advice_usd'], '0') > 0) {
                $totalBuy = $this->add($totalBuy, $item['advice_usd']);
            } elseif ($this->compare($item['advice_usd'], '0') < 0) {
                $totalSell = $this->add($totalSell, $this->abs($item['advice_usd']));
            }
        }

        return [
            'k_factor' => (float) $kFactor,
            'inactive_threshold_pct' => (float) $inactiveThresholdPct,
            'normalized_total_pct' => (float) $this->normalizeOutputNumber($totalNewTargetPct),
            'max_deviation_pct' => (float) $this->normalizeOutputNumber($maxDeviation),
            'items' => array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'value' => $this->toFloatOutput($item['current_value']),
                    'current_value' => $this->toFloatOutput($item['current_value']),
                    'current_pct' => $this->toFloatOutput($item['current_pct']),
                    'weight_pct' => $this->toFloatOutput($item['current_pct']),
                    'target_pct' => $this->toFloatOutput($item['target_pct']),
                    'new_target_pct' => $this->toFloatOutput($item['new_target_pct']),
                    'new_target_value' => $this->toFloatOutput($item['new_target_value']),
                    'deviation_pct' => $this->toFloatOutput($item['deviation_pct']),
                    'abs_deviation_pct' => $this->toFloatOutput($this->abs($item['deviation_pct'])),
                    'advice_usd' => $this->toFloatOutput($item['advice_usd']),
                    'advice_action' => $item['advice_action'],
                    'is_active' => (bool) $item['is_active'],
                    'symbols' => $item['symbols'],
                ];
            }, $items),
            'summary' => $this->buildSummary($this->toFloatOutput($totalBuy), $this->toFloatOutput($totalSell)),
        ];
    }

    private function buildSummary(float $totalBuy, float $totalSell): array
    {
        $net = $totalBuy - $totalSell;

        if ($totalBuy > 0 && $totalSell > 0) {
            $text = sprintf('买入 $%s / 卖出 $%s', $this->formatMoney($totalBuy), $this->formatMoney($totalSell));
        } elseif ($totalBuy > 0) {
            $text = sprintf('买入 $%s', $this->formatMoney($totalBuy));
        } elseif ($totalSell > 0) {
            $text = sprintf('卖出 $%s', $this->formatMoney($totalSell));
        } else {
            $text = '无需调仓';
        }

        return [
            'buy_usd' => round($totalBuy, 8),
            'sell_usd' => round($totalSell, 8),
            'net_usd' => round($net, 8),
            'text' => $text,
        ];
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private function toFloatOutput(string $value): float
    {
        return round((float) $value, 8);
    }

    private function normalizeOutputNumber(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
    }

    private function normalizeNumber($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return '0';
            }

            if (is_numeric($value)) {
                $value = (float) $value;
            }
        }

        if (is_float($value) || is_int($value)) {
            $normalized = sprintf('%.18F', (float) $value);
            return rtrim(rtrim($normalized, '0'), '.');
        }

        return (string) $value;
    }

    private function add(string $left, string $right): string
    {
        if (function_exists('bcadd')) {
            return bcadd($this->normalizeNumber($left), $this->normalizeNumber($right), $this->scale);
        }

        return (string) ((float) $left + (float) $right);
    }

    private function subtract(string $left, string $right): string
    {
        if (function_exists('bcsub')) {
            return bcsub($this->normalizeNumber($left), $this->normalizeNumber($right), $this->scale);
        }

        return (string) ((float) $left - (float) $right);
    }

    private function multiply(string $left, string $right): string
    {
        if (function_exists('bcmul')) {
            return bcmul($this->normalizeNumber($left), $this->normalizeNumber($right), $this->scale);
        }

        return (string) ((float) $left * (float) $right);
    }

    private function divide(string $left, string $right): string
    {
        if ($this->compare($right, '0') === 0) {
            return '0';
        }

        if (function_exists('bcdiv')) {
            return bcdiv($this->normalizeNumber($left), $this->normalizeNumber($right), $this->scale);
        }

        return (string) ((float) $left / (float) $right);
    }

    private function compare(string $left, string $right): int
    {
        if (function_exists('bccomp')) {
            return bccomp($this->normalizeNumber($left), $this->normalizeNumber($right), $this->scale);
        }

        return ((float) $left) <=> ((float) $right);
    }

    private function abs(string $value): string
    {
        if ($this->compare($value, '0') < 0) {
            return $this->subtract('0', $value);
        }

        return $this->normalizeNumber($value);
    }
}