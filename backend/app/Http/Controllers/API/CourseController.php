<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Http\Resources\LessonResource;
use App\Http\Resources\QuestionResource;
use App\Http\Resources\QuizResource;
use App\Http\Resources\ShortCourseResource;
use App\Http\Resources\ShortLessonResource;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\ItemList;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseAssessmentService;
use App\Services\CourseCatalogueQueryService;
use App\Services\CourseCompletionService;
use App\Services\CourseLeaderboardService;
use App\Services\CoursePresentationService;
use App\Services\CourseReadCompatibilityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

final class CourseController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly CourseAssessmentService $assessments,
        private readonly CourseCatalogueQueryService $catalogueQueries,
        private readonly CourseCompletionService $completion,
        private readonly CourseLeaderboardService $leaderboard,
        private readonly CourseReadCompatibilityService $courseReads,
        private readonly CoursePresentationService $coursePresentation
    ) {
    }

    public function getCourses(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
            'grade_id' => 'nullable|integer',
            'type' => 'nullable|string|max:50',
            'course_type' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:120',
        ]);

        try {
            $courses = $this->catalogueQueries->cachedCatalogue($filters);

            return $this->responses->success(
                $this->coursePresentation->catalogueCollection($courses),
                'Courses retrieved successfully'
            );
        } catch (\Exception $e) {
            report($e);

            return $this->responses->error('Failed to fetch courses', 500);
        }
    }

    public function getQuizzes(Request $request): JsonResource
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:25',
        ]);

        /** @var User|null $user */
        $user = auth('api')->user();
        $quizzes = $this->assessments->accessibleQuizzes(
            $user,
            (int) ($validated['per_page'] ?? 10)
        );

        return $this->responses->resource(
            QuizResource::collection($quizzes),
            'Quizzes retrieved successfully'
        );
    }

    public function getAllExams(Request $request): JsonResource
    {
        return $this->getQuizzes($request);
    }

    public function getList(ItemList $list): JsonResource|JsonResponse
    {
        if ($list->type === 'course') {
            $resource = in_array($list->id, auth()->user()?->courses ?? [])
                ? new CourseResource($list)
                : new ShortCourseResource($list);

            return $this->responses->resource($resource, 'Course retrieved successfully');
        }

        /** @var User|null $user */
        $user = auth('api')->user();
        if (!$this->assessments->canAccessQuiz($user, $list)) {
            return $this->responses->error(
                'You are not authorized to access this assessment.',
                403
            );
        }

        return $this->responses->resource(
            new QuizResource($list->loadMissing('questions')),
            'Assessment retrieved successfully'
        );
    }

    public function getCourse(Course $course): JsonResource
    {
        /** @var User|null $user */
        $user = auth('api')->user();
        $read = $this->courseReads->legacyCourse($course, $user);

        return $this->responses->resource(
            $this->coursePresentation->legacyCourse(
                $read['course'],
                $read['is_enrolled']
            ),
            'Course retrieved successfully'
        );
    }

    public function canAccessSection(User $user, CourseSection $section): bool
    {
        return $this->completion->canAccessSection($user, $section);
    }

    public function canAccessSections(User $user, Collection $sections): Collection
    {
        return $this->completion->accessStates($user, $sections);
    }

    public function getLesson(Lesson $lesson): JsonResource
    {
        $user = auth('api')->user();
        $courseId = (int) $lesson->list_id;
        $hasAccess = $user && $courseId > 0
            ? $this->courseReads->hasLearningAccess((int) $user->id, $courseId)
            : false;

        // is_opened is the administrator's explicit preview switch. Every
        // other reel remains purchase-protected, including on this legacy URL.
        if ((bool) $lesson->is_opened || $hasAccess) {
            return $this->responses->resource(
                new LessonResource($lesson),
                'Lesson retrieved successfully'
            );
        }

        return $this->responses->resource(
            new ShortLessonResource($lesson),
            'Lesson preview retrieved successfully'
        );
    }

    public function getQuestion(Question $question): JsonResource|JsonResponse
    {
        $quiz = $question->itemList;
        /** @var User|null $user */
        $user = auth('api')->user();
        if (!$quiz || !$this->assessments->canAccessQuiz($user, $quiz)) {
            return $this->responses->error(
                'You are not authorized to access this assessment question.',
                403
            );
        }

        return $this->responses->resource(
            new QuestionResource($question),
            'Assessment question retrieved successfully'
        );
    }

    public function subscribe(Request $request, Course $list): JsonResponse
    {
        if ($list->type != 'course') {
            return $this->responses->error('This is not a course', 400);
        }

        if (auth()->user()->courses->contains($list->id)) {
            return $this->responses->error(
                'You are already subscribed to this course',
                400
            );
        }

        auth()->user()->courses()->attach($list->id);

        return $this->responses->success(null, 'Subscribed successfully');
    }

    public function listCourses(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
            'grade_id' => 'nullable|integer',
            'type' => 'nullable|string|max:50',
            'course_type' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:120',
        ]);

        try {
            $courses = $this->catalogueQueries->mobileCatalogue($filters);

            return $this->responses->success(
                $this->coursePresentation->mobileCataloguePayload($courses),
                'Courses retrieved successfully'
            );
        } catch (\Exception $e) {
            report($e);

            return $this->responses->error('Failed to fetch courses', 500);
        }
    }

    public function getCourseProgress(Request $request, int|string $courseId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $read = $this->courseReads->progressCourse(
                (int) $user->id,
                (int) $courseId
            );

            if (!$read['enrollment']) {
                return $this->responses->error(
                    'You are not authorized to access this course',
                    403
                );
            }

            return $this->responses->success(
                $this->coursePresentation->progressPayload(
                    $read['course'],
                    $read['enrollment'],
                    $read['access_type'],
                    (int) $user->id
                ),
                'Course progress retrieved successfully'
            );
        } catch (ModelNotFoundException $e) {
            return $this->responses->error('Course not found', 404);
        } catch (\Exception $e) {
            report($e);

            return $this->responses->error('Failed to fetch course progress', 500);
        }
    }

    public function viewCourseDetails(Request $request, int|string $courseId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $read = $this->courseReads->detailedCourse((int) $courseId, $user);

            if ($read['unavailable']) {
                return $this->responses->error('Course not found', 404);
            }

            return $this->responses->success(
                $this->coursePresentation->detailedCourse(
                    $read['course'],
                    $user,
                    $read['has_access']
                ),
                'Course details retrieved successfully'
            );
        } catch (ModelNotFoundException $e) {
            return $this->responses->error('Course not found', 404);
        } catch (\Exception $e) {
            report($e);

            return $this->responses->error('Failed to fetch course details', 500);
        }
    }

    public function markSectionComplete(
        Request $request,
        int|string $courseId,
        int|string $sectionId
    ): JsonResponse {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('Unauthenticated', 401);
            }

            $result = $this->completion->complete(
                $user,
                (int) $courseId,
                (int) $sectionId
            );
            $additional = isset($result['code'])
                ? ['code' => $result['code']]
                : [];

            return $result['success']
                ? $this->responses->success(
                    $result['data'],
                    $result['message'],
                    $result['status'],
                    $additional
                )
                : $this->responses->error(
                    $result['message'],
                    $result['status'],
                    $result['data'],
                    $additional
                );
        } catch (\Exception $e) {
            report($e);

            return $this->responses->error('Failed to mark section as completed', 500);
        }
    }

    public function getExamData(Request $request, int|string $quizId): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            $quiz = $this->assessments->exam((int) $quizId);

            if (!$this->assessments->canAccessQuiz($user, $quiz)) {
                return $this->responses->error(
                    'You are not authorized to access this exam. Please enroll in the course first.',
                    403
                );
            }

            return $this->responses->success(
                $this->assessments->examPayload($quiz),
                'Exam data retrieved successfully'
            );
        } catch (\Exception $e) {
            report($e);

            return $this->responses->error('Failed to fetch exam data', 500);
        }
    }

    public function getExamDataBySection(
        Request $request,
        int|string $courseId,
        int|string $sectionId
    ): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user || !$this->assessments->hasDirectCourseAccess($user, (int) $courseId)) {
                return $this->responses->error(
                    'You are not authorized to access this course. Please enroll first.',
                    403
                );
            }

            $section = $this->assessments->examSection(
                (int) $courseId,
                (int) $sectionId
            );
            if (!$section || !$section->sectionable) {
                return $this->responses->error('Exam section not found', 404);
            }

            return $this->responses->success(
                $this->assessments->sectionExamPayload($section),
                'Exam data retrieved successfully'
            );
        } catch (\Exception $e) {
            report($e);

            return $this->responses->error('Failed to fetch exam data', 500);
        }
    }

    public function getBestStudents(int|string $courseId): JsonResponse
    {
        try {
            $result = $this->leaderboard->forCourse((int) $courseId);

            return $this->responses->success($result['data'], $result['message']);
        } catch (ModelNotFoundException $e) {
            return $this->responses->error(
                'Course not found',
                404,
                null,
                ['error' => 'The requested course does not exist']
            );
        } catch (\Exception $e) {
            report($e);

            return $this->responses->error('Failed to retrieve best students', 500);
        }
    }
}
