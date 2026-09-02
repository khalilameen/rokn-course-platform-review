<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ProjectSubmissionReviewDecision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'decision_id',
        'submission_id',
        'sequence',
        'status',
        'score',
        'feedback',
        'source',
        'reviewer_id',
        'reviewer_role',
        'decided_at',
        'decision_metadata',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'score' => 'integer',
        'decided_at' => 'datetime',
        'decision_metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Project review decisions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Project review decisions are immutable.');
        });
    }

    public function submission()
    {
        return $this->belongsTo(ProjectSubmission::class, 'submission_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id')->withTrashed();
    }
}
