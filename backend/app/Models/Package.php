<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'name_ar', 'name_en', 'price', 'coins',
        'is_active', 'direct_enabled',
        'google_product_id', 'apple_product_id',
        'google_enabled', 'apple_enabled',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'coins' => 'integer',
        'is_active' => 'boolean',
        'direct_enabled' => 'boolean',
        'google_enabled' => 'boolean',
        'apple_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (Package $package): void {
            $hasIssuedStoreContract = trim((string) $package->getOriginal('google_product_id')) !== ''
                || trim((string) $package->getOriginal('apple_product_id')) !== '';
            if ($hasIssuedStoreContract && $package->isDirty('coins')) {
                throw new \DomainException(
                    'عدد عملات منتج متجر منشور ثابت. أنشئ باقة ومنتجًا جديدين.'
                );
            }
            foreach (['google_product_id', 'apple_product_id'] as $productField) {
                if (
                    trim((string) $package->getOriginal($productField)) !== ''
                    && $package->isDirty($productField)
                ) {
                    throw new \DomainException(
                        'معرّف منتج المتجر المنشور ثابت. عطّل المنتج وأنشئ باقة جديدة.'
                    );
                }
            }
        });
    }

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

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
