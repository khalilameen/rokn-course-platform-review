<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WatchingLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class LatestWatchResumeService
{
    /**
     * Return one newest resume row per course in the database, rather than
     * loading a learner's entire watch history and de-duplicating it in PHP.
     *
     * @param iterable<int, int|string> $courseIds
     * @param list<string> $relations
     * @return Collection<int, WatchingLog> keyed by course id
     */
    public function forUser(int $userId, iterable $courseIds, array $relations = []): Collection
    {
        $ids = collect($courseIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $ranked = DB::table('watching_logs')
            ->where('user_id', $userId)
            ->whereIn('course_id', $ids)
            ->select(['id', 'course_id'])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY course_id '
                . 'ORDER BY COALESCE(watched_at, updated_at) DESC, id DESC) as resume_rank'
            );

        return WatchingLog::query()
            ->joinSub($ranked, 'latest_resume', function ($join): void {
                $join->on('latest_resume.id', '=', 'watching_logs.id')
                    ->where('latest_resume.resume_rank', 1);
            })
            ->select('watching_logs.*')
            ->when($relations !== [], fn ($query) => $query->with($relations))
            ->get()
            ->keyBy(static fn (WatchingLog $log): int => (int) $log->course_id);
    }
}
