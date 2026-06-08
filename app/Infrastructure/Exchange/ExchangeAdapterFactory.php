<?php

namespace App\Infrastructure\Exchange;

use App\Infrastructure\Exchange\Adapters\BitgetExchangeAdapter;
use App\Infrastructure\Exchange\Adapters\OkxExchangeAdapter;
use App\Infrastructure\Exchange\Contracts\ExchangeAdapterInterface;

class ExchangeAdapterFactory
{
    public function make(string $exchange): ExchangeAdapterInterface
    {
        return match (strtolower(trim($exchange))) {
            'okx', 'okex' => new OkxExchangeAdapter(),
            'bitget' => new BitgetExchangeAdapter(),
            default => throw new \RuntimeException('unsupported_exchange:' . $exchange),
        };
    }
}