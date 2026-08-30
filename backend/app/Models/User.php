<?php

namespace App\Models;

use App\Models\UserNote;
use App\Models\Classification;
use App\Traits\HasPhoto;
use App\Traits\HasApiTokens;
use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable, HasPhoto, HasApiTokens, SoftDeletes, ResolvesLocalizedAttributes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'phone', 'gender', 'birthday', 'social_provider', 'social_id',
        'device_os', 'first_name',
        'second_name', 'last_name', 'phone', 'parent_phone', 'parent_job', 'type', 'governorate',
        'profile_image', 'job_title', 'bio',
        'name_ar', 'name_en', 'bio_ar', 'bio_en',
        'notifications_status', 'preferred_locale', 'leaderboard_opt_in', 'last_learning_nudge_at',
        'watch_history_enabled', 'marketing_notifications_enabled',
        'autoplay_next_enabled', 'video_quality_preference', 'video_fit_mode', 'playback_speed',
        'terms_accepted_at', 'privacy_notice_acknowledged_at', 'legal_notice_version',
        'portfolio_slug', 'portfolio_is_public', 'portfolio_headline', 'portfolio_location',
        'portfolio_skills', 'portfolio_links',
    ];

    /**
     * Get the name based on Accept-Language header.
     *
     * @return string|null
     */
    public function getNameAttribute()
    {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('name_ar', $this->attributes) && !array_key_exists('name_en', $this->attributes)) {
            return $this->attributes['name'] ?? null;
        }

        return $this->localizedValue('name_ar', 'name_en', 'name');
    }

    /**
     * Get the bio based on Accept-Language header.
     *
     * @return string|null
     */
    public function getBioAttribute()
    {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('bio_ar', $this->attributes) && !array_key_exists('bio_en', $this->attributes)) {
            return $this->attributes['bio'] ?? null;
        }

        return $this->localizedValue('bio_ar', 'bio_en', 'bio');
    }

    /**
     * Get the profile image URL.
     *
     * @return string|null
     */
    public function getProfileImageUrlAttribute()
    {
        if (!$this->profile_image) {
            return $this->image; // Fallback to HasPhoto image if available
        }

        if (filter_var($this->profile_image, FILTER_VALIDATE_URL)) {
            return $this->profile_image;
        }

        return asset('storage/' . $this->profile_image);
    }

    /**
     * Get the profile deep link for mobile app.
     *
     * @return string|null
     */
    public function getProfileDeeplinkAttribute(): ?string
    {
        if (!(bool) $this->portfolio_is_public || blank($this->portfolio_slug)) {
            return null;
        }

        return route('portfolio.public', ['slug' => $this->portfolio_slug]);
    }

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'api_token', 'access_token',
        'admin_totp_secret', 'admin_mfa_backup_codes', 'admin_totp_last_used_step',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'portfolio_is_public' => 'boolean',
        'portfolio_skills' => 'array',
        'portfolio_links' => 'array',
        'watch_history_enabled' => 'boolean',
        'marketing_notifications_enabled' => 'boolean',
        'autoplay_next_enabled' => 'boolean',
        'playback_speed' => 'float',
        'leaderboard_opt_in' => 'boolean',
        'last_learning_nudge_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'privacy_notice_acknowledged_at' => 'datetime',
        'admin_totp_secret' => 'encrypted',
        'admin_totp_confirmed_at' => 'datetime',
        'admin_totp_last_used_step' => 'integer',
        'admin_mfa_backup_codes' => 'array',
        'last_dashboard_login_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'exam_attempts_count',
        'completed_exams_count',
        'average_exam_score',
        'completed_lessons_count',
        'exam_statistics',
        'lesson_progress_statistics'
    ];

    public function notes()
    {
        return $this->hasMany(UserNote::class);
    }

    public function latestNote()
    {
        return $this->hasOne(UserNote::class)->latest();
    }

    public function addNote()
    {
        return $this->notes()->create([
            'note' => request('note'),
            'created_by' => auth()->id(),
        ]);
    }


    /**
     * @param Builder $builder
     */
    public function scopeActive(Builder $builder)
    {
        $builder->where('active', true);
    }


    public function courses()
    {
        return $this->hasManyThrough('App\Models\ItemList', 'course_user', 'user_id', 'course_id')
            ->where('item_lists.type', 'course')
            ->withTimestamps();

    }

    /**
     * Get exam attempts for this user.
     */
    public function examAttempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /**
     * Get student section progress for this user.
     */
    public function sectionProgress()
    {
        return $this->hasMany(StudentSectionProgress::class);
    }

    /**
     * Get count of exams user has attempted (opened).
     */
    public function getExamAttemptsCountAttribute()
    {
        return $this->examAttempts()->count();
    }

    /**
     * Get count of exams user has completed till the end.
     */
    public function getCompletedExamsCountAttribute()
    {
        return $this->examAttempts()->completed()->count();
    }

    /**
     * Get average score of student in all completed exams.
     */
    public function getAverageExamScoreAttribute()
    {
        $completedExams = $this->examAttempts()->completed()->get();

        if ($completedExams->isEmpty()) {
            return 0;
        }

        $totalScore = $completedExams->sum('score_percentage');
        return round($totalScore / $completedExams->count(), 2);
    }

    /**
     * Get count of lessons user has completed.
     */
    public function getCompletedLessonsCountAttribute()
    {
        return $this->sectionProgress()->completed()->count();
    }

    /**
     * Get comprehensive exam statistics for the user.
     */
    public function getExamStatisticsAttribute()
    {
        $attempts = $this->examAttempts();
        $completedAttempts = $attempts->completed()->get();

        return [
            'total_attempts' => $attempts->count(),
            'completed_exams' => $completedAttempts->count(),
            'average_score' => $completedAttempts->isNotEmpty()
                ? round($completedAttempts->avg('score_percentage'), 2)
                : 0,
            'passed_exams' => $completedAttempts->where('is_passed', true)->count(),
            'failed_exams' => $completedAttempts->where('is_passed', false)->count(),
            'completion_rate' => $attempts->count() > 0
                ? round(($completedAttempts->count() / $attempts->count()) * 100, 2)
                : 0
        ];
    }

    /**
     * Get the course enrollments for the user.
     */
    public function enrollments()
    {
        return $this->hasMany(\App\Models\CourseEnrollment::class);
    }

    /**
     * Get comprehensive lesson progress statistics for the user.
     */
    public function getLessonProgressStatisticsAttribute()
    {
        $totalProgress = $this->sectionProgress();
        $completedProgress = $totalProgress->completed()->get();

        return [
            'total_lessons_accessed' => $totalProgress->count(),
            'completed_lessons' => $completedProgress->count(),
            'completion_rate' => $totalProgress->count() > 0
                ? round(($completedProgress->count() / $totalProgress->count()) * 100, 2)
                : 0
        ];
    }

    /**
     * Get the student notifications for the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function studentNotifications()
    {
        return $this->hasMany(StudentNotification::class, 'user_id');
    }

    /**
     * Get the count of unread notifications for the user.
     *
     * @return int
     */
    public function unreadNotificationsCount()
    {
        return $this->studentNotifications()->unread()->count();
    }

    /**
     * Get the packages purchased by the user.
     */
    public function purchasedPackages()
    {
        return $this->belongsToMany(Package::class, 'package_user')
                    ->withPivot('order_id', 'price', 'coins', 'created_at')
                    ->withTimestamps();
    }

    /**
     * Check if the user is a premium user (has purchased at least one package).
     *
     * @return bool
     */
    public function isPremiumUser(): bool
    {
        return $this->purchasedPackages()->exists();
    }

    public function portfolioItems()
    {
        return $this->hasMany(PortfolioItem::class);
    }

    public function coinEarnings()
    {
        return $this->hasMany(UserCoinEarning::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function coinTaskAttempts()
    {
        return $this->hasMany(UserCoinTaskAttempt::class);
    }

    public function deviceTokens()
    {
        return $this->hasMany(UserDeviceToken::class);
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Get the levels earned by the user.
     */
    public function earnedLevels()
    {
        return $this->belongsToMany(Level::class, 'user_level')
            ->withPivot('id', 'earned_at', 'course_id')
            ->withTimestamps();
    }

    /**
     * Check if user has earned a specific level badge.
     *
     * @param int $levelId
     * @return bool
     */
    public function hasEarnedLevel($levelId, $courseId = null)
    {
        return $this->earnedLevels()
            ->where('level_id', $levelId)
            ->when($courseId, fn ($query) => $query->wherePivot('course_id', $courseId))
            ->exists();
    }

    /**
     * Award a level badge to the user.
     *
     * @param int $levelId
     * @param int $courseId
     * @return void
     */
    public function awardLevelBadge($levelId, $courseId)
    {
        if (!$this->hasEarnedLevel($levelId, $courseId)) {
            $this->earnedLevels()->attach($levelId, [
                'earned_at' => now(),
                'course_id' => $courseId,
            ]);
        }
    }

    /**
     * Get the orders for the user.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get orders count for the user.
     */
    public function ordersCount()
    {
        try {
            return $this->orders()->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get the courses that the user teaches.
     */
    public function teachingCourses()
    {
        return $this->belongsToMany(Course::class, 'course_teacher', 'teacher_id', 'course_id')
                    ->withTimestamps();
    }

    /**
     * Get the interests (classifications) for this user.
     */
    public function interests()
    {
        return $this->belongsToMany(Classification::class, 'classification_user')
                    ->withTimestamps();
    }
}
