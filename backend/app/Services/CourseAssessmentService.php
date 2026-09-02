<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\ItemList;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final readonly class CourseAssessmentService
{
    private const QUESTION_COLUMNS = [
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
    ];

    public function __construct(
        private CourseCompletionService $courseCompletion,
        private FinancialProvenanceService $financialProvenance
    ) {}

    public function accessibleQuizzes(?User $user, int $perPage): LengthAwarePaginator
    {
        $courseIds = $this->accessibleCourseIds($user);

        return ItemList::quiz()
            ->whereHas('courseSection', function (Builder $sections) use ($courseIds): void {
                $sections->whereIn('course_id', $courseIds);
            })
            ->with('photo')
            ->withCount('questions')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function canAccessQuiz(?User $user, ItemList $quiz): bool
    {
        if (!$user || $quiz->type !== 'quiz') {
            return false;
        }

        $sections = CourseSection::query()
            ->where('sectionable_type', ItemList::class)
            ->where('sectionable_id', $quiz->id)
            ->get();
        if ($sections->isNotEmpty()) {
            return $sections->contains(
                fn (CourseSection $section): bool => $this->courseCompletion->canAccessSection($user, $section)
            );
        }

        // Unsectioned legacy quizzes are not part of the authored course graph
        // and cannot become reachable merely through a stale course_id.
        return false;
    }

    public function exam(int $quizId): ItemList
    {
        return ItemList::query()
            ->with([
                'questions' => function (Relation $questions): void {
                    $questions->select(self::QUESTION_COLUMNS)
                        ->with('photo')
                        ->orderBy('priority');
                },
            ])
            ->where('type', 'quiz')
            ->findOrFail($quizId);
    }

    public function examSection(int $courseId, int $sectionId): ?CourseSection
    {
        return CourseSection::query()
            ->whereKey($sectionId)
            ->where('course_id', $courseId)
            ->where('sectionable_type', ItemList::class)
            ->with([
                'sectionable' => function (Relation $quizzes): void {
                    $quizzes->with([
                        'questions' => function (Relation $questions): void {
                            $questions->select(self::QUESTION_COLUMNS)
                                ->with('photo')
                                ->orderBy('priority');
                        },
                    ]);
                },
            ])
            ->first();
    }

    /** @return array<string, mixed> */
    public function examPayload(ItemList $quiz): array
    {
        $questions = $this->questionPayloads($quiz);

        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'type' => $quiz->type,
            'image' => $quiz->image,
            'is_opened' => $quiz->is_opened ?? true,
            'time_minutes' => $quiz->time_minutes,
            'questions_count' => $questions->count(),
            'questions' => $questions,
            'metadata' => $this->metadata($quiz, $questions->count()),
            'instructions' => $this->instructions($quiz, $questions->count()),
            'created_at' => $quiz->created_at,
            'updated_at' => $quiz->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    public function sectionExamPayload(CourseSection $section): array
    {
        /** @var ItemList $quiz */
        $quiz = $section->sectionable;
        $questions = $this->questionPayloads($quiz);

        return [
            'section_id' => $section->id,
            'section_title' => $section->title,
            'section_order' => $section->order,
            'quiz_id' => $quiz->id,
            'quiz_title' => $quiz->title,
            'quiz_description' => $quiz->description,
            'quiz_type' => $quiz->type,
            'quiz_image' => $quiz->image,
            'is_opened' => $quiz->is_opened ?? true,
            'time_minutes' => $quiz->time_minutes,
            'questions_count' => $questions->count(),
            'questions' => $questions,
            'metadata' => $this->metadata($quiz, $questions->count()),
            'instructions' => $this->instructions($quiz, $questions->count()),
            'created_at' => $quiz->created_at,
            'updated_at' => $quiz->updated_at,
        ];
    }

    /** @return array<int, int> */
    private function accessibleCourseIds(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $enrollments = CourseEnrollment::query()
            ->where('user_id', (int) $user->id)
            ->where('is_active', true)
            ->where(function (Builder $enrollments): void {
                $enrollments->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with(['course.sections', 'course.courseSection'])
            ->get()
            ->reject(fn (CourseEnrollment $enrollment): bool =>
                $this->financialProvenance->enrollmentHasActiveHold($enrollment, ['course'])
            );

        $directCourseIds = $enrollments
            ->map->course
            ->filter(fn (?Course $course): bool =>
                $course !== null
                && !$course->isNestedCourse()
                && $course->isPublishedForLearning()
            )
            ->map(fn (Course $course): int => (int) $course->id)
            ->unique()
            ->values();

        if ($directCourseIds->isEmpty()) {
            return [];
        }

        $bundledIds = CourseSection::query()
            ->where('sectionable_type', Course::class)
            ->whereIn('course_id', $directCourseIds)
            ->pluck('sectionable_id');
        $bundledCourseIds = Course::query()
            ->whereIn('id', $bundledIds)
            ->where('is_coming_soon', false)
            ->whereHas('sections')
            ->pluck('id')
            ->map(fn ($courseId): int => (int) $courseId);

        return $directCourseIds
            ->merge($bundledCourseIds)
            ->unique()
            ->values()
            ->all();
    }

    private function questionPayloads(ItemList $quiz): Collection
    {
        return $quiz->questions->map(function ($question): array {
            $choices = [];
            foreach (range(1, 6) as $choiceId) {
                $value = $question->{"choice{$choiceId}"};
                if (!empty($value)) {
                    $choices[] = ['id' => $choiceId, 'text' => $value];
                }
            }
            shuffle($choices);

            return [
                'id' => $question->id,
                'title' => $question->title,
                'question' => $question->question,
                // Question images have always been stored through HasPhoto.
                // Reading only the legacy scalar column made the authoring UI,
                // API and player disagree about whether an image existed.
                'question_image' => $question->image,
                'description' => $question->description,
                'choices' => $choices,
                'priority' => $question->priority,
            ];
        });
    }

    /** @return array<string, int> */
    private function metadata(ItemList $quiz, int $questionCount): array
    {
        return [
            'total_questions' => $questionCount,
            'estimated_time' => $quiz->time_minutes ?? ($questionCount * 2),
            'passing_score' => 60,
            'max_score' => $questionCount * 10,
        ];
    }

    /** @return array<string, int|bool> */
    private function instructions(ItemList $quiz, int $questionCount): array
    {
        return [
            'time_limit' => $quiz->time_minutes ?? ($questionCount * 2),
            'allow_review' => true,
            'show_results' => true,
            'randomize_questions' => false,
            'randomize_choices' => true,
        ];
    }
}
