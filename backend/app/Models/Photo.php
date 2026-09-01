<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\StoredFileDeletionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Photo extends Model
{
    /**
     * @var array
     */
    protected $fillable = ['path', 'type'];

    protected static function boot()
    {
        parent::boot();
        static::deleted(function (Photo $photo): void {
            $path = (string) $photo->path;
            $delete = static function () use ($path): void {
                // The database pointer is gone before the object is retired.
                if (!Photo::query()->where('path', $path)->exists()) {
                    app(StoredFileDeletionService::class)->deleteOrQueue('public', $path);
                }
            };
            DB::transactionLevel() > 0 ? DB::afterCommit($delete) : $delete();
        });
        static::saved(fn (Photo $photo) => $photo->invalidateCourseCatalogue());
        static::deleted(fn (Photo $photo) => $photo->invalidateCourseCatalogue());
    }

    /**
     * @param $query
     * @return mixed
     */
    public function scopeFeatured($query)
    {
        return $query->where('type', 'featured');
    }

    /**
     * Gets the owning models.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function photoable()
    {
        return $this->morphTo();
    }

    /**
     * @return string
     */
    public function assetPath()
    {
        return asset('storage/' . $this->path);
    }

    private function invalidateCourseCatalogue(): void
    {
        $owner = $this->photoable;
        $affectsCatalogue = $owner instanceof Course
            || ($owner instanceof User
                && in_array(strtolower((string) $owner->role), ['teacher', 'admin'], true)
                && $owner->teachingCourses()->exists());
        if (!$affectsCatalogue) {
            return;
        }
        $increment = static function (): void {
            try {
                Cache::add('courses:catalog-revision', 1, now()->addYears(10));
                Cache::increment('courses:catalog-revision');
            } catch (\Throwable) {
                // Image persistence must not depend on the cache service.
            }
        };
        DB::transactionLevel() > 0 ? DB::afterCommit($increment) : $increment();
    }
}
