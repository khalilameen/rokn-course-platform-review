<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_event_id',
        'event_name',
        'severity',
        'app_version',
        'build_number',
        'platform',
        'os_major',
        'device_tier',
        'network_type',
        'screen_key',
        'error_code',
        'error_fingerprint',
        'endpoint',
        'request_id',
        'user_id',
        'occurred_at',
        'received_at',
    ];

    protected $casts = [
        'build_number' => 'integer',
        'os_major' => 'integer',
        'user_id' => 'integer',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
