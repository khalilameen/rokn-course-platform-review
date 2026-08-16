<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'requirements_text',
        'requirements_text_ar',
        'requirements_text_en',
        'ai_prompt',
        'ai_model_type',
        'temperature',
        'tokens_number',
        'passing_score',
        'fallback_review_delay_seconds',
        'is_graduation_project',
    ];

    /**
     * Get the requirements_text attribute based on Accept-Language header.
     */
    public function getRequirementsTextAttribute()
    {
        if (!array_key_exists('requirements_text_ar', $this->attributes) && !array_key_exists('requirements_text_en', $this->attributes)) {
            return $this->attributes['requirements_text'] ?? null;
        }

        $lang = request()->header('Accept-Language', 'ar');
        return str_starts_with($lang, 'en')
            ? ($this->attributes['requirements_text_en'] ?? $this->attributes['requirements_text_ar'] ?? null)
            : ($this->attributes['requirements_text_ar'] ?? $this->attributes['requirements_text_en'] ?? null);
    }

    /**
     * Get the description attribute (alias for requirements_text) based on Accept-Language header.
     */
    public function getDescriptionAttribute()
    {
        return $this->requirements_text;
    }

    protected $casts = [
        'is_graduation_project' => 'boolean',
        'passing_score' => 'integer',
        'temperature' => 'float',
        'tokens_number' => 'integer',
        'fallback_review_delay_seconds' => 'integer',
    ];

    /**
     * Get the section that owns this project.
     */
    public function section()
    {
        return $this->morphOne(CourseSection::class, 'sectionable');
    }

    /**
     * Get all evaluations for this project.
     */
    public function evaluations()
    {
        return $this->hasMany(UserProjectEvaluation::class);
    }

    public function submissions()
    {
        return $this->hasMany(ProjectSubmission::class);
    }

    /**
     * Get evaluation for a specific user.
     */
    public function evaluationForUser(int $userId)
    {
        return $this->evaluations()->where('user_id', $userId)->first();
    }

    /**
     * Check if a user has passed this project.
     */
    public function userPassed(int $userId): bool
    {
        $evaluation = $this->evaluationForUser($userId);
        return $evaluation && $evaluation->passed;
    }

    /**
     * Get the course through the section.
     */
    public function getCourseAttribute()
    {
        return $this->section ? $this->section->course : null;
    }

    /**
     * Get the module through the section.
     */
    public function getModuleAttribute()
    {
        return $this->section ? $this->section->module : null;
    }

    /**
     * Scope to get graduation projects.
     */
    public function scopeGraduationProjects($query)
    {
        return $query->where('is_graduation_project', true);
    }
}
