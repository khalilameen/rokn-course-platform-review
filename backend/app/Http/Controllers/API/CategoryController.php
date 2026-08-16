<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\ApiResponseService;
use Illuminate\Http\Resources\Json\JsonResource;

final class CategoryController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function index(): JsonResource
    {
        return $this->responses->resource(
            CategoryResource::collection(Category::all()),
            'Categories retrieved successfully'
        );
    }
}
