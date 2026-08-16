<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductFeatureFlag extends Model
{
    protected $fillable = [
        'key', 'enabled', 'rollout_percentage', 'owner', 'reason', 'expires_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'rollout_percentage' => 'integer',
        'expires_at' => 'immutable_datetime',
    ];
}
