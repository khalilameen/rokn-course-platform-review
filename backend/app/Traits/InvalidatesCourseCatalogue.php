<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

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

            $key = 'courses:catalog-revision';
            Cache::forever($key, max(1, (int) Cache::get($key, 1)) + 1);
        };

        static::saved($touchCatalogue);
        static::deleted($touchCatalogue);

        if (in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive(static::class), true)) {
            static::restored($touchCatalogue);
        }
    }
}
