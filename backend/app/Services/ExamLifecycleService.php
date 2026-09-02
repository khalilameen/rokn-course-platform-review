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
    public function __construct(
        private CourseCompletionService $courseCompletion,
        private CourseStagedAuthoringService $stagedAuthoring
    ) {}

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
                    )->with('photo')->orderBy('priority')->orderBy('id');
                }])
                ->findOrFail($quizId);

            if (!$this->hasQuizAccess($user, $quiz, $courseId, $sectionId)) {
                return ['authorized' => false, 'resumed' => false, 'attempt' => null];
            }

            $quizAliases = $this->stagedAuthoring->equivalentEntityIds(ItemList::class, $quizId);
            $sectionAliases = $sectionId === null
                ? []
                : $this->stagedAuthoring->equivalentEntityIds(CourseSection::class, $sectionId);

            $existingAttempt = ExamAttempt::query()
                ->where('user_id', $user->getKey())
                ->whereIn('quiz_id', $quizAliases)
                ->when(
                    $sectionId !== null,
                    fn ($attempts) => $attempts->whereIn('section_id', $sectionAliases),
                    fn ($attempts) => $attempts->whereNull('section_id')
                )
                ->when(
                    $courseId !== null,
                    fn ($attempts) => $attempts->where('course_id', $courseId),
                    fn ($attempts) => $attempts->whereNull('course_id')
                )
                ->inProgress()
                ->lockForUpdate()
                ->first();

            if ($existingAttempt !== null) {
                // Materialize only this learner's active pointer. Publishing
                // never scans or locks the shared attempts table.
                $existingAttempt->forceFill([
                    'quiz_id' => $quizId,
                    'section_id' => $sectionId,
                ])->save();
                return ['authorized' => true, 'resumed' => true, 'attempt' => $existingAttempt];
            }

            // Draft rows may exist while a moderator is still authoring. A
            // new immutable attempt is created only from a complete, answerable
            // question set; an already-open attempt above remains resumable
            // from its own snapshot even if the live quiz changes later.
            if (!$this->quizCanStart($quiz)) {
                return ['authorized' => false, 'resumed' => false, 'attempt' => null];
            }

            $lastAttempt = ExamAttempt::query()
                ->where('user_id', $user->getKey())
                ->whereIn('quiz_id', $quizAliases)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            $attempt = ExamAttempt::query()->create([
                'user_id' => $user->getKey(),
                'quiz_id' => $quizId,
                'quiz_title' => trim((string) $quiz->title),
                'quiz_description' => $quiz->description,
                'quiz_image' => $quiz->image,
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
                        // Freeze the resolved public asset into the immutable
                        // attempt so later question edits cannot change what
                        // this learner was asked to answer.
                        'question_image' => $question->image,
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
            if (!$attempt->courseContextIsAvailable()) {
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

            if (
                $answer !== null
                && (int) $answer->selected_answer !== $selectedAnswer
            ) {
                // A retry with the same answer is idempotent. A different
                // answer from another device/stale screen must not rewrite a
                // response the learner has already submitted.
                return [
                    'state' => 'answer_conflict',
                    'attempt' => $attempt,
                    'answer' => $answer,
                ];
            }

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
        $attempt = DB::transaction(function () use ($user, $attemptId, $securitySummary): ?ExamAttempt {
            $attempt = ExamAttempt::query()
                ->whereKey($attemptId)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                return null;
            }
            // Ending is an idempotent transition. A mobile client can receive
            // the completed response after the server commits, lose the
            // connection, then safely ask again without being forced to retake
            // the assessment.
            if ($attempt->isCompleted()) {
                return $attempt;
            }
            if (!$attempt->isInProgress() || !$attempt->courseContextIsAvailable()) {
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

        // The attempt owns an immutable question snapshot, so a publish may
        // safely remap its section identity while it is open. Completing a
        // passed attempt also closes progression server-side; a lost mobile
        // follow-up cannot leave the next section locked.
        if (
            $attempt
            && $attempt->isCompleted()
            && (bool) $attempt->is_passed
            && $attempt->course_id !== null
            && $attempt->section_id !== null
        ) {
            $currentSectionId = $this->stagedAuthoring->currentLearnerEntityMap(
                CourseSection::class,
                [(int) $attempt->section_id]
            )[(int) $attempt->section_id] ?? (int) $attempt->section_id;
            $this->courseCompletion->complete(
                $user,
                (int) $attempt->course_id,
                $currentSectionId
            );
        }

        return $attempt;
    }

    private function hasQuizAccess(
        User $user,
        ItemList $quiz,
        ?int $requestedCourseId,
        ?int $requestedSectionId
    ): bool
    {
        $sectionQuery = CourseSection::query()
            ->where('sectionable_type', ItemList::class)
            ->where('sectionable_id', $quiz->getKey());
        if ($requestedSectionId !== null) {
            $sectionQuery->whereKey($requestedSectionId);
        } elseif ($requestedCourseId !== null) {
            $sectionQuery->where('course_id', $requestedCourseId);
        }
        $section = $sectionQuery->first();

        if ($requestedSectionId !== null && !$section) {
            return false;
        }
        if ($section) {
            if ($requestedCourseId !== null && (int) $section->course_id !== $requestedCourseId) {
                return false;
            }

            return $this->courseCompletion->canAccessSection($user, $section);
        }

        $quizCourseId = $quiz->course_id === null ? null : (int) $quiz->course_id;
        if ($requestedCourseId !== null && $quizCourseId !== null && $requestedCourseId !== $quizCourseId) {
            return false;
        }
        $courseId = $requestedCourseId ?? $quizCourseId;
        if ($courseId === null) {
            return true;
        }

        $enrollment = CourseEnrollment::query()
            ->where('user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();

        return $enrollment?->isActive() === true;
    }

    private function quizCanStart(ItemList $quiz): bool
    {
        if ($quiz->questions->isEmpty()) {
            return false;
        }

        $ids = [];
        foreach ($quiz->questions as $question) {
            $questionId = (int) $question->id;
            $rightAnswer = (int) $question->right_answer;
            $rightChoice = $rightAnswer >= 1 && $rightAnswer <= 6
                ? trim((string) $question->{"choice{$rightAnswer}"})
                : '';
            if (
                $questionId <= 0
                || isset($ids[$questionId])
                || trim((string) $question->question) === ''
                || trim((string) $question->choice1) === ''
                || trim((string) $question->choice2) === ''
                || $rightChoice === ''
            ) {
                return false;
            }
            $ids[$questionId] = true;
        }

        return true;
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
