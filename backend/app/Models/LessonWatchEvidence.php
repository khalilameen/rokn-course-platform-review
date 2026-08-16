<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LessonWatchEvidence extends Model
{
    protected $guarded = [];

    protected $casts = [
        'duration_seconds' => 'integer',
        'verified_seconds' => 'integer',
        'last_position_seconds' => 'integer',
        'last_heartbeat_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
