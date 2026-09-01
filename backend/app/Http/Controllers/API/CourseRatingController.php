<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\CourseRatingDeleteRequest;
use App\Http\Requests\API\CourseRatingRequest;
use App\Models\Course;
use App\Models\CourseRating;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseRatingEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class CourseRatingController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly CourseRatingEligibilityService $eligibility
    ) {
    }

    public function store(CourseRatingRequest $request, int|string $courseId): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $course = Course::query()->findOrFail($courseId);
        $eligibility = $this->eligibility->for($user, $course);
        if (!$eligibility['can_rate']) {
            $message = $eligibility['reason'] === 'watch_required'
                ? 'شاهد مقطعًا كاملًا قبل التقييم'
                : 'التقييم متاح لطلاب الكورس';

            return $this->responses->error($message, 403, [
                'code' => strtoupper($eligibility['reason']),
            ]);
        }

        $expectedVersion = $request->integer('version');
        $nextRating = $request->integer('rating');
        $nextComment = $request->input('comment');

        $result = DB::transaction(function () use (
            $user,
            $course,
            $expectedVersion,
            $nextRating,
            $nextComment
        ): array {
            $inserted = 0;
            if ($expectedVersion === 0) {
                $inserted = DB::table('course_ratings')->insertOrIgnore([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'rating' => $nextRating,
                    'comment' => $nextComment,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            /** @var CourseRating|null $rating */
            $rating = CourseRating::withTrashed()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if (!$rating) {
                return ['conflict' => true, 'rating' => null];
            }
            if ($inserted > 0) {
                // insertOrIgnore closes the first-write race. Emit the model's
                // catalogue invalidation event after the winning insert.
                $rating->save();

                return ['conflict' => false, 'rating' => $rating];
            }

            $sameValue = !$rating->trashed()
                && (int) $rating->rating === $nextRating
                && ($rating->comment ?: null) === $nextComment;
            if ((int) $rating->version !== $expectedVersion) {
                // A transport retry after a committed response is success,
                // while a genuinely different edit from another device is a conflict.
                return ['conflict' => !$sameValue, 'rating' => $rating];
            }

            if ($sameValue) {
                return ['conflict' => false, 'rating' => $rating];
            }

            $rating->forceFill([
                'rating' => $nextRating,
                'comment' => $nextComment,
                'version' => (int) $rating->version + 1,
            ]);
            if ($rating->trashed()) {
                $rating->restore();
            } else {
                $rating->save();
            }

            return ['conflict' => false, 'rating' => $rating];
        }, 3);

        if ($result['conflict']) {
            return $this->responses->error(
                'تغيّر تقييمك من جهاز آخر\nحدّث الكورس ثم حاول مرة أخرى',
                409,
                $this->payload($course, $result['rating'])
            );
        }

        return $this->responses->success(
            $this->payload($course, $result['rating']),
            'تم حفظ تقييمك'
        );
    }

    public function destroy(
        CourseRatingDeleteRequest $request,
        int|string $courseId
    ): JsonResponse {
        /** @var User $user */
        $user = auth('api')->user();
        $course = Course::query()->findOrFail($courseId);
        $expectedVersion = $request->integer('version');

        $result = DB::transaction(function () use ($user, $course, $expectedVersion): array {
            /** @var CourseRating|null $rating */
            $rating = CourseRating::withTrashed()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if (!$rating || $rating->trashed()) {
                return ['conflict' => false, 'rating' => $rating];
            }
            if ((int) $rating->version !== $expectedVersion) {
                return ['conflict' => true, 'rating' => $rating];
            }

            $rating->forceFill(['version' => (int) $rating->version + 1])->save();
            $rating->delete();

            return ['conflict' => false, 'rating' => $rating];
        }, 3);

        if ($result['conflict']) {
            return $this->responses->error(
                'تغيّر تقييمك من جهاز آخر\nحدّث الكورس ثم حاول مرة أخرى',
                409,
                $this->payload($course, $result['rating'])
            );
        }

        return $this->responses->success(
            $this->payload($course, null, (int) ($result['rating']?->version ?? 0)),
            'تم حذف تقييمك'
        );
    }

    /** @return array<string, int|float|string|null> */
    private function payload(
        Course $course,
        ?CourseRating $rating,
        ?int $version = null
    ): array {
        $aggregate = CourseRating::query()
            ->where('course_id', $course->id)
            ->whereBetween('rating', [1, 5])
            ->selectRaw('COUNT(*) AS ratings_count, AVG(rating) AS average_rating')
            ->first();
        $count = (int) ($aggregate?->ratings_count ?? 0);

        return [
            'rating' => $rating && !$rating->trashed() ? (int) $rating->rating : null,
            'comment' => $rating && !$rating->trashed() ? $rating->comment : null,
            'version' => $version ?? (int) ($rating?->version ?? 0),
            'average_rating' => $count > 0
                ? round((float) $aggregate->average_rating, 1)
                : null,
            'ratings_count' => $count,
        ];
    }
}
