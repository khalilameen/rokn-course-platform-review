<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCoinEarning extends Model
{
    protected $fillable = [
        'user_id',
        'coin_earning_method_id',
        'amount',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function method()
    {
        return $this->belongsTo(CoinEarningMethod::class, 'coin_earning_method_id');
    }
}
