<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\MediaHealthService;
use App\Services\MediaReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Promote one newly attached upload only after Bunny finishes processing it. */
final class ProbeLessonMedia implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;
    public int $timeout = 45;
    public int $uniqueFor = 3600;
    public bool $failOnTimeout = true;

    public function __construct(public int $lessonId)
    {
        $this->onQueue((string) config('queue.channels.media', 'media'));
    }

    public function uniqueId(): string
    {
        return 'lesson-media-probe:' . $this->lessonId;
    }

    public function handle(
        MediaHealthService $health,
        MediaReconciliationService $reconciliation
    ): void {
        $lesson = Lesson::query()->with('course')->find($this->lessonId);
        if (!$lesson || !$lesson->usesBunnyVideo() || !$lesson->course) {
            return;
        }

        $state = $health->probe($lesson);
        if ($state->status === 'ready') {
            // Verify the signed HLS document, poster, duration, renditions and
            // private attachments without rescanning every video in the course.
            $reconciliation->reconcileLesson($lesson, true, true);
            return;
        }
        if ($state->status === 'failed' || $this->attempts() >= $this->tries) {
            return;
        }

        $delays = [15, 30, 60, 120, 180, 300, 300, 300, 300, 300, 300];
        $this->release($delays[min(count($delays) - 1, max(0, $this->attempts() - 1))]);
    }
}
