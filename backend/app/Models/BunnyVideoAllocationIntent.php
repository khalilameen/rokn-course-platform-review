<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BunnyVideoAllocationIntent extends Model
{
    protected $fillable = ['marker', 'video_guid', 'status', 'context'];
}
