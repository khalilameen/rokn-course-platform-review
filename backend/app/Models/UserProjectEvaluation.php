<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProjectEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'score',
        'passed',
        'evaluation_data',
        'submission_text',
        'submission_file',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'score' => 'integer',
        'evaluation_data' => 'array',
    ];

    /**
     * Get the user who submitted this evaluation.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the project being evaluated.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Calculate if the evaluation passes based on score and project passing score.
     */
    public function calculatePassed(): bool
    {
        $project = $this->project;
        return $this->score >= ($project ? $project->passing_score : 50);
    }

    /**
     * Update the passed status based on score.
     */
    public function updatePassedStatus(): void
    {
        $this->passed = $this->calculatePassed();
        $this->save();
    }

    /** Legacy evaluations must not mint a public storage URL. */
    public function getSubmissionFileUrlAttribute(): ?string
    {
        // ProjectSubmission owns the authenticated download route. This model
        // stores historical evaluation metadata only; exposing its path would
        // bypass account ownership checks for old public-disk files.
        return null;
    }

    /**
     * Scope to get passed evaluations.
     */
    public function scopePassed($query)
    {
        return $query->where('passed', true);
    }

    /**
     * Scope to get evaluations for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
