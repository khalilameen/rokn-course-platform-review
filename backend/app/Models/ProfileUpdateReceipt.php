<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProfileUpdateReceipt extends Model
{
    protected $fillable = [
        'user_id', 'client_request_id', 'request_fingerprint',
        'profile_revision',
    ];

    protected $casts = [
        'profile_revision' => 'integer',
    ];
}
