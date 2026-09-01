<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AiEntitlementUsage extends Model
{
    public const FEATURE_COURSE_CHAT = 'course_chat';
    public const FEATURE_PROJECT_FEEDBACK = 'project_feedback';
    public const FEATURE_PROJECT_FOLLOWUP = 'project_followup';
    public const FEATURES = [
        self::FEATURE_COURSE_CHAT,
        self::FEATURE_PROJECT_FEEDBACK,
        self::FEATURE_PROJECT_FOLLOWUP,
    ];

    protected $fillable = [
        'enrollment_id',
        'access_plan_id',
        'feature',
        'used_requests',
        'reserved_requests',
        'used_tokens',
        'reserved_tokens',
        'used_cost_usd',
        'reserved_cost_usd',
    ];

    protected $casts = [
        'used_requests' => 'integer', 'reserved_requests' => 'integer',
        'used_tokens' => 'integer', 'reserved_tokens' => 'integer',
        'used_cost_usd' => 'decimal:6', 'reserved_cost_usd' => 'decimal:6',
    ];
    public function enrollment() { return $this->belongsTo(CourseEnrollment::class); }
    public function plan() { return $this->belongsTo(CourseAccessPlan::class, 'access_plan_id'); }
}
