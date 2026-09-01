<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ArabicSearchNormalizer;
use App\Services\SavedFolderConsistencyService;
use App\Support\UnicodeText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class FinalizeReleaseBackfills extends Command
{
    protected $signature = 'rokn:release-finalize
        {--old-workers-drained : Confirm that the previous release can no longer write}
        {--batch=500 : Rows processed per short transaction}';

    protected $description = 'Close mixed-release backfill gaps after old web and queue processes drain';

    public function handle(
        SavedFolderConsistencyService $savedFolders,
        ArabicSearchNormalizer $searchNormalizer
    ): int
    {
        if (!(bool) $this->option('old-workers-drained')) {
            $this->error('Drain the previous web release and queue workers before finalizing backfills.');

            return self::INVALID;
        }

        $batch = filter_var(
            $this->option('batch'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 50, 'max_range' => 2000]]
        );
        if ($batch === false) {
            $this->error('The batch size must be an integer from 50 to 2000.');

            return self::INVALID;
        }

        $lock = Cache::lock('release:finalize-backfills:v1', 900);
        if (!$lock->get()) {
            $this->error('Another release finalizer is already running.');

            return self::FAILURE;
        }

        try {
            if ($this->hasPendingMigrations()) {
                $this->error('Run every forward migration before finalizing overlap backfills.');

                return self::FAILURE;
            }

            $updated = $this->finalizeExamSnapshots((int) $batch);
            $repairedFolders = $savedFolders->repairLegacyFolders((int) $batch);
            $reindexedCourses = $this->finalizeCourseSearch($searchNormalizer, (int) $batch);
            $remaining = $this->repairableExamSnapshotCount();
            $remainingFolders = $savedFolders->repairableCount();
            $remainingCourses = $this->staleCourseSearchCount($searchNormalizer, (int) $batch);
            if ($remaining > 0 || $remainingFolders > 0 || $remainingCourses > 0) {
                $this->error(
                    "{$remaining} repairable exam snapshot(s) and "
                    ."{$remainingFolders} saved folder(s) and "
                    ."{$remainingCourses} stale course search row(s) remain."
                );

                return self::FAILURE;
            }

            Cache::forget('health:launch-schema:v3');

            $this->info(
                "Release backfills finalized; {$updated} exam snapshot(s) and "
                ."{$repairedFolders} saved folder(s) repaired; "
                ."{$reindexedCourses} course search row(s) refreshed."
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Release finalization stopped safely: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function hasPendingMigrations(): bool
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $ran = array_fill_keys($migrator->getRepository()->getRan(), true);

        return collect(array_keys($files))->contains(
            static fn (string $migration): bool => !isset($ran[$migration])
        );
    }

    private function finalizeExamSnapshots(int $batch): int
    {
        if (!$this->examSnapshotSchemaExists()) {
            return 0;
        }

        $updated = 0;
        DB::table('exam_attempts as attempt')
            ->join('lists as quiz', 'quiz.id', '=', 'attempt.quiz_id')
            ->whereNull('attempt.quiz_title')
            ->select([
                'attempt.id',
                'quiz.title',
                'quiz.title_ar',
                'quiz.title_en',
                'quiz.description',
                'quiz.description_ar',
                'quiz.description_en',
                'quiz.image',
            ])
            ->orderBy('attempt.id')
            ->chunkById($batch, function ($attempts) use (&$updated): void {
                DB::transaction(function () use ($attempts, &$updated): void {
                    foreach ($attempts as $attempt) {
                        $updated += DB::table('exam_attempts')
                            ->where('id', $attempt->id)
                            ->whereNull('quiz_title')
                            ->update([
                                'quiz_title' => $this->firstText([
                                    $attempt->title_ar,
                                    $attempt->title_en,
                                    $attempt->title,
                                ], 'اختبار'),
                                'quiz_description' => $this->firstText([
                                    $attempt->description_ar,
                                    $attempt->description_en,
                                    $attempt->description,
                                ]),
                                'quiz_image' => $this->firstText([$attempt->image]),
                            ]);
                    }
                }, 3);
            }, 'attempt.id', 'id');

        return $updated;
    }

    private function repairableExamSnapshotCount(): int
    {
        if (!$this->examSnapshotSchemaExists()) {
            return 0;
        }

        // An attempt whose quiz was deleted remains valid historical evidence;
        // finalization neither deletes it nor invents mutable source data.
        return DB::table('exam_attempts as attempt')
            ->join('lists as quiz', 'quiz.id', '=', 'attempt.quiz_id')
            ->whereNull('attempt.quiz_title')
            ->count();
    }

    private function examSnapshotSchemaExists(): bool
    {
        return Schema::hasTable('exam_attempts')
            && Schema::hasTable('lists')
            && Schema::hasColumns('exam_attempts', [
                'quiz_title',
                'quiz_description',
                'quiz_image',
            ]);
    }

    private function finalizeCourseSearch(ArabicSearchNormalizer $normalizer, int $batch): int
    {
        if (!$this->courseSearchSchemaExists()) {
            return 0;
        }

        $updated = 0;
        $this->courseSearchQuery()
            ->chunkById($batch, function ($courses) use ($normalizer, &$updated): void {
                DB::transaction(function () use ($courses, $normalizer, &$updated): void {
                    foreach ($courses as $course) {
                        [$title, $terms] = $this->normalizedCourseSearch($course, $normalizer);
                        if (
                            hash_equals((string) $course->search_title_normalized, $title)
                            && hash_equals((string) $course->search_terms_normalized, $terms)
                        ) {
                            continue;
                        }

                        $updated += DB::table('courses')
                            ->where('id', $course->id)
                            ->update([
                                'search_title_normalized' => $title,
                                'search_terms_normalized' => $terms,
                            ]);
                    }
                }, 3);
            });

        return $updated;
    }

    private function staleCourseSearchCount(ArabicSearchNormalizer $normalizer, int $batch): int
    {
        if (!$this->courseSearchSchemaExists()) {
            return 0;
        }

        $stale = 0;
        $this->courseSearchQuery()
            ->chunkById($batch, function ($courses) use ($normalizer, &$stale): void {
                foreach ($courses as $course) {
                    [$title, $terms] = $this->normalizedCourseSearch($course, $normalizer);
                    if (
                        !hash_equals((string) $course->search_title_normalized, $title)
                        || !hash_equals((string) $course->search_terms_normalized, $terms)
                    ) {
                        $stale++;
                    }
                }
            });

        return $stale;
    }

    private function courseSearchQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('courses')
            ->select([
                'id', 'name_ar', 'name_en', 'description_ar', 'description_en',
                'search_keywords_ar', 'search_keywords_en',
                'search_title_normalized', 'search_terms_normalized',
            ])
            ->orderBy('id');
    }

    /** @return array{0: string, 1: string} */
    private function normalizedCourseSearch(object $course, ArabicSearchNormalizer $normalizer): array
    {
        $title = implode(' ', array_filter([
            $course->name_ar,
            $course->name_en,
        ], static fn ($value): bool => trim((string) $value) !== ''));
        $terms = implode(' ', array_filter([
            $course->name_ar,
            $course->name_en,
            $course->description_ar,
            $course->description_en,
            $course->search_keywords_ar,
            $course->search_keywords_en,
        ], static fn ($value): bool => trim((string) $value) !== ''));

        return [$normalizer->normalize($title), $normalizer->normalize($terms)];
    }

    private function courseSearchSchemaExists(): bool
    {
        return Schema::hasTable('courses')
            && Schema::hasColumns('courses', [
                'name_ar', 'name_en', 'description_ar', 'description_en',
                'search_keywords_ar', 'search_keywords_en',
                'search_title_normalized', 'search_terms_normalized',
            ]);
    }

    /** @param list<mixed> $values */
    private function firstText(array $values, ?string $fallback = null): ?string
    {
        foreach ($values as $value) {
            $text = UnicodeText::clean($value, false);
            if ($text !== '') {
                return $text;
            }
        }

        return $fallback;
    }
}
