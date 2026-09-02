<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SocialIdentityGuard extends Model
{
    protected $fillable = ['identity_hash', 'deletion_started_at'];

    protected $casts = ['deletion_started_at' => 'immutable_datetime'];
}
