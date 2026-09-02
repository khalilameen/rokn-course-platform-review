<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectSubmission extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PASSED = 'passed';
    public const STATUS_NEEDS_RESUBMISSION = 'needs_resubmission';

    public const EFFORT_VALID = 'valid';
    public const EFFORT_INVALID = 'invalid';
    public const EFFORT_UNKNOWN = 'unknown';

    protected $fillable = [
        'public_id',
        'user_id',
        'project_id',
        'idempotency_key',
        'submission_text',
        'submission_file',
        'original_file_name',
        'mime_type',
        'file_size',
        'submission_metadata',
        'evaluation_snapshot',
        'effort_status',
        'review_status',
        'review_source',
        'score',
        'feedback',
        'submitted_at',
        'auto_pass_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'submission_metadata' => 'array',
        'evaluation_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'auto_pass_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'file_size' => 'integer',
        'score' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function feedbackThread()
    {
        return $this->hasOne(ProjectFeedbackThread::class, 'submission_id');
    }

    public function reviewDecisions()
    {
        return $this->hasMany(ProjectSubmissionReviewDecision::class, 'submission_id')
            ->orderBy('sequence');
    }

    public function latestReviewDecision()
    {
        return $this->hasOne(ProjectSubmissionReviewDecision::class, 'submission_id')
            ->latestOfMany('sequence');
    }

    public function aiInputAttachments()
    {
        return $this->hasMany(AiInputAttachment::class, 'owner_id')
            ->where('owner_type', AiInputAttachment::OWNER_PROJECT_SUBMISSION);
    }

    public function getSubmissionFileUrlAttribute(): ?string
    {
        return $this->submission_file
            ? route('api.project-submissions.file', ['submission' => $this->public_id])
            : null;
    }

    /**
     * The disk is immutable upload metadata, not a process-wide assumption.
     * Rows created before this contract used the private local disk.
     */
    public function getSubmissionDiskAttribute(): string
    {
        $disk = (string) data_get($this->submission_metadata, 'storage_disk', 'local');

        return $disk !== '' && is_array(config("filesystems.disks.{$disk}"))
            ? $disk
            : 'local';
    }

    /** @return list<string> */
    public function submissionDiskCandidates(): array
    {
        return array_values(array_unique([
            $this->submission_disk,
            // Compatibility for files written before private storage shipped.
            'local',
            'public',
        ]));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
