<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'name_ar', 'name_en', 'price', 'coins'
    ];

    /**
     * Get the purchases of this package.
     */
    public function purchases()
    {
        return $this->belongsToMany(User::class, 'package_user')
                    ->withPivot('order_id', 'price', 'coins', 'created_at')
                    ->withTimestamps();
    }
}
