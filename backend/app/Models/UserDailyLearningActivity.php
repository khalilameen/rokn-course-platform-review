<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserDailyLearningActivity extends Model
{
    protected $fillable = [
        'user_id',
        'activity_date',
        'qualified_seconds',
    ];

    protected $casts = [
        'activity_date' => 'date',
        'qualified_seconds' => 'integer',
    ];
}
