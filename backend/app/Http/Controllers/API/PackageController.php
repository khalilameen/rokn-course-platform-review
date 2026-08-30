<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = Package::query()
            ->where('price', '>', 0)
            ->where('coins', '>', 0)
            ->orderBy('coins')
            ->get();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Packages retrieved successfully',
            'data' => $packages,
        ]);
    }

    public function show(int|string $id): JsonResponse
    {
        $package = Package::query()
            ->where('price', '>', 0)
            ->where('coins', '>', 0)
            ->find($id);

        if (!$package) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'Package not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Package retrieved successfully',
            'data' => $package,
        ]);
    }

    public function purchase(Request $request, int|string $id): JsonResponse
    {
        return response()->json([
            'status' => 410,
            'success' => false,
            'code' => 'external_checkout_required',
            'message' => 'Use the secure checkout inside the app to purchase Rokn coins.',
            'data' => [
                'package_id' => (int) $id,
                'initiate_endpoint' => '/api/v1/payment/initiate',
            ],
        ], 410);
    }
}
