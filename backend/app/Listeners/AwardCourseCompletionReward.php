<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CourseCompleted;
use App\Services\LearningRewardService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class AwardCourseCompletionReward implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;
    public int $tries = 3;

    public function __construct(
        private readonly LearningRewardService $rewards
    ) {
    }

    public function handle(CourseCompleted $event): void
    {
        $this->rewards->awardCourseCompletion(
            $event->user,
            $event->course
        );
    }
}
