<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassificationResource;
use App\Models\Classification;
use Illuminate\Http\JsonResponse;

final class ClassificationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل التصنيفات',
            'data' => ClassificationResource::collection(
                Classification::query()
                    ->orderBy('home_order')
                    ->orderBy('name_ar')
                    ->orderBy('id')
                    ->get()
            ),
        ]);
    }
}
