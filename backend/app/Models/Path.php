<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Path extends Model
{
    use HasFactory;

    protected $fillable = [
        'title_ar',
        'title_en',
    ];

    public function getTitleAttribute() {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('title_ar', $this->attributes) && !array_key_exists('title_en', $this->attributes)) {
            return null;
        }

        $lang = request()->header('Accept-Language', 'ar');
        return str_starts_with($lang, 'en')
            ? ($this->attributes['title_en'] ?? $this->attributes['title_ar'] ?? null)
            : ($this->attributes['title_ar'] ?? $this->attributes['title_en'] ?? null);
    }

    /**
     * Get the courses for this path.
     */
    public function courses()
    {
        return $this->hasMany(Course::class);
    }

    /**
     * Get the interests (classifications) for this path.
     */
    public function interests()
    {
        return $this->belongsToMany(Classification::class, 'classification_path');
    }
}
