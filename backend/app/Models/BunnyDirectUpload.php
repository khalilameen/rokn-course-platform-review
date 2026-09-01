<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BunnyDirectUpload extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'section_id',
        'idempotency_key',
        'request_hash',
        'video_guid',
        'allocation_token',
        'status',
        'expires_at',
        'attached_at',
    ];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'attached_at' => 'immutable_datetime',
    ];
}
