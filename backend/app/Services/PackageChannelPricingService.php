<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Package;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

final class PackageChannelPricingService
{
    public function directDiscountPercent(): float
    {
        if (
            !Schema::hasTable('settings')
            || !Schema::hasColumn('settings', 'direct_checkout_discount_percent')
        ) {
            return 10;
        }

        return min(50, max(0, (float) (
            Setting::query()->value('direct_checkout_discount_percent') ?? 10
        )));
    }

    public function directPrice(Package $package): float
    {
        $price = max(0, (float) $package->price);
        $discounted = $price * (1 - ($this->directDiscountPercent() / 100));

        return round(max(0.01, $discounted), 2);
    }
}
