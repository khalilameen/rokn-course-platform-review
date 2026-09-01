<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BunnyStorageCleanupCandidate extends Model
{
    protected $fillable = [
        'path_hash', 'path', 'reason', 'eligible_after', 'attempts',
        'last_attempt_at', 'completed_at', 'last_error',
        'quarantined_at',
    ];

    protected $casts = [
        'path' => 'encrypted',
        'eligible_after' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'quarantined_at' => 'datetime',
        'attempts' => 'integer',
    ];
}
