<?php

namespace App\Models;

use App\Support\RoknPublicUrl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Certificate extends Model
{
    protected $fillable = [
        'user_id',
        'public_id',
        'course_id',
        'project_id',
        'holder_name',
        'course_name',
        'image_path',
        'generation_lease_id',
        'generated_at',
        'status',
        'verification_level',
        'revoked_at',
        'recovery_attempts',
        'recovery_next_attempt_at',
        'recovery_failed_at',
        'recovery_failure_code',
        'artifact_checked_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'revoked_at' => 'datetime',
        'recovery_attempts' => 'integer',
        'recovery_next_attempt_at' => 'datetime',
        'recovery_failed_at' => 'datetime',
        'artifact_checked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Certificate $certificate): void {
            if (!$certificate->public_id) {
                $certificate->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (Certificate $certificate): void {
            foreach (['public_id', 'holder_name', 'course_name', 'generated_at'] as $attribute) {
                $original = $certificate->getRawOriginal($attribute);
                if (
                    $certificate->isDirty($attribute)
                    && $original !== null
                    && trim((string) $original) !== ''
                ) {
                    $certificate->setAttribute($attribute, $original);
                }
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class)->withTrashed();
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
        if (!$this->hasStoredArtifact()) {
            return '';
        }

        return $this->public_id
            ? RoknPublicUrl::certificateArtifact((string) $this->public_id)
            : '';
    }

    public function hasStoredArtifact(): bool
    {
        $path = trim((string) $this->image_path);
        if ($path === '' || $path === 'pending' || ($this->status ?? 'active') !== 'active') {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Storage::disk((string) config('certificate.disk', 'public'))->exists($path);
        } catch (\Throwable $exception) {
            report($exception);
            return false;
        }
    }

    public function getPortfolioUrlAttribute(): string
    {
        // A certificate without its immutable random public identifier is not
        // shareable yet. Never fall back to a user id or another enumerable
        // identifier: old/corrupt rows are repaired by certificate recovery.
        return $this->public_id
            ? RoknPublicUrl::certificate((string) $this->public_id)
            : '';
    }
}
