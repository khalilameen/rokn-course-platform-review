<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\ExamAttempt;
use App\Models\ItemList;
use App\Models\Lesson;
use App\Models\LessonWatchEvidence;
use App\Models\Order;
use App\Models\Project;
use App\Models\StudentSectionProgress;
use App\Models\UserProjectEvaluation;
use App\Services\CertificateService;
use App\Services\CourseChatAccessService;
use App\Services\LearningEvidenceService;
use Illuminate\Http\JsonResponse;

final class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly CourseChatAccessService $courseAccess,
        private readonly LearningEvidenceService $learningEvidence
    ) {
    }

    /**
     * List all certificates for the authenticated user with course minimum details.
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status'  => 401,
                'success' => false,
                'message' => 'Unauthenticated',
                'data' => null,
            ], 401);
        }

        $certificates = Certificate::where('user_id', $user->id)
            ->with('course')
            ->orderBy('generated_at', 'desc')
            ->get();

        return response()->json([
            'status'  => 200,
            'success' => true,
            'message' => 'Certificates retrieved successfully',
            'data'    => CertificateResource::collection($certificates),
        ]);
    }

    /**
     * Generate (if not yet generated) or fetch the certificate for a given course.
     *
     * Requirements before generating:
     *   1. User must be actively enrolled in the course.
     *   2. User must have completed every course section.
     *   3. When the course has a graduation project, it must be passed.
     */
    public function show($courseId): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status'  => 401,
                'success' => false,
                'message' => 'Unauthenticated',
                'data' => null,
            ], 401);
        }

        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->with('course')
            ->first();

        if ($certificate) {
            if (($certificate->status ?? 'active') !== 'active') {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'certificate_revoked',
                    'message' => 'This certificate is no longer active',
                    'data' => null,
                ], 410);
            }

            if ($certificate->image_path === 'pending') {
                // A pending row remains retryable until generation succeeds.
                $recovered = $this->certificates->generate(
                    $user,
                    $certificate->course,
                    $certificate->project_id
                        ? Project::find($certificate->project_id)
                        : null,
                );
                if ($recovered && $recovered->image_path !== 'pending') {
                    $recovered->load('course');

                    return response()->json([
                        'status' => 200,
                        'success' => true,
                        'message' => 'Certificate retrieved successfully',
                        'data' => new CertificateResource($recovered),
                    ]);
                }

                return response()->json([
                    'status' => 202,
                    'success' => true,
                    'code' => 'certificate_generating',
                    'message' => 'Certificate generation is still in progress',
                    'data' => null,
                ], 202);
            }

            return response()->json([
                'status'  => 200,
                'success' => true,
                'message' => 'Certificate retrieved successfully',
                'data'    => new CertificateResource($certificate),
            ]);
        }

        $course = Course::find($courseId);
        if (!$course) {
            return response()->json([
                'status'  => 404,
                'success' => false,
                'message' => 'Course not found',
                'data' => null,
            ], 404);
        }

        $enrollment = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$enrollment) {
            return response()->json([
                'status'  => 403,
                'success' => false,
                'message' => 'You are not enrolled in this course',
                'data' => null,
            ], 403);
        }

        // Certificate access is checked independently from learning access.
        if (!$this->courseAccess->hasCertificateAccess(
            (int) $user->id,
            (int) $courseId
        )) {
            return response()->json([
                'status' => 402,
                'success' => false,
                'code' => 'certificate_upgrade_required',
                'message' => 'Your grant includes the full course and projects. '
                    . 'The certificate is available with a paid support plan.',
                'data' => [
                    'learning_access' => true,
                    'certificate_available' => false,
                    'upgrade_endpoint' => "/api/v1/courses/{$courseId}/full-track-upgrade",
                ],
            ], 402);
        }

        // Do not issue a new certificate while the related order is under review.
        if ($enrollment->order_id && Order::query()
            ->whereKey($enrollment->order_id)
            ->where('user_id', $user->id)
            ->whereIn('financial_status', [
                Order::FINANCIAL_PARTIALLY_RECOVERED,
                Order::FINANCIAL_REVIEW_REQUIRED,
            ])
            ->where('unrecovered_coins', '>', 0)
            ->exists()) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'certificate_financial_review_required',
                'message' => 'Certificate issuance is temporarily unavailable '
                    . 'while the related payment is being reviewed',
                'data' => null,
            ], 409);
        }

        $sections = CourseSection::query()
            ->where('course_id', $courseId)
            ->get(['id', 'sectionable_type', 'sectionable_id']);
        $sectionIds = $sections->pluck('id');
        $completedSections = StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_section_id', $sectionIds)
            ->where('is_completed', true)
            ->distinct('course_section_id')
            ->count('course_section_id');

        if ($sectionIds->isEmpty() || $completedSections !== $sectionIds->count()) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'course_not_completed',
                'message' => 'Complete every course step before requesting the certificate',
                'data' => null,
            ], 403);
        }

        if (!$this->hasServerVerifiedLearningEvidence($user, $sections)) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'course_evidence_incomplete',
                'message' => 'Complete the remaining learning steps before requesting the certificate',
                'data' => null,
            ], 403);
        }

        $graduationProject = Project::where('is_graduation_project', true)
            ->whereHas('section', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            })
            ->first();

        if ($graduationProject) {
            $passed = UserProjectEvaluation::where('user_id', $user->id)
                ->where('project_id', $graduationProject->id)
                ->where('passed', true)
                ->exists();

            if (!$passed) {
                return response()->json([
                    'status' => 403,
                    'success' => false,
                    'code' => 'graduation_project_not_passed',
                    'message' => 'You have not passed the graduation project yet',
                    'data' => null,
                ], 403);
            }
        }

        $certificate = $this->certificates->generate($user, $course, $graduationProject);

        if (!$certificate) {
            return response()->json([
                'status'  => 500,
                'success' => false,
                'message' => 'Failed to generate certificate. Please try again later.',
                'data' => null,
            ], 500);
        }

        $certificate->load('course');

        return response()->json([
            'status'  => 200,
            'success' => true,
            'message' => 'Certificate generated successfully',
            'data'    => new CertificateResource($certificate),
        ]);
    }

    private function hasServerVerifiedLearningEvidence($user, $sections): bool
    {
        $lessonSections = $sections->where('sectionable_type', Lesson::class);
        if ($lessonSections->isNotEmpty()) {
            $lessons = Lesson::query()
                ->whereIn('id', $lessonSections->pluck('sectionable_id'))
                ->get()
                ->keyBy('id');
            $evidence = LessonWatchEvidence::query()
                ->where('user_id', $user->id)
                ->whereIn('course_section_id', $lessonSections->pluck('id'))
                ->get()
                ->keyBy('course_section_id');
            foreach ($lessonSections as $section) {
                $lesson = $lessons->get($section->sectionable_id);
                $lessonEvidence = $evidence->get($section->id);
                if (!$lesson || !$lessonEvidence) {
                    return false;
                }

                $required = $this->learningEvidence->requiredSeconds(
                    $lesson,
                    $lessonEvidence->duration_seconds
                );
                if ($required === null || (int) $lessonEvidence->verified_seconds < $required) {
                    return false;
                }
            }
        }

        $quizSections = $sections->where('sectionable_type', ItemList::class);
        if ($quizSections->isNotEmpty()) {
            $passedSectionIds = ExamAttempt::query()
                ->where('user_id', $user->id)
                ->where('status', ExamAttempt::STATUS_COMPLETED)
                ->where('is_passed', true)
                ->whereIn('section_id', $quizSections->pluck('id'))
                ->distinct()
                ->pluck('section_id');

            if ($passedSectionIds->count() !== $quizSections->count()) {
                return false;
            }
        }

        return true;
    }
}
