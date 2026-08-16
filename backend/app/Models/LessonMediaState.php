<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LessonMediaState extends Model
{
    protected $fillable = [
        'lesson_id', 'provider', 'provider_media_id', 'status', 'protocol',
        'duration_seconds', 'available_qualities', 'manifest', 'last_probe_at',
        'last_error_code', 'last_error_message', 'retry_count',
        'integrity_status', 'integrity_issues', 'last_reconciled_at',
        'quarantined_at',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'available_qualities' => 'array',
        'manifest' => 'array',
        'integrity_issues' => 'array',
        'last_probe_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'quarantined_at' => 'datetime',
        'retry_count' => 'integer',
    ];
}
