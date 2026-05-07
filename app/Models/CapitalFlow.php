<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CapitalFlow extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'capital_flows';

    protected $fillable = [
        'user_id',
        'type',
        'fiat_amount',
        'fiat_currency',
        'usdt_rate',
        'usdt_amount',
        'platform',
        'transaction_date',
        'notes',
    ];

    protected $casts = [
        'fiat_amount' => 'float',
        'usdt_rate' => 'float',
        'usdt_amount' => 'float',
        'transaction_date' => 'datetime',
    ];
}