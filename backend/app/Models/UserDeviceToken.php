<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'device_token',
        'device_type',
        'device_os',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
