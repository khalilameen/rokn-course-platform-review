<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\AiPlanLimitReachedException;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Services\AiEntitlementBudgetService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseChatAccessService;
use App\Services\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class GenerateProjectFeedback implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;
    public int $uniqueFor = 600;
    public bool $failOnTimeout = true;
    public string $executionId;

    public function __construct(public int $submissionId)
    {
        $this->executionId = (string) Str::uuid();
        $this->onQueue('ai-feedback');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [20, 90];
    }

    public function uniqueId(): string
    {
        return 'project-feedback:' . $this->submissionId;
    }

    public function handle(
        CourseChatAccessService $access,
        CourseAccessPlanService $plans,
        AiEntitlementBudgetService $budget,
        OpenRouterService $openRouter
    ): void {
        $submission = ProjectSubmission::with('project')->find($this->submissionId);
        if (!$submission || $submission->review_status !== ProjectSubmission::STATUS_PASSED) return;

        $section = CourseSection::query()
            ->where('sectionable_type', Project::class)
            ->where('sectionable_id', $submission->project_id)
            ->with('course')
            ->first();
        if (!$section?->course) return;

        $enrollment = $access->activeEnrollmentFor((int) $submission->user_id, (int) $section->course_id);
        $terms = $enrollment ? $plans->termsForEnrollment($enrollment) : null;
        if (!$enrollment || !$terms || !in_array($terms['project_feedback_level'] ?? null, ['report', 'enhanced'], true)) return;

        $metadata = is_array($submission->submission_metadata) ? $submission->submission_metadata : [];
        // A worker may die after marking the submission as processing. Let the
        // queued retry continue; ShouldBeUnique still prevents concurrent runs.
        if (data_get($metadata, 'ai_feedback.status') === 'ready') return;
        $text = trim(strip_tags((string) $submission->submission_text));
        if ($text === '') {
            // Never pretend that a text-only model inspected a private file.
            $metadata['ai_feedback'] = ['status' => 'not_applicable', 'reason' => 'no_text_input'];
            $submission->forceFill(['submission_metadata' => $metadata])->save();
            return;
        }

        $claimed = DB::transaction(function () use ($submission): bool {
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($locked->review_status !== ProjectSubmission::STATUS_PASSED) return false;
            $meta = is_array($locked->submission_metadata) ? $locked->submission_metadata : [];
            $status = (string) data_get($meta, 'ai_feedback.status', '');
            if ($status === 'ready') return false;

            $owner = (string) data_get($meta, 'ai_feedback.execution_id', '');
            $leaseExpiresAt = (string) data_get($meta, 'ai_feedback.lease_expires_at', '');
            $leaseIsLive = $leaseExpiresAt !== ''
                && strtotime($leaseExpiresAt) !== false
                && strtotime($leaseExpiresAt) > time();
            if ($status === 'processing' && $owner !== $this->executionId && $leaseIsLive) {
                return false;
            }

            $meta['ai_feedback'] = [
                'status' => 'processing',
                'execution_id' => $this->executionId,
                'attempt' => $this->attempts(),
                'started_at' => now()->toIso8601String(),
                'lease_expires_at' => now()->addSeconds($this->timeout + 30)->toIso8601String(),
            ];
            $locked->forceFill(['submission_metadata' => $meta])->save();

            return true;
        }, 3);
        if (!$claimed) return;

        $allowed = array_values(array_filter(config('openrouter.allowed_models', [])));
        $model = trim((string) (($terms['model_override'] ?? null) ?: $submission->project?->ai_model_type ?: config('openrouter.default_model')));
        if (!in_array($model, $allowed, true)) $model = (string) config('openrouter.default_model');
        $maxTokens = min(
            (int) config('openrouter.max_tokens', 500),
            (int) (($terms['max_output_tokens'] ?? null) ?: 320),
            (int) ($submission->project?->tokens_number ?: 500)
        );
        $enhanced = ($terms['project_feedback_level'] ?? null) === 'enhanced'
            && (bool) ($terms['project_output_enabled'] ?? false);
        $requirements = trim(strip_tags((string) ($submission->project?->requirements_text ?? '')));
        $messages = [[
            'role' => 'system',
            'content' => 'راجع محاولة مشروع لطالب مصري باختصار ووضوح. لا تغيّر قرار النجاح ولا تعطِ درجة. '
                . 'اكتب ما نُفّذ جيدًا ثم أهم تعديلين عمليين. لا تستخدم مدحًا جاهزًا ولا تدّع فحص ملف أو صورة. '
                . ($enhanced ? 'اختم بنموذج نصي قصير محسّن يمكن للطالب القياس عليه إذا كان مناسبًا لطبيعة المشروع.' : ''),
        ], [
            'role' => 'user',
            'content' => "المطلوب في المشروع\n{$requirements}\n\nمحاولة الطالب المكتوبة\n{$text}",
        ]];

        $reservation = null;
        try {
            $estimated = $maxTokens + (int) ceil((strlen($requirements) + strlen($text)) / 4);
            $reservation = $budget->reserve($enrollment, 'project_feedback', $estimated, $model);
            $result = $openRouter->chat(
                $model,
                $messages,
                (float) ($submission->project?->temperature ?? .35),
                $maxTokens
            );
            $budget->settle($reservation, $result);
            DB::transaction(function () use ($submission, $terms, $result): void {
                $fresh = ProjectSubmission::query()->lockForUpdate()->find($submission->id);
                if (!$fresh) return;
                $meta = is_array($fresh->submission_metadata) ? $fresh->submission_metadata : [];
                if (
                    data_get($meta, 'ai_feedback.status') === 'ready'
                    || data_get($meta, 'ai_feedback.execution_id') !== $this->executionId
                ) {
                    return;
                }
                $meta['ai_feedback'] = [
                    'status' => 'ready',
                    'level' => $terms['project_feedback_level'],
                    'generated_at' => now()->toIso8601String(),
                ];
                $fresh->forceFill([
                    'feedback' => trim((string) $result['message']),
                    'submission_metadata' => $meta,
                ])->save();
            }, 3);
        } catch (AiPlanLimitReachedException $exception) {
            $this->markUnavailable($submission->id, 'plan_budget_reached');
        } catch (\Throwable $exception) {
            $budget->release($reservation, $exception->getMessage());
            $this->markUnavailable($submission->id, 'provider_unavailable');
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markUnavailable($this->submissionId, 'worker_failed');
    }

    private function markUnavailable(int $submissionId, string $reason): void
    {
        DB::transaction(function () use ($submissionId, $reason): void {
            $submission = ProjectSubmission::query()->lockForUpdate()->find($submissionId);
            if (!$submission) return;
            $metadata = is_array($submission->submission_metadata) ? $submission->submission_metadata : [];
            if (data_get($metadata, 'ai_feedback.status') === 'ready') return;
            $owner = (string) data_get($metadata, 'ai_feedback.execution_id', '');
            if ($owner !== '' && $owner !== $this->executionId) return;

            $metadata['ai_feedback'] = [
                'status' => 'unavailable',
                'reason' => $reason,
                'failed_at' => now()->toIso8601String(),
            ];
            $submission->forceFill(['submission_metadata' => $metadata])->save();
        }, 3);
    }
}
