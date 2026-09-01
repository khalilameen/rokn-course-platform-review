<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\CourseSection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests covering Course API endpoints:
 * listing courses, viewing details, student progress, section completion, ratings, and best students.
 */
class CourseEndpointTest extends ApiTestCase
{
    public function test_can_list_courses(): void
    {
        $this->getJson('/api/v1/courses/list')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'courses',
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                        'from',
                        'to',
                    ],
                ],
            ]);

        $this->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true);
    }

    public function test_mobile_catalogue_uses_revisioned_shared_cache(): void
    {
        Cache::flush();

        $first = $this->getJson('/api/v1/courses/list')
            ->assertOk()
            ->json('data.courses.0.title');

        DB::table('courses')->where('id', $this->courseId)->update([
            'name_ar' => 'عنوان بعد التحديث',
            'name_en' => 'Updated course title',
        ]);

        $this->getJson('/api/v1/courses/list')
            ->assertOk()
            ->assertJsonPath('data.courses.0.title', $first);

        Cache::forever('courses:catalog-revision', 2);

        $this->getJson('/api/v1/courses/list')
            ->assertOk()
            ->assertJsonPath('data.courses.0.title', 'Updated course title');
    }

    public function test_section_changes_invalidate_mobile_catalogue_cache(): void
    {
        Cache::flush();

        $this->getJson('/api/v1/courses/list')
            ->assertOk()
            ->assertJsonPath('data.courses.0.metadata.sections_count', 1);

        CourseSection::create([
            'course_id' => $this->courseId,
            'title' => 'Second section',
            'sectionable_type' => null,
            'sectionable_id' => null,
            'order' => 2,
        ]);

        $this->getJson('/api/v1/courses/list')
            ->assertOk()
            ->assertJsonPath('data.courses.0.metadata.sections_count', 2);
    }

    public function test_dashboard_main_course_stays_first_in_mobile_catalogue(): void
    {
        DB::table('courses')->insert([
            'name_ar' => 'قريبًا',
            'name_en' => 'Coming soon',
            'grade_id' => $this->gradeId,
            'price' => 200,
            'active' => true,
            'is_main_course' => false,
            'is_coming_soon' => true,
            'is_catalog_visible' => true,
            'course_type' => 'online',
            'rate' => 5,
            'created_at' => now()->addDay(),
            'updated_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/v1/courses/list?per_page=1');

        $response->assertOk()
            ->assertJsonPath('data.courses.0.id', $this->courseId)
            ->assertJsonPath('data.courses.0.is_main_course', true);
    }

    public function test_can_view_course_details(): void
    {
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            $this->getJson("/api/v1/courses/{$this->courseId}/details")
                ->assertOk()
                ->assertJsonPath('status', 200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.id', $this->courseId);

            $this->getJson("/api/v1/course/{$this->courseId}")
                ->assertOk()
                ->assertJsonPath('status', 200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('message', 'تم تحميل الكورس')
                ->assertJsonPath('data.id', $this->courseId);
        } finally {
            Schema::dropIfExists('course_modules');
        }
    }

    public function test_catalog_visible_coming_soon_course_has_a_guest_details_page(): void
    {
        $teacherId = (int) DB::table('courses')->where('id', $this->courseId)->value('teacher_id');
        $courseId = DB::table('courses')->insertGetId([
            'name_ar' => 'كورس قادم',
            'name_en' => 'Coming soon course',
            'description_ar' => 'وصف الكورس القادم',
            'description_en' => 'Coming soon course description',
            'image' => 'courses/coming-soon.jpg',
            'teacher_id' => $teacherId,
            'grade_id' => $this->gradeId,
            'price' => 200,
            'active' => true,
            'is_main_course' => false,
            'is_coming_soon' => true,
            'is_catalog_visible' => true,
            'course_type' => 'online',
            'rate' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_teacher')->insert([
            'course_id' => $courseId,
            'teacher_id' => $teacherId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('classification_course')->insert([
            'classification_id' => 1,
            'course_id' => $courseId,
        ]);

        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            $this->getJson("/api/v1/courses/{$courseId}/details")
                ->assertOk()
                ->assertJsonPath('data.id', $courseId)
                ->assertJsonPath('data.is_coming_soon', true);
        } finally {
            Schema::dropIfExists('course_modules');
        }
    }

    public function test_every_legacy_lesson_shape_exposes_the_same_duration(): void
    {
        $this->getJson('/api/v1/lesson/10')
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 15);

        DB::table('lessons')->where('id', 10)->update(['is_opened' => false]);

        $this->getJson('/api/v1/lesson/10')
            ->assertOk()
            ->assertJsonPath('message', 'تم تحميل العينة المجانية')
            ->assertJsonPath('data.duration_minutes', 15)
            ->assertJsonMissingPath('data.video_link');
    }

    public function test_authenticated_user_can_view_course_progress(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/progress")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);
    }

    public function test_authenticated_user_can_mark_section_complete(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/courses/{$this->courseId}/sections/{$this->sectionId}/complete")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_best_students_is_not_published_as_a_public_endpoint(): void
    {
        $this->getJson("/api/v1/courses/{$this->courseId}/best-students")
            ->assertNotFound();
    }

    public function test_course_details_falls_back_when_requested_translation_is_blank(): void
    {
        DB::table('courses')->where('id', $this->courseId)->update([
            'name_ar' => 'عنوان عربي موجود',
            'name_en' => '   ',
            'description_ar' => 'وصف عربي موجود',
            'description_en' => '',
        ]);

        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            $this->withHeader('Accept-Language', 'en')
                ->getJson("/api/v1/courses/{$this->courseId}/details")
                ->assertOk()
                ->assertJsonPath('data.title', 'عنوان عربي موجود')
                ->assertJsonPath('data.description', 'وصف عربي موجود');
        } finally {
            Schema::dropIfExists('course_modules');
        }
    }

    public function test_user_without_active_course_access_cannot_rate_course(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/courses/{$this->courseId}/rate", [
                'rating' => 5,
                'comment' => 'Great course',
                'version' => 0,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('course_ratings', [
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
        ]);
    }

    public function test_course_details_return_the_current_students_rating(): void
    {
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            $this->actingAs($this->user, 'api')
                ->postJson('/api/v1/course-codes/redeem', ['code' => 'TESTCODE'])
                ->assertOk();

            DB::table('lesson_watch_evidence')->insert([
                'user_id' => $this->user->id,
                'lesson_id' => 10,
                'course_section_id' => $this->sectionId,
                'duration_seconds' => 900,
                'verified_seconds' => 900,
                'last_position_seconds' => 900,
                'last_heartbeat_at' => now(),
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->actingAs($this->user, 'api')
                ->postJson("/api/v1/courses/{$this->courseId}/rate", [
                    'rating' => 4,
                    'version' => 0,
                ])
                ->assertOk()
                ->assertJsonPath('data.rating', 4);

            $this->actingAs($this->user, 'api')
                ->getJson("/api/v1/courses/{$this->courseId}/details")
                ->assertOk()
                ->assertJsonPath('data.user_rating.rating', 4)
                ->assertJsonPath('data.ratings_count', 1)
                ->assertJsonPath('data.average_rating', 4);
        } finally {
            Schema::dropIfExists('course_modules');
        }
    }

    public function test_authenticated_user_can_view_project_evaluations(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/project-evaluations");
        $this->assertNotEquals(404, $response->status());
    }
}
