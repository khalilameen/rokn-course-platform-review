<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PortfolioVideoUpload extends Model
{
    protected $fillable = [
        'user_id', 'portfolio_item_id', 'idempotency_key', 'request_hash',
        'content_sha256', 'size_bytes', 'mime_type', 'original_name',
        'video_guid', 'allocation_token', 'status', 'expires_at', 'attached_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'expires_at' => 'immutable_datetime',
        'attached_at' => 'immutable_datetime',
    ];
}
