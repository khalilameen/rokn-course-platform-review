<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Certificate extends Model
{
    protected $fillable = [
        'user_id',
        'public_id',
        'course_id',
        'project_id',
        'image_path',
        'generated_at',
        'status',
        'verification_level',
        'revoked_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    public function getCertificateUrlAttribute(): string
    {
        if ($this->image_path === 'pending' || ($this->status ?? 'active') !== 'active') {
            return '';
        }

        try {
            return Storage::disk((string) config('certificate.disk', 'public'))
                ->url((string) $this->image_path);
        } catch (\Throwable $exception) {
            report($exception);
            return '';
        }
    }

    public function getPortfolioUrlAttribute(): string
    {
        $user = $this->relationLoaded('user') ? $this->user : $this->user()->first();
        $slug = $user?->portfolio_slug ?: ('student-' . $this->user_id);

        $parameters = ['slug' => $slug];
        if ($this->public_id) {
            $parameters['certificate'] = $this->public_id;
        }

        return route('portfolio.public', $parameters);
    }
}
