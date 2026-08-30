<?php

namespace App\Models;

use App\Traits\ResolvesLocalizedAttributes;
use App\Traits\InvalidatesCourseCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseSection extends Model
{
    use HasFactory, SoftDeletes, ResolvesLocalizedAttributes, InvalidatesCourseCatalogue;

    protected $fillable = [
        'title',
        'title_ar',
        'title_en',
        'course_id',
        'module_id',
        'section_type',
        'sectionable_type',
        'sectionable_id',
        'order'
    ];

    /**
     * Get the title attribute based on Accept-Language header.
     */
    public function getTitleAttribute()
    {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('title_ar', $this->attributes) && !array_key_exists('title_en', $this->attributes)) {
            return $this->attributes['title'] ?? null;
        }

        return $this->localizedValue('title_ar', 'title_en', 'title');
    }

    /**
     * Get the sectionable content (lesson, quiz, etc.)
     */
    public function sectionable()
    {
        return $this->morphTo();
    }

    /**
     * Get the course that owns this section.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the module that owns this section (if any).
     */
    public function module()
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    /**
     * Get the project for this section (if section_type is project).
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'sectionable_id');
    }

    /**
     * Get the attachments for this section.
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('order');
    }

    /**
     * Check if this is a project section.
     */
    public function isProject(): bool
    {
        return $this->getSectionType() === 'project';
    }

    /**
     * Check if this is a lesson section.
     */
    public function isLesson(): bool
    {
        return $this->getSectionType() === 'lesson';
    }

    /**
     * Get the section type based on sectionable_type or section_type field
     */
    public function getSectionType()
    {
        // If section_type is set to project, return project
        if ($this->section_type === 'project') {
            return 'project';
        }

        // Otherwise determine from sectionable_type
        $types = [
            'App\Models\Lesson' => 'lesson',
            'App\Models\Question' => 'question',
            'App\Models\Link' => 'link',
            'App\Models\ItemList' => 'quiz',
            'App\Models\Course' => 'course',
            'App\Models\CoursePdf' => 'pdf',
            'App\Models\Project' => 'project'
        ];

        return $types[$this->sectionable_type] ?? 'lesson';
    }

    /**
     * Check if a user has completed this section.
     */
    public function isCompletedByUser(int $userId): bool
    {
        return StudentSectionProgress::where('user_id', $userId)
            ->where('course_section_id', $this->id)
            ->where('is_completed', true)
            ->exists();
    }

    /**
     * Check if a user has passed the project (if this is a project section).
     */
    public function userPassedProject(int $userId): bool
    {
        if (!$this->isProject() || !$this->project) {
            return true;
        }

        return $this->project->userPassed($userId);
    }
}
