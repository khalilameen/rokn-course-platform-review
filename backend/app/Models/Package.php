<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'name_ar', 'name_en', 'price', 'coins',
        'google_product_id', 'apple_product_id',
        'google_enabled', 'apple_enabled',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'coins' => 'integer',
        'google_enabled' => 'boolean',
        'apple_enabled' => 'boolean',
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

    public function storePurchases()
    {
        return $this->hasMany(StorePurchase::class);
    }
}
