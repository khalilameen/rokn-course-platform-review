<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinEarningMethod extends Model
{
    protected $fillable = [
        'title_ar',
        'title_en',
        'coins_amount',
        'action_key',
        'action_url',
        'requires_external_visit',
        'verification_delay_seconds',
        'is_active',
        'is_repeatable',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_repeatable' => 'boolean',
        'requires_external_visit' => 'boolean',
        'verification_delay_seconds' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function userEarnings()
    {
        return $this->hasMany(UserCoinEarning::class, 'coin_earning_method_id');
    }

    public function attempts()
    {
        return $this->hasMany(UserCoinTaskAttempt::class);
    }
}
