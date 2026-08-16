<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    /**
     * @var array
     */
    protected $fillable = ['path', 'type'];

    protected static function boot()
    {
        parent::boot();
        static::deleting(function ($photo) {
            // make sure no other photo uses the same file name.
            if (static::where('path', $photo->path)->count() === 1) {
                Storage::disk('public')->delete($photo->path);
            }
        });
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
}
