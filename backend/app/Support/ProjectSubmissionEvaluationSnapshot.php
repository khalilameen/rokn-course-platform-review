<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use LogicException;

/** Immutable project and entitlement facts used by delayed review jobs. */
final class ProjectSubmissionEvaluationSnapshot
{
    public const CURRENT_VERSION = 1;

    /** @param array<string,mixed>|null $accessTerms */
    public static function capture(
        Project $project,
        ?CourseSection $section,
        ?CourseEnrollment $enrollment,
        ?array $accessTerms
    ): array {
        $snapshot = [
            'version' => self::CURRENT_VERSION,
            'captured_at' => now()->toIso8601String(),
            'course_id' => $section ? (int) $section->course_id : null,
            'section_id' => $section ? (int) $section->id : null,
            'project' => [
                'id' => (int) $project->id,
                'updated_at' => $project->updated_at?->toIso8601String(),
                'requirements_text' => (string) $project->requirements_text,
                'requirements_text_ar' => $project->getRawOriginal('requirements_text_ar'),
                'requirements_text_en' => $project->getRawOriginal('requirements_text_en'),
                'ai_prompt' => (string) $project->ai_prompt,
                'ai_model_type' => $project->ai_model_type,
                'temperature' => (float) ($project->temperature ?? .35),
                'tokens_number' => max(1, (int) ($project->tokens_number ?: 500)),
                'passing_score' => (int) ($project->passing_score ?? 50),
            ],
            'access' => [
                'enrollment_id' => $enrollment ? (int) $enrollment->id : null,
                'access_plan_id' => $enrollment?->access_plan_id
                    ? (int) $enrollment->access_plan_id
                    : null,
                'terms' => $accessTerms,
            ],
        ];
        $snapshot['fingerprint'] = self::fingerprint($snapshot);

        return $snapshot;
    }

    /** Return null only for rows created before snapshots or malformed data. */
    public static function fromSubmission(ProjectSubmission $submission): ?array
    {
        $snapshot = $submission->evaluation_snapshot;
        if (!is_array($snapshot) || (int) ($snapshot['version'] ?? 0) !== self::CURRENT_VERSION) {
            return null;
        }
        if ((int) data_get($snapshot, 'project.id') !== (int) $submission->project_id) {
            return null;
        }
        $contextIds = [
            (int) ($snapshot['course_id'] ?? 0),
            (int) ($snapshot['section_id'] ?? 0),
            (int) data_get($snapshot, 'access.enrollment_id', 0),
        ];
        $hasCompleteContext = $contextIds[0] > 0
            && $contextIds[1] > 0
            && $contextIds[2] > 0;
        $hasNoContext = $contextIds[0] === 0
            && $contextIds[1] === 0
            && $contextIds[2] === 0;
        // Service-level callers can create an administrative/legacy standalone
        // submission. Keep its immutable project policy readable, but reject a
        // partially captured entitlement so a delayed worker can never combine
        // old project terms with a different current course or enrollment.
        if (!$hasCompleteContext && !$hasNoContext) {
            return null;
        }
        foreach (['requirements_text', 'ai_prompt', 'temperature', 'tokens_number', 'passing_score'] as $key) {
            if (!array_key_exists($key, (array) ($snapshot['project'] ?? []))) {
                return null;
            }
        }
        $planId = data_get($snapshot, 'access.access_plan_id');
        $terms = data_get($snapshot, 'access.terms');
        if ($planId !== null) {
            try {
                CourseAccessPlanSnapshot::assertValidForPlan(
                    (int) $planId,
                    is_array($terms) ? $terms : null
                );
            } catch (LogicException) {
                return null;
            }
        }
        $fingerprint = trim((string) ($snapshot['fingerprint'] ?? ''));
        if ($fingerprint === '' || !hash_equals($fingerprint, self::fingerprint($snapshot))) {
            return null;
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private static function fingerprint(array $snapshot): string
    {
        unset($snapshot['fingerprint']);

        return hash('sha256', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function __construct()
    {
    }
}
