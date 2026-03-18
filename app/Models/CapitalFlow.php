<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use MongoDB\Laravel\Eloquent\Model as MongoModel; // 如果你用的是 MongoDB 扩展

class CapitalFlow extends MongoModel
{
    protected $fillable = [
        'type', 'fiat_amount', 'fiat_currency', 
        'usdt_rate', 'usdt_amount', 'platform', 
        'transaction_date', 'notes'
    ];
}