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
            'message' => 'Classifications retrieved successfully',
            'data' => ClassificationResource::collection(Classification::all()),
        ]);
    }
}
