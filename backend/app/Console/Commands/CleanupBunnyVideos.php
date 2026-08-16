<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Services\BunnyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CleanupBunnyVideos extends Command
{
    protected $signature = 'bunny:cleanup-videos {--limit=50 : Maximum candidates claimed in one run}';

    protected $description = 'Safely retire unreferenced Bunny Stream videos with retry and backoff';

    public function handle(BunnyService $bunny): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $ids = BunnyVideoCleanupCandidate::query()
            ->whereNull('remote_deleted_at')
            ->where('requires_review', false)
            ->whereNotNull('reviewed_at')
            ->whereNotNull('reviewed_by')
            ->where('eligible_after', '<=', now())
            ->orderBy('eligible_after')
            ->limit($limit)
            ->pluck('id');

        $failed = 0;
        foreach ($ids as $id) {
            $candidate = DB::transaction(function () use ($id): ?BunnyVideoCleanupCandidate {
                $locked = BunnyVideoCleanupCandidate::query()->lockForUpdate()->find($id);
                if (!$locked || $locked->remote_deleted_at || $locked->eligible_after->isFuture()) {
                    return null;
                }

                // A short lease prevents a second manually started worker from
                // claiming the same remote object while this request is in flight.
                $locked->forceFill([
                    'attempts' => (int) $locked->attempts + 1,
                    'last_attempt_at' => now(),
                    'eligible_after' => now()->addMinutes(15),
                ])->save();

                return $locked->fresh();
            });

            if (!$candidate) {
                continue;
            }

            if ($this->hasActiveCourseReference($candidate->video_guid)) {
                $candidate->forceFill([
                    // A changed reference graph invalidates the prior human
                    // approval. An administrator must review it again.
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'requires_review' => true,
                    'eligible_after' => now()->addDay(),
                    'last_error' => 'Cleanup blocked: video is still referenced by an active course section.',
                ])->save();
                $failed++;
                continue;
            }

            try {
                if (!$bunny->deleteVideo($candidate->video_guid)) {
                    throw new \RuntimeException('Bunny Stream did not confirm deletion.');
                }

                $candidate->forceFill([
                    'remote_deleted_at' => now(),
                    'last_error' => null,
                ])->save();
                $this->line("Deleted Bunny video {$candidate->video_guid}");
            } catch (Throwable $exception) {
                $delayMinutes = min(1440, 5 * (2 ** min(8, max(0, (int) $candidate->attempts - 1))));
                $candidate->forceFill([
                    'eligible_after' => now()->addMinutes($delayMinutes),
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ])->save();
                $this->warn("Bunny cleanup deferred for {$candidate->video_guid}");
                $failed++;
            }
        }

        $this->info(sprintf('Processed %d Bunny cleanup candidate(s); %d deferred.', $ids->count(), $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function hasActiveCourseReference(string $videoGuid): bool
    {
        return CourseSection::query()
            ->join('lessons', function ($join): void {
                $join->on('lessons.id', '=', 'course_sections.sectionable_id')
                    ->where('course_sections.sectionable_type', '=', Lesson::class);
            })
            ->where('lessons.bunny_video_id', $videoGuid)
            ->exists();
    }
}
