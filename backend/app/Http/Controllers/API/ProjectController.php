<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\Setting;
use App\Models\UserProjectEvaluation;
use App\Services\ProjectSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ProjectController extends Controller
{
    public function __construct(private ProjectSubmissionService $submissionService)
    {
    }

    public function show($projectId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return $this->error('Unauthenticated', 401);
            }

            $project = Project::with(['section.course', 'section.module'])->findOrFail($projectId);
            $courseId = (int) optional($project->section)->course_id;
            if (!$courseId || !$this->checkCourseAccess($user->id, $courseId)) {
                return $this->error('You are not authorized to access this project', 403);
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

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Project details retrieved successfully',
                'data' => [
                    'id' => $project->id,
                    'requirements_text' => $project->requirements_text,
                    // Prompt/model settings deliberately stay server-side.
                    'passing_score' => $project->passing_score,
                    'is_graduation_project' => $project->is_graduation_project,
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
            return $this->error('Project not found', 404);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->error('Failed to retrieve project details', 500);
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
                return $this->error('Unauthenticated', 401);
            }

            if (!$request->filled('client_submission_id') && $request->hasHeader('Idempotency-Key')) {
                $request->merge(['client_submission_id' => $request->header('Idempotency-Key')]);
            }

            $request->validate([
                'submission_text' => 'nullable|string|max:20000|required_without:submission_file',
                'submission_file' => [
                    'nullable',
                    'file',
                    'required_without:submission_text',
                    'max:' . (int) config('projects.maximum_file_kilobytes', 25600),
                    'mimetypes:' . implode(',', (array) config('projects.allowed_mime_types', [])),
                ],
                'client_submission_id' => 'nullable|string|max:100',
                'metadata' => 'nullable|array',
            ]);

            $project = Project::with('section')->findOrFail($projectId);
            $courseId = (int) optional($project->section)->course_id;
            if (!$courseId || !$this->checkCourseAccess($user->id, $courseId)) {
                return $this->error('You are not authorized to submit this project', 403);
            }
            if (!$this->hasCompletedProjectPrerequisites($user->id, $project)) {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'code' => 'project_prerequisites_incomplete',
                    'message' => 'Complete the module reels before submitting its project',
                    'data' => null,
                ], 409);
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
                'message' => 'استلمنا مشروعك وبدأت مراجعته.',
                'data' => $this->submissionPayload($submission),
            ], $httpStatus);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'message' => 'Validation failed',
                'data' => null,
                'errors' => $exception->errors(),
            ], 422);
        } catch (\UnexpectedValueException $exception) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'submission_idempotency_conflict',
                'message' => 'The submission key is already associated with different content',
                'data' => null,
            ], 409);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->error('Project not found', 404);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->error('Failed to submit project', 500);
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
            return $this->error('Submission not found', 404);
        }

        $submission = $this->submissionService->finalizeIfDue($submission);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Submission status retrieved successfully',
            'data' => $this->submissionPayload($submission),
        ]);
    }

    public function downloadSubmissionFile(ProjectSubmission $submission): Response
    {
        $user = auth('api')->user();
        if (!$user || $submission->user_id !== $user->id || !$submission->submission_file) {
            return $this->error('Submission file not found', 404);
        }

        $path = ltrim(str_replace('\\', '/', trim((string) $submission->submission_file)), '/');
        $expectedPrefix = "project_submissions/{$submission->user_id}/{$submission->project_id}/";
        if ($path === '' || str_contains($path, '../') || !str_starts_with($path, $expectedPrefix)) {
            return $this->error('Submission file not found', 404);
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
            return $this->error('Submission file not found', 404);
        }

        $downloadName = basename((string) ($submission->original_file_name ?: 'project-submission'));

        return $disk->download($path, $downloadName, [
            'Content-Type' => $submission->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function getUserProjectEvaluations(Request $request, $courseId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return $this->error('Unauthenticated', 401);
            }

            if (!$this->checkCourseAccess($user->id, (int) $courseId)) {
                return $this->error('You are not authorized to access this course', 403);
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
                'message' => 'Project evaluations retrieved successfully',
                'data' => [
                    'course_id' => (int) $courseId,
                    'total_projects' => $projects->count(),
                    'passed_projects' => $evaluations->where('passed', true)->count(),
                    'projects' => $projects,
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->error('Failed to retrieve project evaluations', 500);
        }
    }

    private function checkCourseAccess(int $userId, int $courseId): bool
    {
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

    private function hasCompletedProjectPrerequisites(int $userId, Project $project): bool
    {
        $section = $project->section;
        if (!$section) {
            return false;
        }

        $settings = Setting::first();
        if ($settings && !$settings->enforce_course_section_order) {
            return true;
        }

        $query = CourseSection::query()
            ->where('course_id', $section->course_id)
            ->where('order', '<', $section->order);

        if ($section->module_id) {
            $query->where('module_id', $section->module_id);
        }

        $requiredSectionIds = $query->orderBy('order')
            ->get()
            ->reject(fn (CourseSection $candidate) => $candidate->getSectionType() === 'project')
            ->pluck('id');

        if ($requiredSectionIds->isEmpty()) {
            return true;
        }

        $completedCount = \App\Models\StudentSectionProgress::query()
            ->where('user_id', $userId)
            ->whereIn('course_section_id', $requiredSectionIds)
            ->where('is_completed', true)
            ->distinct('course_section_id')
            ->count('course_section_id');

        return $completedCount === $requiredSectionIds->count();
    }

    private function submissionPayload(ProjectSubmission $submission): array
    {
        $metadata = is_array($submission->submission_metadata)
            ? $submission->submission_metadata
            : [];

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
