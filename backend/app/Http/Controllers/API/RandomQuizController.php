<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\RandomQuizResource;
use App\Models\RandomQuiz;
use App\Services\ApiResponseService;
use Illuminate\Http\Resources\Json\JsonResource;

final class RandomQuizController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function getAll(): JsonResource
    {
        return $this->responses->resource(
            RandomQuizResource::collection(RandomQuiz::all()),
            'تم تحميل الاختبارات السريعة'
        );
    }

    public function getRandomQuiz(RandomQuiz $randomQuiz): JsonResource
    {
        return $this->responses->resource(
            new RandomQuizResource($randomQuiz),
            'تم تحميل الاختبار السريع'
        );
    }
}
