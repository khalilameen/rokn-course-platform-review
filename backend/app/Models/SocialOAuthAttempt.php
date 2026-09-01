<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SocialOAuthAttempt extends Model
{
    protected $table = 'social_oauth_attempts';

    protected $fillable = [
        'state_hash',
        'completion_hash',
        'provider',
        'return_to',
        'code_challenge',
        'encrypted_token',
        'encrypted_session_response',
        'state_expires_at',
        'state_consumed_at',
        'completion_expires_at',
        'completion_processing_at',
        'completion_consumed_at',
    ];

    protected $hidden = [
        'state_hash',
        'completion_hash',
        'encrypted_token',
        'encrypted_session_response',
    ];

    protected $casts = [
        'state_expires_at' => 'datetime',
        'state_consumed_at' => 'datetime',
        'completion_expires_at' => 'datetime',
        'completion_processing_at' => 'datetime',
        'completion_consumed_at' => 'datetime',
    ];
}
