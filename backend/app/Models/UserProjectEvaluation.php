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

    /**
     * Get the submission file URL.
     */
    public function getSubmissionFileUrlAttribute()
    {
        return $this->submission_file ? asset('storage/' . $this->submission_file) : null;
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
