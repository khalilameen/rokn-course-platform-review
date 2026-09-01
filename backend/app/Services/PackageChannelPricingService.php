<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Package;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PackageChannelPricingService
{
    public function directDiscountPercent(): float
    {
        $load = static function (): float {
            if (
                !Schema::hasTable('settings')
                || !Schema::hasColumn('settings', 'direct_checkout_discount_percent')
            ) {
                return 10;
            }

            return min(50, max(0, (float) (
                Setting::query()->value('direct_checkout_discount_percent') ?? 10
            )));
        };

        try {
            return (float) Cache::remember('packages:direct-discount:v2', 60, $load);
        } catch (Throwable) {
            return $load();
        }
    }

    public function directPrice(Package $package, ?float $discountPercent = null): float
    {
        $price = max(0, (float) $package->price);
        $discount = $discountPercent ?? $this->directDiscountPercent();
        $discounted = $price * (1 - (min(50, max(0, $discount)) / 100));

        return round(max(0.01, $discounted), 2);
    }
}
