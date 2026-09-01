<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamAttempt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'quiz_id',
        'quiz_title',
        'quiz_description',
        'quiz_image',
        'course_id',
        'section_id',
        'attempt_number',
        'status',
        'started_at',
        'completed_at',
        'time_taken_minutes',
        'total_questions',
        'answered_questions',
        'correct_answers',
        'score_percentage',
        'score_points',
        'is_passed',
        'exam_data',
        'security_summary',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'exam_data' => 'array',
        'security_summary' => 'array',
        'is_passed' => 'boolean',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(ItemList::class, 'quiz_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function securityLogs(): HasMany
    {
        return $this->hasMany(ExamSecurityLog::class);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForQuiz(Builder $query, int $quizId): Builder
    {
        return $query->where('quiz_id', $quizId);
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isAbandoned(): bool
    {
        return $this->status === self::STATUS_ABANDONED;
    }

    public function canContinue(): bool
    {
        return $this->isInProgress()
            && $this->started_at !== null
            && $this->started_at->copy()->addHours(24)->isFuture()
            && $this->courseContextIsAvailable();
    }

    /** Historical results survive retirement; an active attempt does not. */
    public function courseContextIsAvailable(): bool
    {
        if ($this->course_id === null) {
            return true;
        }

        $course = Course::query()->withCount('sections')->find($this->course_id);

        return $course !== null && $course->isPublishedForLearning();
    }

    public function calculateTimeTaken(): ?int
    {
        if ($this->completed_at && $this->started_at) {
            return (int) floor($this->started_at->diffInMinutes($this->completed_at, true));
        }
        return null;
    }

    public function calculateScore(): void
    {
        if ($this->total_questions > 0) {
            $this->score_percentage = round(($this->correct_answers / $this->total_questions) * 100, 2);
            $this->score_points = round(($this->correct_answers / $this->total_questions) * 100, 2);
            $this->is_passed = $this->score_percentage >= 60;
            return;
        }

        $this->score_percentage = 0;
        $this->score_points = 0;
        $this->is_passed = false;
    }

    public function getNextAttemptNumber(): int
    {
        $lastAttempt = self::where('user_id', $this->user_id)
            ->where('quiz_id', $this->quiz_id)
            ->orderBy('attempt_number', 'desc')
            ->first();

        return $lastAttempt ? (int) $lastAttempt->attempt_number + 1 : 1;
    }

    /** @return array{title:string,description:?string,image:?string} */
    public function quizSnapshot(): array
    {
        $title = trim((string) $this->quiz_title);
        if ($title === '' && $this->quiz) {
            $title = trim((string) $this->quiz->title);
        }

        $description = $this->quiz_description;
        if ($description === null && $this->quiz) {
            $description = $this->quiz->description;
        }
        $image = $this->quiz_image;
        if ($image === null && $this->quiz) {
            $image = $this->quiz->image;
        }

        return [
            'title' => $title !== '' ? $title : 'اختبار',
            'description' => $description !== null ? (string) $description : null,
            'image' => $image !== null ? (string) $image : null,
        ];
    }
}
