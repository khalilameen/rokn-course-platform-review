<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Support\Facades\DB;

/**
 * Feature tests covering Exam & Quiz API endpoints:
 * quizzes, exams, random quizzes, exam attempts, submitting answers, finishing exams,
 * history, progress, results, and security logging.
 */
class ExamEndpointTest extends ApiTestCase
{
    private int $quizSectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quizSectionId = (int) DB::table('course_sections')->insertGetId([
            'course_id' => $this->courseId,
            'title_ar' => 'اختبار الكورس',
            'title_en' => 'Course quiz',
            'section_type' => 'quiz',
            'sectionable_type' => \App\Models\ItemList::class,
            'sectionable_id' => 1,
            'order' => 2,
            'sort_order' => 2,
            'is_free' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_assessment_bank_routes_require_authentication(): void
    {
        foreach ([
            '/api/v1/quizzes',
            '/api/v1/exams',
            '/api/v1/list/1',
            '/api/v1/question/1',
            '/api/v1/random-quizzes',
            '/api/v1/random-quizzes/1',
            '/api/v1/exams/1/data',
        ] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
    }

    public function test_unenrolled_student_cannot_read_paid_assessment_questions(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/list/1')
            ->assertStatus(403);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/question/1')
            ->assertStatus(403);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/exams/1/data')
            ->assertStatus(403);
    }

    public function test_enrolled_student_can_read_assessment_without_right_answer(): void
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => 1,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quiz = $this->actingAs($this->user, 'api')->getJson('/api/v1/list/1');
        $quiz->assertStatus(200)->assertJsonMissingPath('data.items.0.right_answer');

        $question = $this->actingAs($this->user, 'api')->getJson('/api/v1/question/1');
        $question->assertStatus(200)->assertJsonMissingPath('data.right_answer');

        $exam = $this->actingAs($this->user, 'api')->getJson('/api/v1/exams/1/data');
        $exam->assertStatus(200)->assertJsonMissingPath('data.questions.0.right_answer');
    }

    public function test_quiz_index_is_bounded_and_ignores_unavailable_banks_at_constant_query_cost(): void
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => 1,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $baseline = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/quizzes');
        $baselineQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $baseline
            ->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم تحميل الاختبارات')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonMissingPath('data.0.items.0.right_answer');

        self::assertLessThanOrEqual(
            15,
            $baselineQueryCount,
            'The quiz index query budget must remain bounded.'
        );

        $unavailableCourseId = (int) DB::table('courses')->insertGetId([
            'name_ar' => 'Unavailable assessment course',
            'name_en' => 'Unavailable assessment course',
            'grade_id' => $this->gradeId,
            'price' => 100,
            'active' => 1,
            'is_main_course' => 0,
            'is_coming_soon' => 0,
            'course_type' => 'online',
            'rate' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quizRows = [];
        $questionRows = [];
        for ($offset = 0; $offset < 80; $offset++) {
            $quizId = 100 + $offset;
            $quizRows[] = [
                'id' => $quizId,
                'title' => "Unavailable Quiz {$quizId}",
                'title_ar' => "Unavailable Quiz {$quizId}",
                'title_en' => "Unavailable Quiz {$quizId}",
                'course_id' => $unavailableCourseId,
                'type' => 'quiz',
                'time_minutes' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $questionRows[] = [
                'id' => 1000 + $offset,
                'list_id' => $quizId,
                'title' => "Private question {$quizId}",
                'question' => "Private question {$quizId}",
                'choice1' => 'A',
                'choice2' => 'B',
                'right_answer' => 'A',
                'priority' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('lists')->insert($quizRows);
        DB::table('questions')->insert($questionRows);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $expanded = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/quizzes');
        $expandedQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $expanded
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonMissing(['title' => 'Unavailable Quiz 100'])
            ->assertJsonMissing(['question' => 'Private question 100']);

        self::assertLessThanOrEqual(
            $baselineQueryCount,
            $expandedQueryCount,
            'Unavailable platform-wide quiz banks must not add entitlement or hydration queries.'
        );

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/quizzes?per_page=26')
            ->assertStatus(422);
    }

    public function test_random_quiz_is_authenticated_metadata_only(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/random-quizzes/1')
            ->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم تحميل الاختبار السريع')
            ->assertJsonPath('data.preview_only', true)
            ->assertJsonMissingPath('data.items');
    }

    public function test_free_lesson_preview_never_embeds_assessment_questions(): void
    {
        DB::table('lessons')->where('id', 10)->update(['quiz_id' => 1]);

        $this->getJson('/api/v1/lesson/10')
            ->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم تحميل المقطع')
            ->assertJsonPath('data.quiz.id', 1)
            ->assertJsonMissingPath('data.quiz.items')
            ->assertJsonMissingPath('data.quiz.right_answer');
    }

    public function test_can_get_exam_data(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/exams/1/data');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_get_section_exam_data(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/sections/{$this->quizSectionId}/exam");
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_start_exam(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/exams/start', ['quiz_id' => 1]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_unenrolled_student_cannot_start_a_course_exam(): void
    {
        DB::table('exam_attempts')->delete();

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/start', ['quiz_id' => 1])
            ->assertStatus(403)
            ->assertJsonPath('status', 403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);
    }

    public function test_enrolled_student_cannot_start_a_quiz_before_its_section_is_unlocked(): void
    {
        $this->enrollCurrentUser();
        DB::table('settings')->update(['enforce_course_section_order' => 1]);
        DB::table('course_sections')->where('id', $this->sectionId)->update([
            'section_type' => 'lesson',
            'sectionable_type' => \App\Models\Lesson::class,
            'sectionable_id' => 10,
            'order' => 1,
        ]);
        $quizSectionId = $this->quizSectionId;
        DB::table('course_sections')->where('id', $quizSectionId)->update([
            'title_ar' => 'اختبار الوحدة',
            'section_type' => 'quiz',
            'sectionable_type' => \App\Models\ItemList::class,
            'sectionable_id' => 1,
            'order' => 2,
            'sort_order' => 2,
            'is_free' => 0,
            'updated_at' => now(),
        ]);

        $payload = [
            'quiz_id' => 1,
            'course_id' => $this->courseId,
            'section_id' => $quizSectionId,
        ];
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/start', $payload)
            ->assertStatus(403);

        DB::table('student_section_progress')->insert([
            'user_id' => $this->user->id,
            'course_section_id' => $this->sectionId,
            'is_completed' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/start', $payload)
            ->assertOk();
    }

    public function test_repeated_start_resumes_one_in_progress_attempt(): void
    {
        $this->enrollCurrentUser();
        DB::table('exam_attempts')->delete();

        $first = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/start', ['quiz_id' => 1])
            ->assertOk()
            ->assertJsonPath('message', 'بدأ الاختبار');

        $second = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/start', ['quiz_id' => 1])
            ->assertOk()
            ->assertJsonPath('message', 'تم استكمال المحاولة');

        self::assertSame(
            $first->json('data.exam_attempt_id'),
            $second->json('data.exam_attempt_id')
        );
        self::assertSame(1, DB::table('exam_attempts')->count());
    }

    public function test_submit_answer_is_not_a_correctness_oracle_and_conflicting_edits_are_rejected(): void
    {
        $this->enrollCurrentUser();
        DB::table('exam_attempts')->delete();
        DB::table('questions')->where('id', 1)->update(['right_answer' => '4']);

        $attemptId = (int) $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/start', ['quiz_id' => 1])
            ->assertOk()
            ->json('data.exam_attempt_id');

        $payload = [
            'exam_attempt_id' => $attemptId,
            'question_id' => 1,
            'selected_answer' => 4,
        ];
        $first = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/submit-answer', $payload)
            ->assertOk()
            ->assertJsonMissingPath('data.is_correct')
            ->assertJsonMissingPath('data.correct_answers')
            ->assertJsonMissingPath('data.score_percentage')
            ->assertJsonMissingPath('data.score_points')
            ->assertJsonMissingPath('data.points_earned')
            ->assertJsonMissingPath('data.right_answer')
            ->assertJsonPath('data.answered_questions', 1);

        $retry = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/submit-answer', $payload)
            ->assertOk();
        self::assertSame($first->json('data'), $retry->json('data'));

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/submit-answer', [
                ...$payload,
                'selected_answer' => 2,
            ])
            ->assertConflict()
            ->assertJsonPath('data.code', 'quiz_answer_conflict');

        self::assertSame(1, DB::table('exam_answers')->where('exam_attempt_id', $attemptId)->count());
        $this->assertDatabaseHas('exam_answers', [
            'exam_attempt_id' => $attemptId,
            'question_id' => 1,
            'selected_answer' => 4,
            'is_correct' => 1,
        ]);
        $this->assertDatabaseHas('exam_attempts', [
            'id' => $attemptId,
            'answered_questions' => 1,
            'correct_answers' => 1,
        ]);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/exams/end', ['exam_attempt_id' => $attemptId])
            ->assertOk()
            ->assertJsonPath('data.correct_answers', 1)
            ->assertJsonPath('data.score_percentage', 100)
            ->assertJsonPath('data.is_passed', true);
    }

    public function test_can_submit_exam_answer(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/exams/submit-answer', [
            'exam_attempt_id' => 1,
            'question_id' => 1,
            'answer' => 2
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_exam_progress(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/exams/1/progress');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_end_exam(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/exams/end', ['exam_attempt_id' => 1]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_exam_results(): void
    {
        \Illuminate\Support\Facades\DB::table('exam_attempts')->insert([
            'id' => 2,
            'user_id' => $this->user->id,
            'quiz_id' => 1,
            'course_id' => $this->courseId,
            'section_id' => $this->sectionId,
            'attempt_number' => 1,
            'status' => 'completed',
            'total_questions' => 10,
            'answered_questions' => 10,
            'correct_answers' => 8,
            'exam_data' => json_encode([]),
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/exams/2/results');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_all_exam_results(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/exams/results/');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_exam_history(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/exams/history');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_log_exam_security_event(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/exams/security-log', [
            'exam_attempt_id' => 1,
            'event_type' => 'tab_switch',
            'details' => 'Switched tab'
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    private function enrollCurrentUser(): void
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => 1,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
