<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BunnyVideoCleanupCandidate extends Model
{
    protected $fillable = [
        'video_guid', 'lesson_id', 'reason', 'eligible_after',
        'reviewed_at', 'reviewed_by', 'remote_deleted_at', 'last_error',
        'requires_review', 'attempts', 'last_attempt_at',
    ];

    protected $casts = [
        'eligible_after' => 'datetime',
        'reviewed_at' => 'datetime',
        'remote_deleted_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'attempts' => 'integer',
        'requires_review' => 'boolean',
    ];
}
