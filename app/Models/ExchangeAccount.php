<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ExchangeAccount extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'exchange_accounts';

    protected $fillable = [
        'exchange',
        'label',
        'api_key_enc',
        'api_secret_enc',
        'api_passphrase_enc',
        'enabled',
        'last_sync_at',
        'last_sync_status',
        'last_error',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_sync_at' => 'datetime',
    ];
}
