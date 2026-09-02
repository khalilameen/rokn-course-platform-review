<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CourseCompleted;
use App\Jobs\SendAiUsageThresholdAlert;
use App\Jobs\SendFinancialAnomalyAlert;
use App\Listeners\AwardCourseCompletionReward;
use App\Listeners\AwardLevelBadge;
use App\Listeners\GenerateCourseCertificate;
use App\Models\InternalSignal;
use App\Models\Project;
use App\Models\User;

final readonly class InternalSignalHandler
{
    public function __construct(
        private AwardLevelBadge $badges,
        private GenerateCourseCertificate $certificates,
        private AwardCourseCompletionReward $rewards,
        private LearningRewardService $learningRewards,
        private AiPlatformUsageMonitor $aiUsage,
        private InternalSignalService $internalSignals
    ) {
    }

    public function handle(InternalSignal $signal): void
    {
        $payload = is_array($signal->payload) ? $signal->payload : [];
        switch ($signal->type) {
            case 'course.completed':
                $this->courseCompleted($payload);
                return;
            case 'course.completed.badge':
                $this->badges->handle($this->courseEvent($payload));
                return;
            case 'course.completed.certificate':
                $this->certificates->handle($this->courseEvent($payload));
                return;
            case 'course.completed.reward':
                $this->rewards->handle($this->courseEvent($payload));
                return;
            case 'financial_anomaly.opened':
                $this->fanOutFinancialAlert($payload);
                return;
            case 'project.passed.first_reward':
                $this->projectPassedReward($payload);
                return;
            case 'financial_anomaly.alert_admin':
                (new SendFinancialAnomalyAlert(
                    (int) ($payload['anomaly_id'] ?? 0),
                    (int) ($payload['admin_id'] ?? 0),
                    (string) ($payload['occurrence'] ?? '')
                ))->handle();
                return;
            case 'ai_usage.settled':
                $this->aiUsage->record((int) ($payload['event_id'] ?? 0));
                return;
            case 'ai_usage.threshold':
                $this->fanOutAiAlert($payload);
                return;
            case 'ai_usage.threshold_admin':
                (new SendAiUsageThresholdAlert(
                    (string) ($payload['metric'] ?? ''),
                    (string) ($payload['period'] ?? ''),
                    max(0, (int) ($payload['actual'] ?? 0)),
                    max(0, (int) ($payload['threshold'] ?? 0)),
                    (int) ($payload['admin_id'] ?? 0)
                ))->handle();
                return;
            default:
                throw new \UnexpectedValueException(
                    'Unknown internal signal type: ' . $signal->type
                );
        }
    }

    private function courseCompleted(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $courseId = (int) ($payload['course_id'] ?? 0);
        foreach (['badge', 'certificate', 'reward'] as $effect) {
            $this->internalSignals->record(
                'course.completed.' . $effect,
                "user:{$userId}:course:{$courseId}:effect:{$effect}",
                ['user_id' => $userId, 'course_id' => $courseId],
                'course_enrollment',
                "{$userId}:{$courseId}"
            );
        }
    }

    private function courseEvent(array $payload): CourseCompleted
    {
        return new CourseCompleted(
            (int) ($payload['user_id'] ?? 0),
            (int) ($payload['course_id'] ?? 0)
        );
    }

    private function fanOutFinancialAlert(array $payload): void
    {
        $anomalyId = (int) ($payload['anomaly_id'] ?? 0);
        $occurrence = (string) ($payload['occurrence'] ?? 'initial');
        foreach ($this->adminIds() as $adminId) {
            $this->internalSignals->record(
                'financial_anomaly.alert_admin',
                "anomaly:{$anomalyId}:occurrence:{$occurrence}:admin:{$adminId}",
                [
                    'anomaly_id' => $anomalyId,
                    'admin_id' => $adminId,
                    'occurrence' => $occurrence,
                ],
                'financial_anomaly',
                $anomalyId
            );
        }
    }

    private function projectPassedReward(array $payload): void
    {
        $user = User::query()->find((int) ($payload['user_id'] ?? 0));
        $project = Project::query()->find((int) ($payload['project_id'] ?? 0));
        if (!$user || !$project) {
            return;
        }

        $this->learningRewards->awardFirstProject($user, $project);
    }

    private function fanOutAiAlert(array $payload): void
    {
        foreach ($this->adminIds() as $adminId) {
            $identity = hash('sha256', json_encode([
                $payload['metric'] ?? '',
                $payload['period'] ?? '',
                $payload['actual'] ?? 0,
                $payload['threshold'] ?? 0,
                $adminId,
            ], JSON_UNESCAPED_SLASHES) ?: '');
            $this->internalSignals->record(
                'ai_usage.threshold_admin',
                $identity,
                array_merge($payload, ['admin_id' => $adminId]),
                'ai_usage_period',
                (string) ($payload['metric'] ?? '') . ':' . (string) ($payload['period'] ?? '')
            );
        }
    }

    /** @return list<int> */
    private function adminIds(): array
    {
        return User::query()
            ->where('role', 'admin')
            ->where('active', true)
            ->whereNotNull('email')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
