<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationBackfillSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $tables = [
        'project_submission_review_decisions',
        'project_submissions',
        'users',
        'internal_signals',
        'certificates',
        'course_enrollments',
        'courses',
    ];

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_explicit_completion_signal_precedes_certificate_and_legacy_fallbacks(): void
    {
        $this->createCompletionTables();
        DB::table('courses')->insert([
            'id' => 10,
            'authoring_version' => 9,
            'last_published_authoring_version' => 9,
        ]);
        foreach ([101, 102, 103] as $userId) {
            DB::table('course_enrollments')->insert([
                'user_id' => $userId,
                'course_id' => 10,
            ]);
        }
        DB::table('certificates')->insert([
            ['user_id' => 101, 'course_id' => 10, 'generated_at' => now(), 'created_at' => now()],
            ['user_id' => 102, 'course_id' => 10, 'generated_at' => now(), 'created_at' => now()],
        ]);
        DB::table('internal_signals')->insert([
            [
                'type' => 'course.completed',
                'payload' => json_encode([
                    'user_id' => 101,
                    'course_id' => 10,
                    'curriculum_revision' => 4,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now()->subDay(),
            ],
            [
                'type' => 'course.completed',
                'payload' => json_encode([
                    'user_id' => 103,
                    'course_id' => 10,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now()->subHour(),
            ],
        ]);

        $migrationPath = database_path(
            'migrations/2026_09_01_000085_version_course_completion_by_published_revision.php'
        );
        (require $migrationPath)->up();
        (require $migrationPath)->up();

        self::assertSame(4, (int) DB::table('course_enrollments')->where('user_id', 101)->value('completed_curriculum_revision'));
        self::assertSame(9, (int) DB::table('course_enrollments')->where('user_id', 102)->value('completed_curriculum_revision'));
        self::assertSame(9, (int) DB::table('course_enrollments')->where('user_id', 103)->value('completed_curriculum_revision'));
    }

    public function test_project_review_snapshot_migration_can_resume_without_duplicate_baselines(): void
    {
        $this->createProjectReviewTables();
        DB::table('users')->insert(['id' => 7, 'role' => 'moderator']);
        DB::table('project_submissions')->insert([
            'id' => 21,
            'submission_metadata' => json_encode(['kind' => 'image'], JSON_THROW_ON_ERROR),
            'review_status' => 'passed',
            'review_source' => 'human',
            'score' => 88,
            'feedback' => 'عمل جيد',
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
            'reviewed_by' => 7,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);
        $migrationPath = database_path(
            'migrations/2026_09_02_000001_snapshot_project_reviews.php'
        );

        (require $migrationPath)->up();
        (require $migrationPath)->up();

        self::assertTrue(Schema::hasColumn('project_submissions', 'evaluation_snapshot'));
        self::assertSame(1, DB::table('project_submission_review_decisions')->count());
        $this->assertDatabaseHas('project_submission_review_decisions', [
            'submission_id' => 21,
            'sequence' => 1,
            'status' => 'passed',
            'reviewer_id' => 7,
            'reviewer_role' => 'moderator',
        ]);
    }

    private function createCompletionTables(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('authoring_version')->default(1);
            $table->unsignedBigInteger('last_published_authoring_version')->nullable();
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unique(['user_id', 'course_id']);
        });
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('internal_signals', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 64)->index();
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createProjectReviewTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 24);
        });
        Schema::create('project_submissions', function (Blueprint $table): void {
            $table->id();
            $table->json('submission_metadata')->nullable();
            $table->string('review_status', 30)->default('pending');
            $table->string('review_source', 40)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();
        });
    }
}
