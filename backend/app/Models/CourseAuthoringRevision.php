<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CourseAuthoringRevision extends Model
{
    public const DRAFT = 'draft';
    public const ARCHIVED = 'archived';

    protected $fillable = [
        'canonical_course_id', 'revision_course_id', 'base_authoring_version',
        'published_authoring_version', 'status', 'active_slot', 'clone_key',
        'published_at', 'retain_until',
    ];

    protected $casts = [
        'base_authoring_version' => 'integer',
        'published_authoring_version' => 'integer',
        'published_at' => 'immutable_datetime',
        'retain_until' => 'immutable_datetime',
    ];

    public function canonicalCourse() { return $this->belongsTo(Course::class, 'canonical_course_id'); }
    public function revisionCourse() { return $this->belongsTo(Course::class, 'revision_course_id'); }
}
