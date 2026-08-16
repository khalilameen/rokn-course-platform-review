<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonWatchEvidence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class LearningEvidenceService
{
    /** Verified progress is bounded by elapsed server time and playback rate. */
    public function recordHeartbeat(
        User $user,
        Lesson $lesson,
        int $positionSeconds,
        ?int $clientDurationSeconds
    ): array {
        $sectionId = $lesson->courseSection?->id;
        if (!$sectionId) {
            return $this->emptyResult();
        }

        return DB::transaction(function () use (
            $user,
            $lesson,
            $sectionId,
            $positionSeconds,
            $clientDurationSeconds
        ): array {
            $evidence = LessonWatchEvidence::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();

            $trustedDuration = (int) $lesson->duration_minutes > 0
                ? (int) $lesson->duration_minutes * 60
                : null;
            $observedDuration = max(
                0,
                (int) ($evidence?->duration_seconds ?? 0),
                (int) ($clientDurationSeconds ?? 0),
                (int) ($trustedDuration ?? 0)
            ) ?: null;
            $now = now();
            $credited = 0;

            if (!$evidence) {
                $evidence = new LessonWatchEvidence([
                    'user_id' => $user->id,
                    'lesson_id' => $lesson->id,
                    'course_section_id' => $sectionId,
                    'duration_seconds' => $observedDuration,
                    'verified_seconds' => 0,
                    'last_position_seconds' => max(0, $positionSeconds),
                    'last_heartbeat_at' => $now,
                ]);
            } else {
                $elapsed = $evidence->last_heartbeat_at
                    ? (int) floor($evidence->last_heartbeat_at->diffInSeconds($now, true))
                    : 0;
                $positionDelta = max(0, $positionSeconds - (int) $evidence->last_position_seconds);
                $maxGap = max(10, (int) config('learning_evidence.maximum_heartbeat_gap_seconds', 45));
                $maxRate = max(1.0, min(2.5, (float) config('learning_evidence.maximum_playback_rate', 2.0)));
                $maxCredit = max(5, (int) config('learning_evidence.maximum_credit_per_heartbeat', 30));

                if ($elapsed >= 1 && $elapsed <= $maxGap && $positionDelta > 0) {
                    $credited = min(
                        $positionDelta,
                        (int) floor($elapsed * $maxRate),
                        $maxCredit
                    );
                }

                $evidence->duration_seconds = $observedDuration;
                $evidence->verified_seconds = (int) $evidence->verified_seconds + $credited;
                $evidence->last_position_seconds = max(0, $positionSeconds);
                $evidence->last_heartbeat_at = $now;
            }

            $required = $this->requiredSeconds($lesson, $observedDuration);
            if ($required !== null && (int) $evidence->verified_seconds >= $required && !$evidence->completed_at) {
                $evidence->completed_at = $now;
            }
            $evidence->save();

            return [
                'evidence_id' => $evidence->id,
                'verified_seconds' => (int) $evidence->verified_seconds,
                'required_seconds' => $required,
                'credited_seconds' => $credited,
                'eligible_for_completion' => $required !== null
                    && (int) $evidence->verified_seconds >= $required,
            ];
        });
    }

    public function evidenceFor(User $user, Lesson $lesson): array
    {
        $evidence = LessonWatchEvidence::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->where('course_section_id', $lesson->courseSection?->id)
            ->first();
        $required = $this->requiredSeconds($lesson, $evidence?->duration_seconds);

        return [
            'evidence_id' => $evidence?->id,
            'verified_seconds' => (int) ($evidence?->verified_seconds ?? 0),
            'required_seconds' => $required,
            'credited_seconds' => 0,
            'eligible_for_completion' => $required !== null && $evidence !== null
                && (int) $evidence->verified_seconds >= $required,
        ];
    }

    public function requiredSeconds(Lesson $lesson, ?int $observedDuration = null): ?int
    {
        $minimum = max(10, (int) config('learning_evidence.minimum_verified_seconds', 20));
        $trustedDuration = (int) $lesson->duration_minutes > 0
            ? (int) $lesson->duration_minutes * 60
            : null;
        // Only server-owned duration can authorize completion.
        if ($trustedDuration === null) {
            return null;
        }
        $duration = $trustedDuration;
        $fraction = max(0.5, min(0.95, (float) config('learning_evidence.required_fraction', 0.80)));

        // Short videos use the same completion fraction without the floor.
        if ($duration > 0 && $duration < $minimum) {
            return max(1, (int) ceil($duration * $fraction));
        }

        return max($minimum, (int) ceil($duration * $fraction));
    }

    private function emptyResult(): array
    {
        return [
            'evidence_id' => null,
            'verified_seconds' => 0,
            'required_seconds' => 0,
            'credited_seconds' => 0,
            'eligible_for_completion' => false,
        ];
    }
}
