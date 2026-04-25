<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * @property mixed $id
 * @property string|null $exchange
 * @property string|null $account_id
 * @property string|null $account_label
 * @property string|null $source_name
 * @property string|null $source_type
 * @property string|null $network
 * @property string|null $token_name
 * @property string|null $symbol
 * @property string|null $coingecko_id
 * @property float|null $token_amount
 * @property float|null $value_usd
 * @property bool|null $is_active
 * @property mixed $last_synced_at
 * @property string|null $label
 * @property string|null $label_id
 */
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
