<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_attempt_id',
        'question_id',
        'selected_answer',
        'is_correct',
        'points_earned',
        'max_points',
        'answered_at',
        'question_data',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'question_data' => 'array',
        'is_correct' => 'boolean',
        'selected_answer' => 'integer',
    ];

    public function examAttempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function scopeCorrect(Builder $query): Builder
    {
        return $query->where('is_correct', true);
    }

    public function scopeIncorrect(Builder $query): Builder
    {
        return $query->where('is_correct', false);
    }

    public function isCorrect(): bool
    {
        return $this->is_correct === true;
    }

    public function getSelectedAnswerText(): ?string
    {
        if (!$this->question_data) {
            return null;
        }

        $choiceMap = [
            1 => 'choice1',
            2 => 'choice2',
            3 => 'choice3',
            4 => 'choice4',
            5 => 'choice5',
            6 => 'choice6',
        ];

        $choiceKey = $choiceMap[$this->selected_answer] ?? null;
        $value = $choiceKey ? ($this->question_data[$choiceKey] ?? null) : null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function getCorrectAnswerText(): ?string
    {
        if (!$this->question_data) {
            return null;
        }

        $rightAnswer = $this->question_data['right_answer'] ?? null;
        if (!$rightAnswer) {
            return null;
        }

        $choiceMap = [
            1 => 'choice1',
            2 => 'choice2',
            3 => 'choice3',
            4 => 'choice4',
            5 => 'choice5',
            6 => 'choice6',
        ];

        $choiceKey = $choiceMap[$rightAnswer] ?? null;
        $value = $choiceKey ? ($this->question_data[$choiceKey] ?? null) : null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function calculatePoints(): void
    {
        if ($this->is_correct) {
            $this->points_earned = $this->max_points;
        } else {
            $this->points_earned = 0;
        }
    }
}
