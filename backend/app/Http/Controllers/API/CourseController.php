<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\LessonResource;
use App\Http\Resources\QuestionResource;
use App\Http\Resources\QuizResource;
use App\Http\Resources\QuizSummaryResource;
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
            'catalogue_revision' => 'nullable|integer|min:1',
        ]);

        try {
            $revision = $this->catalogueQueries->revision();
            if ($this->revisionChanged($filters, $revision)) {
                return $this->catalogueChanged($revision);
            }
            $courses = $this->catalogueQueries->cachedCatalogue($filters);
            $finalRevision = $this->catalogueQueries->revision();
            if ($finalRevision !== $revision) {
                return $this->catalogueChanged($finalRevision);
            }

            return $this->responses->success(
                $this->coursePresentation->catalogueCollection($courses),
                'تم تحميل الكورسات',
                200,
                ['catalogue_revision' => $revision]
            );
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل الكورسات', 500);
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
            QuizSummaryResource::collection($quizzes),
            'تم تحميل الاختبارات'
        );
    }

    public function getAllExams(Request $request): JsonResource
    {
        return $this->getQuizzes($request);
    }

    public function getList(ItemList $list): JsonResource|JsonResponse
    {
        if ($list->type === 'course') {
            return $this->responses->error('الكورس غير متاح', 404);
        }

        /** @var User|null $user */
        $user = auth('api')->user();
        if (!$this->assessments->canAccessQuiz($user, $list)) {
            return $this->responses->error(
                'هذا الاختبار غير متاح لحسابك',
                403
            );
        }

        return $this->responses->resource(
            new QuizResource($list->loadMissing('questions')),
            'تم تحميل الاختبار'
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
            'تم تحميل الكورس'
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
        $lesson->loadMissing(['course', 'courseSection']);
        $course = $lesson->course;
        $section = $lesson->courseSection;
        if (
            !$course
            || !$section
            || !$course->isPublishedForLearning()
            || (int) $section->course_id !== $courseId
            || $section->getSectionType() !== 'lesson'
            || (int) $section->sectionable_id !== (int) $lesson->id
        ) {
            abort(404);
        }
        $hasAccess = $user && $courseId > 0
            ? $this->courseReads->hasLearningAccess((int) $user->id, $courseId)
            : false;

        // is_opened is the administrator's explicit preview switch. Every
        // other reel remains purchase-protected, including on this legacy URL.
        if ($hasAccess || ((bool) $lesson->is_opened && !$course->isNestedCourse())) {
            return $this->responses->resource(
                new LessonResource($lesson),
                'تم تحميل المقطع'
            );
        }

        return $this->responses->resource(
            new ShortLessonResource($lesson),
            'تم تحميل العينة المجانية'
        );
    }

    public function getQuestion(Question $question): JsonResource|JsonResponse
    {
        $quiz = $question->itemList;
        /** @var User|null $user */
        $user = auth('api')->user();
        if (!$quiz || !$this->assessments->canAccessQuiz($user, $quiz)) {
            return $this->responses->error(
                'هذا السؤال غير متاح لحسابك',
                403
            );
        }

        return $this->responses->resource(
            new QuestionResource($question),
            'تم تحميل السؤال'
        );
    }

    public function subscribe(Request $request, Course $list): JsonResponse
    {
        return response()->json([
            'status' => 410,
            'success' => false,
            'code' => 'legacy_course_subscription_retired',
            'message' => 'استخدم مسار شراء الكورس أو كود الجهة التعليمية',
            'data' => null,
        ], 410);
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
            'catalogue_revision' => 'nullable|integer|min:1',
        ]);

        try {
            $revision = $this->catalogueQueries->revision();
            if ($this->revisionChanged($filters, $revision)) {
                return $this->catalogueChanged($revision);
            }
            $courses = $this->catalogueQueries->mobileCatalogue($filters);
            $finalRevision = $this->catalogueQueries->revision();
            if ($finalRevision !== $revision) {
                return $this->catalogueChanged($finalRevision);
            }
            $payload = $this->coursePresentation->mobileCataloguePayload($courses);
            $payload['catalogue_revision'] = $revision;

            return $this->responses->success(
                $payload,
                'تم تحميل الكورسات'
            );
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل الكورسات', 500);
        }
    }

    /** @param array<string, mixed> $filters */
    private function revisionChanged(array $filters, int $currentRevision): bool
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $expected = isset($filters['catalogue_revision'])
            ? (int) $filters['catalogue_revision']
            : null;

        return $page > 1 && $expected !== null && $expected !== $currentRevision;
    }

    private function catalogueChanged(int $revision): JsonResponse
    {
        return $this->responses->error(
            "تغيّرت قائمة الكورسات\nنحدّثها الآن",
            409,
            ['catalogue_revision' => $revision],
            ['code' => 'catalogue_changed']
        );
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
                    'هذا الكورس غير متاح لحسابك',
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
                'تم تحميل تقدمك في الكورس'
            );
        } catch (ModelNotFoundException $e) {
            return $this->responses->error('الكورس غير متاح', 404);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل تقدم الكورس', 500);
        }
    }

    public function viewCourseDetails(Request $request, int|string $courseId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $read = $this->courseReads->detailedCourse((int) $courseId, $user);

            if ($read['unavailable']) {
                return $this->responses->error('الكورس غير متاح', 404);
            }

            return $this->responses->success(
                $this->coursePresentation->detailedCourse(
                    $read['course'],
                    $user,
                    $read['has_access'],
                    $read['entitlement'],
                    $read['enrollment']
                ),
                'تم تحميل تفاصيل الكورس'
            );
        } catch (ModelNotFoundException $e) {
            return $this->responses->error('الكورس غير متاح', 404);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل الكورس', 500);
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
                return $this->responses->error('سجّل الدخول أولًا', 401);
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
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر حفظ تقدمك', 500);
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
                    'افتح الكورس أولًا للوصول إلى الاختبار',
                    403
                );
            }

            return $this->responses->success(
                $this->assessments->examPayload($quiz),
                'تم تحميل الاختبار'
            );
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل الاختبار', 500);
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
            if (!$user) {
                return $this->responses->error(
                    'افتح الكورس أولًا للوصول إلى الاختبار',
                    403
                );
            }

            $section = $this->assessments->examSection(
                (int) $courseId,
                (int) $sectionId
            );
            if (!$section || !$section->sectionable) {
                return $this->responses->error('الاختبار غير متاح', 404);
            }
            if (!$this->completion->canAccessSection($user, $section)) {
                return $this->responses->error(
                    'هذا الاختبار غير متاح لحسابك',
                    403
                );
            }

            return $this->responses->success(
                $this->assessments->sectionExamPayload($section),
                'تم تحميل الاختبار'
            );
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل الاختبار', 500);
        }
    }

    public function getBestStudents(int|string $courseId): JsonResponse
    {
        try {
            $result = $this->leaderboard->forCourse((int) $courseId);

            return $this->responses->success($result['data'], $result['message']);
        } catch (ModelNotFoundException $e) {
            return $this->responses->error(
                'الكورس غير متاح',
                404,
                null,
                ['error' => 'الكورس غير متاح']
            );
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل قائمة الطلاب', 500);
        }
    }
}
