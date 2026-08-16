<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\CourseRatingRequest;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRating;
use App\Models\CourseSection;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;

final class CourseRatingController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    /**
     * Store or update a course rating.
     *
     * @param CourseRatingRequest $request
     * @param int $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(CourseRatingRequest $request, int|string $courseId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $course = Course::findOrFail($courseId);

            // Check if user has access to the course (direct or parent access)
            if (!$this->checkCourseAccess((int) $user->id, (int) $courseId)) {
                return $this->responses->error(
                    'You must be enrolled in the course to rate it.',
                    403
                );
            }

            // Create or update the rating
            $rating = CourseRating::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $courseId,
                ],
                [
                    'rating' => $request->rating,
                    'comment' => $request->comment,
                ]
            );

            return $this->responses->success(
                [
                    'rating' => $rating->rating,
                    'comment' => $rating->comment,
                    'average_rating' => $course->average_rating,
                    'ratings_count' => $course->ratings_count,
                ],
                'Rating submitted successfully'
            );

        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error('Failed to submit rating', 500);
        }
    }

    /**
     * Check if user has access to a course (direct or parent access).
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    private function checkCourseAccess(int $userId, int $courseId): bool
    {
        // Check direct enrollment
        $directEnrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();

        if ($directEnrollment && $directEnrollment->isActive()) {
            return true;
        }

        // Check parent course access
        // Find all parent courses that contain this course as a section
        $parentCourseIds = CourseSection::where('sectionable_type', 'App\Models\Course')
            ->where('sectionable_id', $courseId)
            ->pluck('course_id')
            ->toArray();

        if (empty($parentCourseIds)) {
            return false;
        }

        // Check if user is enrolled in any of the parent courses
        $parentEnrollment = CourseEnrollment::where('user_id', $userId)
            ->whereIn('course_id', $parentCourseIds)
            ->where('is_active', true)
            ->first();

        return $parentEnrollment && $parentEnrollment->isActive();
    }
}
