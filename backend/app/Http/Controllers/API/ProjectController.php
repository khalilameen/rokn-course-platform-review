<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\AiInputAttachment;
use App\Models\AiEntitlementUsage;
use App\Models\CourseSection;
use App\Models\CourseChatTurn;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectFeedbackMessage;
use App\Models\User;
use App\Models\UserProjectEvaluation;
use App\Services\ProjectSubmissionService;
use App\Services\ProjectFeedbackThreadService;
use App\Services\CourseCompletionService;
use App\Services\CourseChatAccessService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseStagedAuthoringService;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\AiInputAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
        private CourseAccessPlanService $accessPlans,
        private AiInputAttachmentService $attachments,
        private CourseStagedAuthoringService $stagedAuthoring,
        private CourseRevisionLearnerReadService $revisionReads
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
            if ($revisionChanged = $this->revisionChangedResponse($project)) return $revisionChanged;
            $courseId = (int) optional($project->section)->course_id;
            if (
                !$courseId
                || !$project->section
                || !$this->courseCompletion->canAccessSection($user, $project->section)
            ) {
                return $this->error('هذا المشروع غير متاح لحسابك', 403);
            }

            $equivalentProjectIds = $this->stagedAuthoring->equivalentEntityIds(
                Project::class,
                (int) $project->id
            );
            $latestSubmission = ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $equivalentProjectIds)
                ->latest('id')
                ->first();
            if ($latestSubmission) {
                $latestSubmission = $this->submissionService->finalizeIfDue($latestSubmission);
            }

            $evaluation = $project->evaluationForUser($user->id);
            $enrollment = $this->courseAccess->activeProjectEnrollmentFor((int) $user->id, $courseId);
            $terms = $enrollment ? $this->accessPlans->termsForEnrollment($enrollment) : null;
            $feedbackContract = $this->accessPlans->publicPayloadFromTerms($terms ?? []);
            $variableCostAllowed = $enrollment
                && $this->courseAccess->enrollmentAllowsVariableCostFeatures($enrollment);
            $feedbackLevel = $variableCostAllowed
                ? (string) $feedbackContract['project_feedback_level']
                : 'pass_only';
            $projectReportEnabled = $variableCostAllowed
                && (bool) $feedbackContract['project_report_enabled'];
            $projectReplyEnabled = (bool) $feedbackContract['project_thread_reply_enabled']
                && $variableCostAllowed;

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل المشروع',
                'data' => [
                    'id' => $project->id,
                    'requirements_text' => $project->requirements_text,
                    // Prompt/model settings deliberately stay server-side.
                    'submission_max_files' => max(1, min(5, (int) ($project->submission_max_files ?: 3))),
                    'submission_allowed_mime_types' => $this->projectAllowedMimeTypes($project),
                    'is_graduation_project' => $project->is_graduation_project,
                    'project_feedback' => [
                        'level' => $feedbackLevel,
                        'report_enabled' => $projectReportEnabled,
                        'reply_enabled' => $projectReplyEnabled,
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
                        // Participation acceptance and effort rejection are
                        // progression decisions, not a learner-facing grade.
                        'score' => data_get($evaluation->evaluation_data, 'assessment_type') === 'human_review'
                            ? $evaluation->score
                            : null,
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
                'submission_text' => 'nullable|string|max:80000',
                'submission_file' => [
                    'nullable',
                    'file',
                    'min:1',
                    'max:' . (int) config('projects.maximum_file_kilobytes', 25600),
                    'mimetypes:' . implode(',', (array) config('projects.allowed_mime_types', [])),
                ],
                'submission_files' => 'nullable|array|max:5',
                'submission_files.*' => [
                    'file', 'min:1',
                    'max:' . (int) config('projects.maximum_file_kilobytes', 25600),
                    'mimetypes:' . implode(',', (array) config('projects.allowed_mime_types', [])),
                ],
                'client_submission_id' => 'nullable|string|max:100',
                'metadata' => 'nullable|array',
            ]);

            $project = Project::with('section')->findOrFail($projectId);
            $project->loadMissing('section.course');
            if ($revisionChanged = $this->revisionChangedResponse($project)) return $revisionChanged;
            $files = array_values(array_filter([
                ...($request->file('submission_files', []) ?: []),
                $request->file('submission_file'),
            ]));
            $maximumFiles = max(1, min(5, (int) ($project->submission_max_files ?: 3)));
            $allowedMimeTypes = $this->projectAllowedMimeTypes($project);
            if (count($files) > $maximumFiles) {
                return $this->projectValidationError('submission_files', "اختر {$maximumFiles} ملفات على الأكثر");
            }
            if (!$request->filled('submission_text') && $files === []) {
                return $this->projectValidationError('submission_files', 'أضف نصًا أو ملفًا واحدًا على الأقل');
            }
            foreach ($files as $file) {
                if (!in_array(strtolower((string) $file->getMimeType()), $allowedMimeTypes, true)) {
                    return $this->projectValidationError('submission_files', 'أحد الملفات بصيغة غير متاحة لهذا المشروع');
                }
            }
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
            $enrollment = $this->courseAccess->activeProjectEnrollmentFor((int) $user->id, $courseId);
            $terms = $enrollment ? $this->accessPlans->termsForEnrollment($enrollment) : null;
            $feedbackContract = $this->accessPlans->publicPayloadFromTerms($terms ?? []);
            $projectReportEnabled = $enrollment
                && $this->courseAccess->enrollmentAllowsVariableCostFeatures($enrollment)
                && (bool) $feedbackContract['project_report_enabled'];
            if (
                $projectReportEnabled
                && $files === []
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
            if ($projectReportEnabled) {
                $providerMaximum = (int) config('openrouter.attachment_provider_max_bytes', 8388608);
                $attachmentTokens = 0;
                foreach ($files as $file) {
                    if ((int) $file->getSize() > $providerMaximum) {
                        return $this->projectValidationError('submission_files', 'اختر ملفات أصغر من 8 ميجابايت لنتمكن من مراجعتها');
                    }
                    try {
                        $attachmentTokens += $this->attachments
                            ->estimatedUploadedFileTokens($file);
                    } catch (\UnexpectedValueException) {
                        return $this->projectValidationError(
                            'submission_files',
                            'أحد الملفات لا يمكن قراءته للتقرير\nاختر نسخة أخرى'
                        );
                    }
                }
                $maxOutputTokens = max(80, min(
                    (int) config('openrouter.max_tokens', 500),
                    (int) ($terms['max_output_tokens'] ?? 320)
                ));
                $semanticText = trim(strip_tags(implode("\n", [
                    (string) $request->input('submission_text'),
                    (string) $project->requirements_text,
                ])));
                $estimatedRequestTokens = $maxOutputTokens
                    + (int) ceil(strlen($semanticText) / 4)
                    + $attachmentTokens;
                $reportBudget = max(0, (int) ($terms['project_feedback_token_budget'] ?? 0));
                $usage = AiEntitlementUsage::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('feature', AiEntitlementUsage::FEATURE_PROJECT_FEEDBACK)
                    ->first(['used_tokens', 'reserved_tokens']);
                $remainingReportTokens = max(
                    0,
                    $reportBudget
                        - (int) ($usage?->used_tokens ?? 0)
                        - (int) ($usage?->reserved_tokens ?? 0)
                );
                if ($remainingReportTokens <= 0 || $estimatedRequestTokens > $remainingReportTokens) {
                    return $this->projectValidationError(
                        'submission_files',
                        "الملفات أكبر من مساحة التقرير في فئتك\nاختر ملفات أقل أو صورًا أوضح وأصغر"
                    );
                }
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
                $files,
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
            'message' => 'nullable|string|max:20000|required_without:attachment_ids',
            'attachment_ids' => 'nullable|array|max:5|required_without:message',
            'attachment_ids.*' => 'uuid|distinct',
            'client_request_id' => 'required|string|min:8|max:100',
        ]);

        try {
            $this->feedbackThreads->queueReply(
                $user,
                $thread,
                (string) ($validated['message'] ?? ''),
                (string) $validated['client_request_id'],
                array_values($validated['attachment_ids'] ?? [])
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

    public function uploadFeedbackAttachment(Request $request, ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || (int) $thread->user_id !== (int) $user->id) {
            return $this->error('محادثة المشروع غير متاحة', 404);
        }
        $contract = $this->feedbackThreads->activeReplyContract($thread);
        $course = Course::query()->findOrFail($thread->course_id);
        if (!$contract || !(bool) ($contract['project_attachments_enabled'] ?? false)) {
            return $this->error('المرفقات غير متاحة في هذه الفئة', 403);
        }
        $validated = $request->validate([
            'client_upload_id' => 'required|uuid',
            'attachment' => [
                'required', 'file',
                'max:' . min(
                    (int) config('projects.maximum_file_kilobytes', 25600),
                    (int) floor((int) config('openrouter.attachment_provider_max_bytes', 8388608) / 1024)
                ),
                'mimetypes:' . implode(',', $this->attachments->allowedMimeTypes()),
            ],
        ]);
        try {
            $attachment = $this->attachments->store(
                $user,
                $course,
                $validated['attachment'],
                AiInputAttachment::PURPOSE_PROJECT_FOLLOWUP,
                (string) $validated['client_upload_id']
            );
        } catch (\UnexpectedValueException $exception) {
            return response()->json([
                'status' => 409, 'success' => false,
                'code' => 'project_attachment_upload_conflict',
                'message' => "تغيّر الملف أثناء الإرسال\nاختره مرة أخرى",
                'data' => null,
            ], 409);
        }
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم رفع الملف',
            'data' => [
                'id' => (string) $attachment->public_id,
                'name' => (string) $attachment->original_file_name,
                'mime_type' => (string) $attachment->mime_type,
                'size_bytes' => (int) $attachment->size_bytes,
            ],
        ]);
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

    public function downloadSubmissionAttachment(AiInputAttachment $attachment): Response
    {
        $user = auth('api')->user();
        if (!$user || (int) $attachment->user_id !== (int) $user->id
            || $attachment->owner_type !== AiInputAttachment::OWNER_PROJECT_SUBMISSION) {
            return $this->error('ملف المشروع غير متاح', 404);
        }
        $disk = Storage::disk((string) $attachment->storage_disk);
        $path = ltrim((string) $attachment->storage_path, '/');
        if ($path === '' || str_contains($path, '../') || !$disk->exists($path)) {
            return $this->error('ملف المشروع غير متاح', 404);
        }
        $name = DownloadFilename::safe(
            (string) $attachment->original_file_name,
            'project-submission',
            pathinfo($path, PATHINFO_EXTENSION)
        );
        return $disk->download($path, $name, [
            'Content-Type' => (string) $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => DownloadFilename::disposition($name),
        ]);
    }

    /**
     * Short-lived download used by the device viewer. The signature binds the
     * learner id and attachment id, so the viewer does not need to forward the
     * app bearer token to a second process.
     */
    public function downloadInputAttachment(Request $request, AiInputAttachment $attachment): Response
    {
        $signedUserId = (int) $request->query('user');
        if (
            $signedUserId <= 0
            || $signedUserId !== (int) $attachment->user_id
            || !User::query()->whereKey($signedUserId)->where('active', true)->exists()
            || !$this->inputAttachmentBelongsToUser($attachment, $signedUserId)
        ) {
            abort(404);
        }
        $disk = Storage::disk((string) $attachment->storage_disk);
        $path = ltrim((string) $attachment->storage_path, '/');
        if ($path === '' || str_contains($path, '../') || !$disk->exists($path)) {
            abort(404);
        }
        $name = DownloadFilename::safe(
            (string) $attachment->original_file_name,
            'project-attachment',
            pathinfo($path, PATHINFO_EXTENSION)
        );
        return $disk->download($path, $name, [
            'Content-Type' => (string) $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => DownloadFilename::disposition($name),
        ]);
    }

    /** Refresh short-lived artifact metadata without exposing storage paths. */
    public function showInputAttachment(AiInputAttachment $attachment): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || !$this->inputAttachmentBelongsToUser($attachment, (int) $user->id)) {
            abort(404);
        }
        $expiresAt = now()->addMinutes(30);
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تجهيز الملف',
            'data' => [
                'id' => (string) $attachment->public_id,
                'name' => (string) $attachment->original_file_name,
                'mime_type' => (string) $attachment->mime_type,
                'size_bytes' => (int) $attachment->size_bytes,
                'download_url' => URL::temporarySignedRoute(
                    'api.project-input-attachments.download',
                    $expiresAt,
                    ['attachment' => $attachment->public_id, 'user' => $attachment->user_id]
                ),
                'download_url_expires_at' => $expiresAt->toIso8601String(),
            ],
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
            $aliasToCurrent = collect();
            foreach ($projectIds as $projectId) {
                foreach ($this->stagedAuthoring->equivalentEntityIds(Project::class, (int) $projectId) as $alias) {
                    $aliasToCurrent->put($alias, (int) $projectId);
                }
            }

            ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $aliasToCurrent->keys())
                ->where('review_status', ProjectSubmission::STATUS_PENDING)
                ->where('auto_pass_at', '<=', now())
                ->get()
                ->each(fn (ProjectSubmission $submission) => $this->submissionService->finalizeIfDue($submission));

            $evaluations = $this->revisionReads->projectEvaluations(
                (int) $user->id,
                $projectIds
            );
            $latestSubmissions = ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $aliasToCurrent->keys())
                ->orderByDesc('id')
                ->get();
            $latestSubmissions = $latestSubmissions->reduce(function ($mapped, ProjectSubmission $submission) use ($aliasToCurrent) {
                $currentId = (int) ($aliasToCurrent->get((int) $submission->project_id) ?? 0);
                if ($currentId > 0 && !$mapped->has($currentId)) $mapped->put($currentId, $submission);
                return $mapped;
            }, collect());

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
                        'is_graduation_project' => $section->project->is_graduation_project,
                    ],
                    'evaluation' => $evaluation ? [
                        'score' => data_get($evaluation->evaluation_data, 'assessment_type') === 'human_review'
                            ? $evaluation->score
                            : null,
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
        // Project reads, submissions and evaluation history must share the
        // exact entitlement boundary used by playback and course details.
        // The old local query ignored financial holds and could keep project
        // access alive after the same enrollment was suspended elsewhere.
        return $this->courseAccess->hasLearningAccess($userId, $courseId);
    }

    private function submissionPayload(ProjectSubmission $submission): array
    {
        $submission->loadMissing(['aiInputAttachments', 'latestReviewDecision']);
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
            // AI reports live in feedback_thread. The submission feedback is
            // the human/automatic review decision, whose append-only ledger is
            // authoritative even for rows touched by an older AI worker.
            'feedback' => $submission->latestReviewDecision?->feedback
                ?? $submission->feedback,
            'file_url' => $submission->submission_file_url,
            'attachments' => $submission->aiInputAttachments->map(fn (AiInputAttachment $attachment): array => [
                'id' => (string) $attachment->public_id,
                'name' => (string) $attachment->original_file_name,
                'mime_type' => (string) $attachment->mime_type,
                'size_bytes' => (int) $attachment->size_bytes,
                'download_url' => $this->inputAttachmentDownloadUrl($attachment),
                'download_url_expires_at' => now()->addMinutes(30)->toIso8601String(),
            ])->values()->all(),
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

    private function revisionChangedResponse(Project $project): ?JsonResponse
    {
        $course = $project->section?->course;
        if (!$course || !$course->is_coming_soon) return null;
        $revision = $this->stagedAuthoring->activeArchiveForCourse($course);
        if (!$revision) return null;
        $canonical = $revision->canonicalCourse()->firstOrFail();

        return response()->json([
            'status' => 409,
            'success' => false,
            'code' => 'course_revision_changed',
            'message' => "تم تحديث الكورس\nنعيد تحميل أحدث نسخة",
            'data' => [
                'course_id' => (int) $canonical->id,
                'published_revision' => (int) (
                    $canonical->last_published_authoring_version ?: $canonical->authoring_version
                ),
                'reload_endpoint' => "/api/v1/courses/{$canonical->id}/details",
            ],
        ], 409);
    }

    /** @return list<string> */
    private function projectAllowedMimeTypes(Project $project): array
    {
        $configured = array_values(array_intersect(
            array_map('strtolower', (array) ($project->submission_allowed_mime_types ?: [])),
            $this->attachments->allowedMimeTypes()
        ));
        return $configured !== [] ? $configured : $this->attachments->allowedMimeTypes();
    }

    private function inputAttachmentDownloadUrl(AiInputAttachment $attachment): string
    {
        return URL::temporarySignedRoute(
            'api.project-input-attachments.download',
            now()->addMinutes(30),
            ['attachment' => $attachment->public_id, 'user' => $attachment->user_id]
        );
    }

    private function inputAttachmentBelongsToUser(
        AiInputAttachment $attachment,
        int $userId
    ): bool {
        if (
            $userId <= 0
            || (int) $attachment->user_id !== $userId
            || $attachment->status !== AiInputAttachment::READY
        ) return false;

        return match ($attachment->owner_type) {
            AiInputAttachment::OWNER_COURSE_CHAT_TURN =>
                $attachment->purpose === AiInputAttachment::PURPOSE_COURSE_CHAT
                && CourseChatTurn::query()
                    ->whereKey($attachment->owner_id)
                    ->where('user_id', $userId)
                    ->where('course_id', $attachment->course_id)
                    ->exists(),
            AiInputAttachment::OWNER_PROJECT_SUBMISSION =>
                $attachment->purpose === AiInputAttachment::PURPOSE_PROJECT_SUBMISSION
                && $this->submissionAttachmentMatchesCourse($attachment, $userId),
            AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE =>
                $attachment->purpose === AiInputAttachment::PURPOSE_PROJECT_FOLLOWUP
                && ProjectFeedbackMessage::query()
                    ->whereKey($attachment->owner_id)
                    ->whereHas('thread', fn ($query) => $query
                        ->where('user_id', $userId)
                        ->where('course_id', $attachment->course_id)
                    )->exists(),
            default => false,
        };
    }

    private function submissionAttachmentMatchesCourse(
        AiInputAttachment $attachment,
        int $userId
    ): bool {
        $submission = ProjectSubmission::query()
            ->whereKey($attachment->owner_id)
            ->where('user_id', $userId)
            ->with('project.section')
            ->first();
        if (!$submission) return false;
        $snapshotCourseId = (int) data_get($submission->evaluation_snapshot, 'course_id', 0);

        return $snapshotCourseId === (int) $attachment->course_id
            || (int) ($submission->project?->section?->course_id ?? 0) === (int) $attachment->course_id;
    }

    private function projectValidationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'status' => 422, 'success' => false,
            'message' => 'راجع ملفات المشروع',
            'data' => null, 'errors' => [$field => [$message]],
        ], 422);
    }
}
