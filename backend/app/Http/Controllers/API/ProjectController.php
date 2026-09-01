<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\ProjectFeedbackThread;
use App\Models\UserProjectEvaluation;
use App\Services\ProjectSubmissionService;
use App\Services\ProjectFeedbackThreadService;
use App\Services\CourseCompletionService;
use App\Services\CourseChatAccessService;
use App\Services\CourseAccessPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Support\DownloadFilename;

final class ProjectController extends Controller
{
    public function __construct(
        private ProjectSubmissionService $submissionService,
        private CourseCompletionService $courseCompletion,
        private ProjectFeedbackThreadService $feedbackThreads,
        private CourseChatAccessService $courseAccess,
        private CourseAccessPlanService $accessPlans
    ) {
    }

    public function show($projectId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return $this->error('سجّل الدخول أولًا', 401);
            }

            $project = Project::with(['section.course', 'section.module'])->findOrFail($projectId);
            $courseId = (int) optional($project->section)->course_id;
            if (
                !$courseId
                || !$project->section
                || !$this->courseCompletion->canAccessSection($user, $project->section)
            ) {
                return $this->error('هذا المشروع غير متاح لحسابك', 403);
            }

            $latestSubmission = ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->latest('id')
                ->first();
            if ($latestSubmission) {
                $latestSubmission = $this->submissionService->finalizeIfDue($latestSubmission);
            }

            $evaluation = $project->evaluationForUser($user->id);
            $enrollment = $this->courseAccess->activeEnrollmentFor((int) $user->id, $courseId);
            $terms = $enrollment ? $this->accessPlans->termsForEnrollment($enrollment) : null;
            $feedbackContract = $this->accessPlans->publicPayloadFromTerms($terms ?? []);
            $feedbackLevel = (string) $feedbackContract['project_feedback_level'];

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل المشروع',
                'data' => [
                    'id' => $project->id,
                    'requirements_text' => $project->requirements_text,
                    // Prompt/model settings deliberately stay server-side.
                    'passing_score' => $project->passing_score,
                    'is_graduation_project' => $project->is_graduation_project,
                    'project_feedback' => [
                        'level' => $feedbackLevel,
                        'report_enabled' => (bool) $feedbackContract['project_report_enabled'],
                        'reply_enabled' => (bool) $feedbackContract['project_thread_reply_enabled'],
                        'message_limit' => (int) $feedbackContract['project_message_limit'],
                        'token_budget' => (int) $feedbackContract['project_token_budget'],
                    ],
                    'section' => [
                        'id' => $project->section->id,
                        'title' => $project->section->title,
                        'order' => $project->section->order,
                    ],
                    'module' => $project->section->module ? [
                        'id' => $project->section->module->id,
                        'title' => $project->section->module->title,
                        'order' => $project->section->module->order,
                    ] : null,
                    'course' => [
                        'id' => $project->section->course->id,
                        'title' => $project->section->course->name_ar,
                    ],
                    'user_evaluation' => $evaluation ? [
                        'id' => $evaluation->id,
                        'score' => data_get($evaluation->evaluation_data, 'assessment_type') === 'participation'
                            ? null
                            : $evaluation->score,
                        'passed' => $evaluation->passed,
                        'assessment_type' => data_get($evaluation->evaluation_data, 'assessment_type', 'legacy'),
                        'skill_verified' => (bool) data_get($evaluation->evaluation_data, 'skill_verified', false),
                        'evaluation_data' => $evaluation->evaluation_data,
                        'submitted_at' => $evaluation->created_at,
                    ] : null,
                    'latest_submission' => $latestSubmission
                        ? $this->submissionPayload($latestSubmission)
                        : null,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->error('المشروع غير متاح', 404);
        } catch (\Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);
            return $this->error('تعذّر تحميل المشروع', 500);
        }
    }

    /**
     * Secure submission endpoint. Only the attempt is accepted from the client;
     * score and pass/fail are exclusively server decisions.
     */
    public function submit(Request $request, $projectId, bool $legacyResponse = false): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return $this->error('سجّل الدخول أولًا', 401);
            }

            if (!$request->filled('client_submission_id') && $request->hasHeader('Idempotency-Key')) {
                $request->merge(['client_submission_id' => $request->header('Idempotency-Key')]);
            }

            $request->validate([
                'submission_text' => 'nullable|string|max:80000|required_without:submission_file',
                'submission_file' => [
                    'nullable',
                    'file',
                    'min:1',
                    'required_without:submission_text',
                    'max:' . (int) config('projects.maximum_file_kilobytes', 25600),
                    'mimetypes:' . implode(',', (array) config('projects.allowed_mime_types', [])),
                ],
                'client_submission_id' => 'nullable|string|max:100',
                'metadata' => 'nullable|array',
            ]);

            $project = Project::with('section')->findOrFail($projectId);
            $courseId = (int) optional($project->section)->course_id;
            if (!$courseId || !$project->section || !$this->checkCourseAccess($user->id, $courseId)) {
                return $this->error('لا يمكنك تسليم هذا المشروع من حسابك', 403);
            }
            if (!$this->courseCompletion->canAccessSection($user, $project->section)) {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'code' => 'project_prerequisites_incomplete',
                    'message' => "أكمل المحتوى السابق أولًا\nثم سلّم المشروع",
                    'data' => null,
                ], 409);
            }
            $enrollment = $this->courseAccess->activeEnrollmentFor((int) $user->id, $courseId);
            $terms = $enrollment ? $this->accessPlans->termsForEnrollment($enrollment) : null;
            $feedbackContract = $this->accessPlans->publicPayloadFromTerms($terms ?? []);
            if (
                (bool) $feedbackContract['project_report_enabled']
                && mb_strlen(trim(strip_tags((string) $request->input('submission_text')))) < 10
            ) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'code' => 'project_note_required',
                    'message' => 'اكتب سطرًا واضحًا عما نفذته لنعد تقرير مشروعك',
                    'data' => null,
                ], 422);
            }

            $idempotencyKey = (string) (
                $request->header('Idempotency-Key')
                ?: $request->input('client_submission_id')
                ?: Str::uuid()
            );

            $submission = $this->submissionService->submit(
                $user,
                $project,
                $request->input('submission_text'),
                $request->file('submission_file'),
                $idempotencyKey,
                (array) $request->input('metadata', [])
            );

            $httpStatus = $legacyResponse ? 200 : 202;
            return response()->json([
                'status' => $httpStatus,
                'success' => true,
                'message' => 'استلمنا مشروعك وبدأت مراجعته',
                'data' => $this->submissionPayload($submission),
            ], $httpStatus);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'message' => 'راجع بيانات المشروع',
                'data' => null,
                'errors' => $exception->errors(),
            ], 422);
        } catch (\UnexpectedValueException $exception) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'submission_idempotency_conflict',
                'message' => "تغيّر محتوى المشروع أثناء الإرسال\nأعد المحاولة",
                'data' => null,
            ], 409);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->error('المشروع غير متاح', 404);
        } catch (\Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);
            return $this->error('تعذّر إرسال المشروع', 500);
        }
    }

    /**
     * Legacy compatibility route. score, passed and evaluation_data are ignored.
     */
    public function saveEvaluation(Request $request, $projectId): JsonResponse
    {
        return $this->submit($request, $projectId, true);
    }

    public function submissionStatus(ProjectSubmission $submission): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || $submission->user_id !== $user->id) {
            return $this->error('التسليم غير متاح', 404);
        }

        $submission = $this->submissionService->finalizeIfDue($submission);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل حالة التسليم',
            'data' => $this->submissionPayload($submission),
        ]);
    }

    public function feedbackThread(ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || (int) $thread->user_id !== (int) $user->id) {
            return $this->error('محادثة المشروع غير متاحة', 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل مراجعة المشروع',
            'data' => $this->feedbackThreads->payload($thread),
        ]);
    }

    public function sendFeedbackMessage(Request $request, ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || (int) $thread->user_id !== (int) $user->id) {
            return $this->error('محادثة المشروع غير متاحة', 404);
        }

        if (!$request->filled('client_request_id') && $request->hasHeader('Idempotency-Key')) {
            $request->merge(['client_request_id' => $request->header('Idempotency-Key')]);
        }
        $validated = $request->validate([
            'message' => 'required|string|min:1|max:20000',
            'client_request_id' => 'required|string|min:8|max:100',
        ]);

        try {
            $this->feedbackThreads->queueReply(
                $user,
                $thread,
                (string) $validated['message'],
                (string) $validated['client_request_id']
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return $this->error('الردود غير متاحة لهذا المشروع', 403);
        } catch (\UnexpectedValueException $exception) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'project_message_idempotency_conflict',
                'message' => "تغيّرت الرسالة أثناء الإرسال\nأعد المحاولة",
                'data' => null,
            ], 409);
        }

        return response()->json([
            'status' => 202,
            'success' => true,
            'message' => 'استلمنا رسالتك',
            'data' => $this->feedbackThreads->payload($thread->fresh()),
        ], 202);
    }

    public function downloadSubmissionFile(ProjectSubmission $submission): Response
    {
        $user = auth('api')->user();
        if (!$user || $submission->user_id !== $user->id || !$submission->submission_file) {
            return $this->error('ملف المشروع غير متاح', 404);
        }

        $path = ltrim(str_replace('\\', '/', trim((string) $submission->submission_file)), '/');
        $expectedPrefix = "project_submissions/{$submission->user_id}/{$submission->project_id}/";
        if ($path === '' || str_contains($path, '../') || !str_starts_with($path, $expectedPrefix)) {
            return $this->error('ملف المشروع غير متاح', 404);
        }

        $disk = null;
        foreach ($submission->submissionDiskCandidates() as $candidate) {
            $candidateDisk = Storage::disk($candidate);
            if ($candidateDisk->exists($path)) {
                $disk = $candidateDisk;
                break;
            }
        }
        if (!$disk) {
            return $this->error('ملف المشروع غير متاح', 404);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $downloadName = DownloadFilename::safe(
            $submission->original_file_name,
            'project-submission',
            $extension
        );

        return $disk->download($path, $downloadName, [
            'Content-Type' => $submission->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => DownloadFilename::disposition($downloadName),
        ]);
    }

    public function getUserProjectEvaluations(Request $request, $courseId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return $this->error('سجّل الدخول أولًا', 401);
            }

            if (!$this->checkCourseAccess($user->id, (int) $courseId)) {
                return $this->error('هذا الكورس غير متاح لحسابك', 403);
            }

            $projectSections = CourseSection::where('course_id', $courseId)
                ->where('section_type', 'project')
                ->with(['project', 'module'])
                ->get();
            $projectIds = $projectSections->pluck('project.id')->filter();

            ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $projectIds)
                ->where('review_status', ProjectSubmission::STATUS_PENDING)
                ->where('auto_pass_at', '<=', now())
                ->get()
                ->each(fn (ProjectSubmission $submission) => $this->submissionService->finalizeIfDue($submission));

            $evaluations = UserProjectEvaluation::where('user_id', $user->id)
                ->whereIn('project_id', $projectIds)
                ->get()
                ->keyBy('project_id');
            $latestSubmissions = ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $projectIds)
                ->orderByDesc('id')
                ->get()
                ->unique('project_id')
                ->keyBy('project_id');

            $projects = $projectSections->map(function ($section) use ($evaluations, $latestSubmissions) {
                if (!$section->project) {
                    return null;
                }

                $evaluation = $evaluations->get($section->project->id);
                $submission = $latestSubmissions->get($section->project->id);
                $status = $submission?->review_status
                    ?? ($evaluation ? ($evaluation->passed ? 'passed' : 'needs_resubmission') : 'not_submitted');

                return [
                    'section_id' => $section->id,
                    'section_title' => $section->title,
                    'module' => $section->module ? [
                        'id' => $section->module->id,
                        'title' => $section->module->title,
                    ] : null,
                    'project' => [
                        'id' => $section->project->id,
                        'passing_score' => $section->project->passing_score,
                        'is_graduation_project' => $section->project->is_graduation_project,
                    ],
                    'evaluation' => $evaluation ? [
                        'score' => $evaluation->score,
                        'passed' => $evaluation->passed,
                        'submitted_at' => $evaluation->created_at,
                    ] : null,
                    'latest_submission' => $submission ? $this->submissionPayload($submission) : null,
                    'status' => $status,
                ];
            })->filter()->values();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل تقييمات المشروعات',
                'data' => [
                    'course_id' => (int) $courseId,
                    'total_projects' => $projects->count(),
                    'passed_projects' => $evaluations->where('passed', true)->count(),
                    'projects' => $projects,
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);
            return $this->error('تعذّر تحميل تقييمات المشروعات', 500);
        }
    }

    private function checkCourseAccess(int $userId, int $courseId): bool
    {
        $course = \App\Models\Course::query()->find($courseId);
        if (!$course || !$course->isPublishedForLearning()) {
            return false;
        }

        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();
        if ($enrollment && $enrollment->isActive()) {
            return true;
        }

        $parentCourseIds = CourseSection::where('sectionable_type', 'App\\Models\\Course')
            ->where('sectionable_id', $courseId)
            ->pluck('course_id');
        if ($parentCourseIds->isEmpty()) {
            return false;
        }

        $parentEnrollment = CourseEnrollment::where('user_id', $userId)
            ->whereIn('course_id', $parentCourseIds)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        return (bool) ($parentEnrollment && $parentEnrollment->isActive());
    }

    private function submissionPayload(ProjectSubmission $submission): array
    {
        $metadata = is_array($submission->submission_metadata)
            ? $submission->submission_metadata
            : [];

        $thread = $submission->feedbackThread;

        return [
            'id' => $submission->public_id,
            'project_id' => $submission->project_id,
            'status' => $submission->review_status,
            'is_reviewing' => $submission->review_status === ProjectSubmission::STATUS_PENDING,
            'passed' => $submission->review_status === ProjectSubmission::STATUS_PASSED,
            'can_continue' => $submission->review_status === ProjectSubmission::STATUS_PASSED,
            'authoritative' => true,
            'provisional' => false,
            'assessment_type' => $metadata['assessment_type'] ?? null,
            'skill_verified' => (bool) ($metadata['skill_verified'] ?? false),
            'needs_resubmission' => $submission->review_status === ProjectSubmission::STATUS_NEEDS_RESUBMISSION,
            'feedback' => $submission->feedback,
            'file_url' => $submission->submission_file_url,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'poll_after_seconds' => $submission->review_status === ProjectSubmission::STATUS_PENDING ? 3 : null,
            'feedback_thread' => $thread ? $this->feedbackThreads->payload($thread) : null,
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $status);
    }
}
