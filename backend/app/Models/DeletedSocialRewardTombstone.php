<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DeletedSocialRewardTombstone extends Model
{
    protected $fillable = [
        'provider',
        'identity_hmac',
        'consumed_reward_keys',
    ];

    protected $casts = [
        'consumed_reward_keys' => 'array',
    ];

    protected $hidden = [
        'identity_hmac',
    ];
}
