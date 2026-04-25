<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * @property mixed $id
 * @property string|null $exchange
 * @property string|null $label
 * @property string|null $api_key_enc
 * @property string|null $api_secret_enc
 * @property string|null $api_passphrase_enc
 * @property bool|null $enabled
 * @property string|null $last_sync_status
 * @property string|null $last_error
 * @property mixed $last_sync_at
 */
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
