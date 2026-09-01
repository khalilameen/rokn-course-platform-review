<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\PackageChannelPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PackageController extends Controller
{
    public function __construct(private readonly PackageChannelPricingService $pricing)
    {
    }

    public function index(): JsonResponse
    {
        $discountPercent = $this->pricing->directDiscountPercent();
        $load = fn () => Package::query()
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->where('coins', '>', 0)
            ->orderBy('coins')
            ->orderBy('id')
            ->get()
            ->map(fn (Package $package): array => $this->payload($package, $discountPercent));
        try {
            $packages = Cache::remember('public-packages:v2', 60, $load);
        } catch (Throwable) {
            $packages = $load();
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل باقات العملات',
            'data' => $packages,
        ]);
    }

    public function show(int|string $id): JsonResponse
    {
        $package = Package::query()
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->where('coins', '>', 0)
            ->find($id);

        if (!$package) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'الباقة غير متاحة',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل الباقة',
            'data' => $this->payload($package, $this->pricing->directDiscountPercent()),
        ]);
    }

    public function purchase(Request $request, int|string $id): JsonResponse
    {
        return response()->json([
            'status' => 410,
            'success' => false,
            'code' => 'external_checkout_required',
            'message' => 'استخدم الدفع داخل التطبيق لشحن العملات',
            'data' => [
                'package_id' => (int) $id,
                'initiate_endpoint' => '/api/v1/payment/initiate',
            ],
        ], 410);
    }

    /** @return array<string, mixed> */
    private function payload(Package $package, float $discountPercent): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name_ar,
            'name_ar' => $package->name_ar,
            'name_en' => $package->name_en,
            'price' => (float) $package->price,
            'direct_price' => $package->direct_enabled
                ? $this->pricing->directPrice($package, $discountPercent)
                : null,
            'direct_discount_percent' => $discountPercent,
            'coins' => (int) $package->coins,
            'store_products' => [
                'google' => $package->google_enabled
                    ? $package->google_product_id
                    : null,
                'apple' => $package->apple_enabled
                    ? $package->apple_product_id
                    : null,
            ],
            'channels' => [
                'direct' => (bool) $package->direct_enabled,
                'google' => (bool) $package->google_enabled,
                'apple' => (bool) $package->apple_enabled,
            ],
        ];
    }
}
