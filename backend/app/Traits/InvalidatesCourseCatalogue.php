<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

trait InvalidatesCourseCatalogue
{
    public static function bootInvalidatesCourseCatalogue(): void
    {
        $touchCatalogue = static function ($model): void {
            if (
                method_exists($model, 'shouldInvalidateCourseCatalogue')
                && !$model->shouldInvalidateCourseCatalogue()
            ) {
                return;
            }

            $incrementRevision = static function (): void {
                try {
                    $key = 'courses:catalog-revision';
                    // add() is atomic on the production cache stores. Incrementing
                    // afterwards means concurrent edits cannot overwrite each
                    // other's revision and strand a stale catalogue page.
                    Cache::add($key, 1, now()->addYears(10));
                    Cache::increment($key);
                } catch (\Throwable) {
                    // Cache invalidation improves freshness but must never make
                    // an editor's database commit depend on Redis availability.
                }
            };

            // Publishing and authoring mutate several related models inside a
            // transaction. Exposing the new revision before commit lets a
            // concurrent request cache the old database snapshot under that
            // new revision for five minutes. Publish the revision only after
            // the database state is visible to every connection.
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($incrementRevision);
                return;
            }

            $incrementRevision();
        };

        static::saved($touchCatalogue);
        static::deleted($touchCatalogue);

        if (in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive(static::class), true)) {
            static::restored($touchCatalogue);
        }
    }
}
