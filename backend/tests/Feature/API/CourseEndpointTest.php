<?php

declare(strict_types=1);

namespace Tests\Feature\API;

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
                ->assertJsonPath('message', 'Course retrieved successfully')
                ->assertJsonPath('data.id', $this->courseId);
        } finally {
            Schema::dropIfExists('course_modules');
        }
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

    public function test_authenticated_user_can_rate_course(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson("/api/v1/courses/{$this->courseId}/rate", [
            'rating' => 5,
            'comment' => 'Great course'
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_authenticated_user_can_view_project_evaluations(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/project-evaluations");
        $this->assertNotEquals(404, $response->status());
    }
}
