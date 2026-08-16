<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ItemList;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ExamLifecycleService
{
    /**
     * @return array{authorized: bool, resumed: bool, attempt: ExamAttempt|null}
     */
    public function start(
        User $user,
        int $quizId,
        ?int $courseId,
        ?int $sectionId
    ): array {
        return DB::transaction(function () use ($user, $quizId, $courseId, $sectionId): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $quiz = ItemList::query()
                ->with(['questions' => function ($query): void {
                    $query->select(
                        'id',
                        'list_id',
                        'title',
                        'question',
                        'question_image',
                        'description',
                        'choice1',
                        'choice2',
                        'choice3',
                        'choice4',
                        'choice5',
                        'choice6',
                        'priority',
                        'right_answer'
                    )->orderBy('priority');
                }])
                ->findOrFail($quizId);

            if (!$this->hasQuizAccess($user, $quiz, $courseId)) {
                return ['authorized' => false, 'resumed' => false, 'attempt' => null];
            }

            $existingAttempt = ExamAttempt::query()
                ->where('user_id', $user->getKey())
                ->where('quiz_id', $quizId)
                ->inProgress()
                ->lockForUpdate()
                ->first();

            if ($existingAttempt !== null) {
                return ['authorized' => true, 'resumed' => true, 'attempt' => $existingAttempt];
            }

            $lastAttempt = ExamAttempt::query()
                ->where('user_id', $user->getKey())
                ->where('quiz_id', $quizId)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            $attempt = ExamAttempt::query()->create([
                'user_id' => $user->getKey(),
                'quiz_id' => $quizId,
                'course_id' => $courseId,
                'section_id' => $sectionId,
                'attempt_number' => $lastAttempt === null ? 1 : (int) $lastAttempt->attempt_number + 1,
                'status' => ExamAttempt::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'total_questions' => $quiz->questions->count(),
                'exam_data' => $quiz->questions
                    ->map(static fn (Question $question): array => [
                        'id' => $question->id,
                        'title' => $question->title,
                        'question' => $question->question,
                        'question_image' => $question->question_image,
                        'description' => $question->description,
                        'choice1' => $question->choice1,
                        'choice2' => $question->choice2,
                        'choice3' => $question->choice3,
                        'choice4' => $question->choice4,
                        'choice5' => $question->choice5,
                        'choice6' => $question->choice6,
                        'right_answer' => $question->right_answer,
                        'priority' => $question->priority,
                    ])
                    ->all(),
            ]);

            return ['authorized' => true, 'resumed' => false, 'attempt' => $attempt];
        }, 3);
    }

    /**
     * @return array{state: string, attempt: ExamAttempt|null, answer: ExamAnswer|null}
     */
    public function submitAnswer(
        User $user,
        int $attemptId,
        int $questionId,
        int $selectedAnswer
    ): array {
        return DB::transaction(function () use ($user, $attemptId, $questionId, $selectedAnswer): array {
            $attempt = ExamAttempt::query()
                ->whereKey($attemptId)
                ->where('user_id', $user->getKey())
                ->inProgress()
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                return $this->answerResult('attempt_missing');
            }

            $questionData = collect($attempt->exam_data)
                ->firstWhere('id', $questionId);
            if (!is_array($questionData)) {
                return $this->answerResult('question_missing', $attempt);
            }

            $isCorrect = hash_equals(
                trim((string) ($questionData['right_answer'] ?? '')),
                (string) $selectedAnswer
            );

            $answer = ExamAnswer::query()
                ->where('exam_attempt_id', $attemptId)
                ->where('question_id', $questionId)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'selected_answer' => $selectedAnswer,
                'is_correct' => $isCorrect,
                'points_earned' => $isCorrect ? 10 : 0,
                'max_points' => 10,
                'answered_at' => now(),
                'question_data' => $questionData,
            ];

            if ($answer === null) {
                $answer = ExamAnswer::query()->create([
                    'exam_attempt_id' => $attemptId,
                    'question_id' => $questionId,
                    ...$attributes,
                ]);
            } else {
                $answer->fill($attributes)->save();
            }

            $attempt->forceFill([
                'answered_questions' => $attempt->answers()->count(),
                'correct_answers' => $attempt->answers()->where('is_correct', true)->count(),
            ])->save();

            return [
                'state' => 'submitted',
                'attempt' => $attempt,
                'answer' => $answer,
            ];
        }, 3);
    }

    public function end(User $user, int $attemptId, mixed $securitySummary): ?ExamAttempt
    {
        return DB::transaction(function () use ($user, $attemptId, $securitySummary): ?ExamAttempt {
            $attempt = ExamAttempt::query()
                ->whereKey($attemptId)
                ->where('user_id', $user->getKey())
                ->inProgress()
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                return null;
            }

            $attempt->load('answers');
            $attempt->status = ExamAttempt::STATUS_COMPLETED;
            $attempt->completed_at = now();
            $attempt->time_taken_minutes = $attempt->calculateTimeTaken();
            $attempt->calculateScore();

            if ($securitySummary !== null) {
                $attempt->security_summary = $securitySummary;
            }

            $attempt->save();

            return $attempt;
        }, 3);
    }

    private function hasQuizAccess(User $user, ItemList $quiz, ?int $requestedCourseId): bool
    {
        $sectionCourseId = CourseSection::query()
            ->where('sectionable_type', ItemList::class)
            ->where('sectionable_id', $quiz->getKey())
            ->value('course_id');

        $requiredCourseIds = collect([
            $requestedCourseId,
            $sectionCourseId === null ? null : (int) $sectionCourseId,
            $quiz->course_id === null ? null : (int) $quiz->course_id,
        ])->filter()->unique()->values();

        if ($requiredCourseIds->isEmpty()) {
            return true;
        }

        $enrollments = CourseEnrollment::query()
            ->where('user_id', $user->getKey())
            ->whereIn('course_id', $requiredCourseIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('course_id');

        return $requiredCourseIds->every(
            static fn (int $courseId): bool => $enrollments->get($courseId)?->isActive() === true
        );
    }

    /**
     * @return array{state: string, attempt: ExamAttempt|null, answer: null}
     */
    private function answerResult(string $state, ?ExamAttempt $attempt = null): array
    {
        return [
            'state' => $state,
            'attempt' => $attempt,
            'answer' => null,
        ];
    }
}
