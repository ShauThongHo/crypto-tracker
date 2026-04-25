<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CexSyncedAsset extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'cex_synced_assets';

    protected $fillable = [
        'exchange',
        'account_id',
        'account_label',
        'source_name',
        'source_type',
        'network',
        'token_name',
        'symbol',
        'coingecko_id',
        'token_amount',
        'value_usd',
        'label',
        'label_id',
        'sync_slot',
        'last_synced_at',
        'is_active',
        'raw_hash',
    ];

    protected $casts = [
        'token_amount' => 'float',
        'value_usd' => 'float',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
