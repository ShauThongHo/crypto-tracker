<?php

namespace App\Infrastructure\Exchange\Contracts;

use App\Models\ExchangeAccount;

interface ExchangeAdapterInterface
{
    /**
     * @return array<int, array{symbol: string, amount: float}>
     */
    public function fetchBalances(ExchangeAccount $account): array;
}