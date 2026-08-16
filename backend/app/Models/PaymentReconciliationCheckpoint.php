<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReconciliationCheckpoint extends Model
{
    protected $fillable = [
        'provider',
        'cursor_order_id',
        'cycles',
        'last_batch_size',
        'last_started_at',
        'last_completed_at',
        'last_error_at',
        'last_error_code',
        'metadata',
    ];

    protected $casts = [
        'cursor_order_id' => 'integer',
        'cycles' => 'integer',
        'last_batch_size' => 'integer',
        'last_started_at' => 'datetime',
        'last_completed_at' => 'datetime',
        'last_error_at' => 'datetime',
        'metadata' => 'array',
    ];
}
