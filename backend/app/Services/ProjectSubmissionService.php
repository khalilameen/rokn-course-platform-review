<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateProjectFeedback;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\Certificate;
use App\Models\CourseSection;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Models\UserProjectEvaluation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProjectSubmissionService
{
    public function __construct(
        private readonly LearningRewardService $learningRewards
    ) {
    }

    public function submit(
        User $user,
        Project $project,
        ?string $text,
        ?UploadedFile $file,
        string $idempotencyKey,
        array $metadata = []
    ): ProjectSubmission {
        $submissionDisk = (string) config('projects.submission_disk', 'local');
        if ($submissionDisk === '' || !is_array(config("filesystems.disks.{$submissionDisk}"))) {
            throw new \RuntimeException('The configured project submission disk is not available.');
        }

        $requestFingerprint = $this->requestFingerprint($text, $file);
        $existing = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            $this->assertIdempotentReplay($existing, $requestFingerprint, $text, $file);
            return $this->finalizeIfDue($existing);
        }

        // A passed project is final, and a pending upload is resumed instead of duplicated.
        // This keeps retries/offline replays from ever locking the learner again.
        $activeSubmission = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->whereIn('review_status', [
                ProjectSubmission::STATUS_PENDING,
                ProjectSubmission::STATUS_PASSED,
            ])
            ->latest('id')
            ->first();
        if ($activeSubmission) {
            return $this->finalizeIfDue($activeSubmission);
        }

        $effortStatus = $this->detectEffort($text, $file);
        $storedPath = null;

        try {
            $submission = DB::transaction(function () use (
                $user,
                $project,
                $text,
                $file,
                &$storedPath,
                $idempotencyKey,
                $metadata,
                $effortStatus,
                $requestFingerprint,
                $submissionDisk
            ): ProjectSubmission {
                // Different client retry keys are still serialized per learner,
                // preventing two simultaneous uploads for the same project.
                User::query()->lockForUpdate()->findOrFail($user->id);

                $existing = ProjectSubmission::query()
                    ->where('user_id', $user->id)
                    ->where('project_id', $project->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    $this->assertIdempotentReplay($existing, $requestFingerprint, $text, $file);
                    return $existing;
                }

                $activeSubmission = ProjectSubmission::query()
                    ->where('user_id', $user->id)
                    ->where('project_id', $project->id)
                    ->whereIn('review_status', [
                        ProjectSubmission::STATUS_PENDING,
                        ProjectSubmission::STATUS_PASSED,
                    ])
                    ->latest('id')
                    ->first();
                if ($activeSubmission) {
                    return $activeSubmission;
                }

                if ($file) {
                    $storedPath = $file->store(
                        "project_submissions/{$user->id}/{$project->id}",
                        $submissionDisk
                    );
                }

                $isInvalid = $effortStatus === ProjectSubmission::EFFORT_INVALID;
                $reviewStatus = $isInvalid
                    ? ProjectSubmission::STATUS_NEEDS_RESUBMISSION
                    : ProjectSubmission::STATUS_PENDING;
                $feedback = $isInvalid
                    ? 'المحاولة غير واضحة بما يكفي للمراجعة. ارفع صورة أو ملفًا يوضح ما نفذته.'
                    : null;
                $submission = ProjectSubmission::create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'idempotency_key' => $idempotencyKey,
                    'submission_text' => $text,
                    'submission_file' => $storedPath,
                    'original_file_name' => $file?->getClientOriginalName(),
                    'mime_type' => $file?->getMimeType(),
                    'file_size' => $file?->getSize(),
                    'submission_metadata' => array_merge($metadata, [
                        'request_fingerprint' => $requestFingerprint,
                        // Persist the exact private disk with the row. Changing
                        // PROJECT_SUBMISSION_DISK later must not orphan uploads
                        // created by an older web or queue node.
                        'storage_disk' => $submissionDisk,
                    ]),
                    'effort_status' => $effortStatus,
                    // Empty/black/solid attempts are the only immediate stop.
                    // A sincere attempt keeps the short reviewing state and then passes.
                    'review_status' => $reviewStatus,
                    'review_source' => $isInvalid ? 'effort_guard' : null,
                    'score' => $isInvalid ? 0 : null,
                    'feedback' => $feedback,
                    'submitted_at' => now(),
                    'auto_pass_at' => $isInvalid
                        ? null
                        : now()->addSeconds(max(1, (int) (
                            $project->fallback_review_delay_seconds
                            ?? config('projects.fallback_review_delay_seconds', 8)
                        ))),
                    'reviewed_at' => $isInvalid ? now() : null,
                ]);

                // Legacy summary remains available to existing mobile/API consumers,
                // but no score or pass decision is accepted from the client.
                UserProjectEvaluation::updateOrCreate(
                    ['user_id' => $user->id, 'project_id' => $project->id],
                    [
                        'score' => 0,
                        'passed' => false,
                        'evaluation_data' => [
                            'status' => $reviewStatus,
                            'submission_id' => $submission->public_id,
                            'source' => $isInvalid ? 'effort_guard' : 'server_review_policy',
                        ],
                        'submission_text' => $text,
                        'submission_file' => $storedPath,
                    ]
                );

                return $submission;
            });

            return $this->finalizeIfDue($submission);
        } catch (QueryException $exception) {
            if ($storedPath) {
                Storage::disk($submissionDisk)->delete($storedPath);
            }

            $existing = ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $this->assertIdempotentReplay($existing, $requestFingerprint, $text, $file);
                return $this->finalizeIfDue($existing);
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk($submissionDisk)->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function finalizeIfDue(ProjectSubmission $submission): ProjectSubmission
    {
        if (
            $submission->review_status !== ProjectSubmission::STATUS_PENDING
            || !$submission->auto_pass_at
            || $submission->auto_pass_at->isFuture()
        ) {
            return $submission;
        }

        $result = DB::transaction(function () use ($submission): ProjectSubmission {
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($locked->review_status !== ProjectSubmission::STATUS_PENDING) {
                return $locked;
            }

            $wasAlreadyPassed = UserProjectEvaluation::query()
                ->where('user_id', $locked->user_id)
                ->where('project_id', $locked->project_id)
                ->where('passed', true)
                ->exists();
            $passed = $wasAlreadyPassed
                || $locked->effort_status !== ProjectSubmission::EFFORT_INVALID;
            $feedback = $passed
                ? 'استلمنا محاولة واضحة وفتحنا لك الخطوة التالية. هذا قبول للاستكمال وليس تقييمًا لمستوى العمل.'
                : 'المحاولة غير واضحة بما يكفي للمراجعة. ارفع صورة أو ملفًا يوضح ما نفذته.';

            return $this->applyReviewOutcome(
                $locked,
                $passed,
                'graceful_fallback',
                $feedback
            );
        });

        if ($result->review_status === ProjectSubmission::STATUS_PASSED) {
            // Feedback is a paid enhancement, never a gate. Queue/provider
            // failures cannot revoke the already granted progression.
            GenerateProjectFeedback::dispatch((int) $result->id)->afterCommit();
        }

        return $result;
    }

    public function reviewByAdmin(
        ProjectSubmission $submission,
        User $reviewer,
        bool $passed,
        ?string $feedback = null
    ): ProjectSubmission {
        if (!in_array(Str::lower((string) $reviewer->role), ['admin', 'moderator'], true)) {
            throw new AuthorizationException('Only an administrator can review project submissions.');
        }

        return DB::transaction(function () use ($submission, $reviewer, $passed, $feedback): ProjectSubmission {
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $isGracefulFallback = $locked->review_status === ProjectSubmission::STATUS_PASSED
                && $locked->review_source === 'graceful_fallback';
            if (
                $locked->review_status !== ProjectSubmission::STATUS_PENDING
                && !$isGracefulFallback
            ) {
                throw ValidationException::withMessages([
                    'submission' => ['تمت مراجعة هذه المحاولة بالفعل، لذلك لم يتغير القرار المسجل.'],
                ]);
            }

            $wasAlreadyPassed = UserProjectEvaluation::query()
                ->where('user_id', $locked->user_id)
                ->where('project_id', $locked->project_id)
                ->where('passed', true)
                ->exists();
            if (!$passed && $wasAlreadyPassed) {
                throw ValidationException::withMessages([
                    'submission' => ['لا يمكن سحب حق الطالب في الاستكمال بعد قبوله تلقائيًا. يمكن اعتماد جودة العمل يدويًا عند القبول.'],
                ]);
            }

            $reviewFeedback = trim((string) $feedback);
            if ($reviewFeedback === '') {
                $reviewFeedback = $passed
                    ? 'راجع فريق ركن المحاولة وقبلها.'
                    : 'راجع فريق ركن المحاولة وطلب إعادة إرسالها.';
            }

            return $this->applyReviewOutcome(
                $locked,
                $passed,
                'admin_manual',
                $reviewFeedback,
                $reviewer
            );
        });
    }

    private function applyReviewOutcome(
        ProjectSubmission $locked,
        bool $passed,
        string $source,
        string $feedback,
        ?User $reviewer = null
    ): ProjectSubmission {
        $status = $passed
            ? ProjectSubmission::STATUS_PASSED
            : ProjectSubmission::STATUS_NEEDS_RESUBMISSION;
        $isParticipationAcceptance = $passed && $source === 'graceful_fallback';
        // The forgiving fallback grants progression only. A numeric score is
        // evidence of assessment, so it is reserved for a human review.
        $score = $passed ? ($isParticipationAcceptance ? null : 100) : 0;
        $reviewedAt = now();
        $metadata = is_array($locked->submission_metadata)
            ? $locked->submission_metadata
            : [];
        $history = is_array($metadata['review_history'] ?? null)
            ? $metadata['review_history']
            : [];
        $history[] = [
            'status' => $status,
            'source' => $source,
            'reviewer_id' => $reviewer?->id,
            'reviewer_role' => $reviewer?->role,
            'reviewed_at' => $reviewedAt->toIso8601String(),
        ];
        $metadata['review_history'] = array_slice($history, -20);
        $metadata['assessment_type'] = $isParticipationAcceptance
            ? 'participation'
            : ($source === 'admin_manual' ? 'human_review' : 'effort_guard');
        $metadata['skill_verified'] = $passed && $source === 'admin_manual';
        $metadata['progression_credit'] = $passed;

        $locked->update([
            'review_status' => $status,
            'review_source' => $source,
            'score' => $score,
            'feedback' => $feedback,
            'reviewed_at' => $reviewedAt,
            'reviewed_by' => $reviewer?->id,
            'submission_metadata' => $metadata,
        ]);

        UserProjectEvaluation::updateOrCreate(
            ['user_id' => $locked->user_id, 'project_id' => $locked->project_id],
            [
                // This legacy summary column is not nullable on older
                // installations. Consumers must use assessment_type and
                // skill_verified before presenting it as a graded score.
                'score' => $score ?? 0,
                'passed' => $passed,
                'evaluation_data' => [
                    'status' => $status,
                    'submission_id' => $locked->public_id,
                    'source' => $source,
                    'effort_status' => $locked->effort_status,
                    'reviewer_id' => $reviewer?->id,
                    'reviewer_role' => $reviewer?->role,
                    'assessment_type' => $metadata['assessment_type'],
                    'skill_verified' => $metadata['skill_verified'],
                    'progression_credit' => $metadata['progression_credit'],
                ],
                'submission_text' => $locked->submission_text,
                'submission_file' => $locked->submission_file,
            ]
        );

        if (!$passed) {
            return $locked->fresh();
        }

        try {
            $this->learningRewards->awardFirstProject(
                $locked->user,
                $locked->project
            );
        } catch (\Throwable $exception) {
            // A reward outage must never roll back a passed project.
            report($exception);
        }

        $projectSection = CourseSection::query()
            ->where('sectionable_type', Project::class)
            ->where('sectionable_id', $locked->project_id)
            ->first();
        if (!$projectSection) {
            return $locked->fresh();
        }

        StudentSectionProgress::updateOrCreate(
            [
                'user_id' => $locked->user_id,
                'course_section_id' => $projectSection->id,
            ],
            ['is_completed' => true]
        );

        $course = $projectSection->course;
        if (!$course) {
            return $locked->fresh();
        }

        if (
            $passed
            && $source === 'admin_manual'
            && Schema::hasColumn('certificates', 'verification_level')
        ) {
            Certificate::query()
                ->where('user_id', $locked->user_id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->update(['verification_level' => 'reviewed_project']);
        }

        $courseSectionIds = CourseSection::query()
            ->where('course_id', $course->id)
            ->pluck('id');
        $completedSections = StudentSectionProgress::query()
            ->where('user_id', $locked->user_id)
            ->whereIn('course_section_id', $courseSectionIds)
            ->where('is_completed', true)
            ->distinct('course_section_id')
            ->count('course_section_id');

        if ($courseSectionIds->isEmpty() || $completedSections !== $courseSectionIds->count()) {
            return $locked->fresh();
        }

        try {
            event(new \App\Events\CourseCompleted($locked->user, $course));
        } catch (\Throwable $exception) {
            // Certificate/notification side effects must not roll back a passed project.
            report($exception);
        }

        return $locked->fresh();
    }

    public function finalizeDue(int $limit = 100): int
    {
        $count = 0;
        ProjectSubmission::query()
            ->where('review_status', ProjectSubmission::STATUS_PENDING)
            ->whereNotNull('auto_pass_at')
            ->where('auto_pass_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ProjectSubmission $submission) use (&$count): void {
                $this->finalizeIfDue($submission);
                $count++;
            });

        return $count;
    }

    private function detectEffort(?string $text, ?UploadedFile $file): string
    {
        $plainText = trim((string) $text);
        if ($file) {
            if ((int) $file->getSize() < (int) config('projects.minimum_file_bytes', 512)) {
                return ProjectSubmission::EFFORT_INVALID;
            }

            if (!str_starts_with((string) $file->getMimeType(), 'image/')) {
                return ProjectSubmission::EFFORT_VALID;
            }

            return $this->isBlankImage($file)
                ? ProjectSubmission::EFFORT_INVALID
                : ProjectSubmission::EFFORT_VALID;
        }

        return mb_strlen($plainText) >= (int) config('projects.minimum_text_length', 10)
            ? ProjectSubmission::EFFORT_VALID
            : ProjectSubmission::EFFORT_INVALID;
    }

    private function requestFingerprint(?string $text, ?UploadedFile $file): string
    {
        $fileHash = null;
        if ($file) {
            $fileHash = hash_file('sha256', $file->getRealPath());
            if ($fileHash === false) {
                throw new \RuntimeException('Unable to fingerprint the project attachment.');
            }
        }

        return hash('sha256', json_encode([
            'text' => trim((string) $text),
            'file_sha256' => $fileHash,
            'file_size' => $file?->getSize(),
            'mime_type' => $file?->getMimeType(),
        ], JSON_THROW_ON_ERROR));
    }

    private function assertIdempotentReplay(
        ProjectSubmission $submission,
        string $fingerprint,
        ?string $text,
        ?UploadedFile $file
    ): void {
        $storedFingerprint = (string) data_get(
            $submission->submission_metadata,
            'request_fingerprint',
            ''
        );
        $legacyMatches = trim((string) $submission->submission_text) === trim((string) $text)
            && ($submission->submission_file !== null) === ($file !== null);

        if (
            ($storedFingerprint !== '' && !hash_equals($storedFingerprint, $fingerprint))
            || ($storedFingerprint === '' && !$legacyMatches)
        ) {
            throw new \UnexpectedValueException(
                'Project submission idempotency key was reused for different content.'
            );
        }
    }

    private function isBlankImage(UploadedFile $file): bool
    {
        // Missing image tooling is treated forgivingly, never as a student failure.
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        // Never decode an unexpectedly large image into PHP memory. Upload
        // validation limits the file bytes, while this guard also blocks tiny
        // compressed images with pathological dimensions (decompression bombs).
        $inspectionBytes = max(1, (int) config('projects.image_inspection_max_bytes', 8388608));
        if ((int) $file->getSize() > $inspectionBytes) {
            return false;
        }

        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false) {
            return true;
        }
        $width = max(0, (int) ($dimensions[0] ?? 0));
        $height = max(0, (int) ($dimensions[1] ?? 0));
        $maximumPixels = max(1, (int) config('projects.image_inspection_max_pixels', 12000000));
        if ($width < 2 || $height < 2) {
            return true;
        }
        if ($width > intdiv($maximumPixels, $height)) {
            return false;
        }

        $contents = @file_get_contents($file->getRealPath());
        $image = $contents !== false ? @imagecreatefromstring($contents) : false;
        if ($image === false) {
            return true;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 2 || $height < 2) {
            imagedestroy($image);
            return true;
        }

        $samples = 0;
        $dark = 0;
        $white = 0;
        $minimumLuminance = 255;
        $maximumLuminance = 0;
        $stepX = max(1, (int) floor($width / 20));
        $stepY = max(1, (int) floor($height / 20));
        $threshold = (int) config('projects.dark_image_threshold', 12);
        $whiteThreshold = (int) config('projects.white_image_threshold', 248);

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgb = imagecolorat($image, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $luminance = (int) round(($red + $green + $blue) / 3);
                $samples++;
                $minimumLuminance = min($minimumLuminance, $luminance);
                $maximumLuminance = max($maximumLuminance, $luminance);
                if (max($red, $green, $blue) <= $threshold) {
                    $dark++;
                }
                if (min($red, $green, $blue) >= $whiteThreshold) {
                    $white++;
                }
            }
        }

        imagedestroy($image);

        if ($samples === 0) {
            return true;
        }

        return ($dark / $samples) >= (float) config('projects.dark_image_ratio', 0.97)
            || ($white / $samples) >= (float) config('projects.white_image_ratio', 0.985)
            || ($maximumLuminance - $minimumLuminance) <= (int) config('projects.solid_image_luminance_range', 3);
    }
}
