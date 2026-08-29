<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests covering Educational Path API endpoints:
 * listing available learning paths, viewing path details, and user enrolled paths.
 */
class PathEndpointTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('badge_image')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedBigInteger('path_id')->nullable();
            $table->unsignedBigInteger('level_id')->nullable();
        });

        Schema::create('classification_path', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('path_id');
            $table->unsignedBigInteger('classification_id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('classification_path');
        Schema::dropIfExists('levels');

        parent::tearDown();
    }

    public function test_can_list_paths(): void
    {
        $levelId = DB::table('levels')->insertGetId([
            'name_ar' => 'مبتدئ',
            'name_en' => 'Beginner',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('courses')->where('id', $this->courseId)->update([
            'path_id' => $this->pathId,
            'level_id' => $levelId,
        ]);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/paths')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Paths retrieved successfully')
            ->assertJsonPath('data.0.title', 'Test Path')
            ->assertJsonPath('data.0.levels.0.id', $levelId)
            ->assertJsonPath('data.0.levels.0.name_en', 'Beginner');
    }

    public function test_can_view_path_details(): void
    {
        $this->getJson("/api/v1/paths/{$this->pathId}")
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Path retrieved successfully')
            ->assertJsonPath('data.id', $this->pathId);
    }

    public function test_authenticated_user_can_view_user_paths(): void
    {
        $currentLevelId = DB::table('levels')->insertGetId([
            'name_ar' => 'مبتدئ',
            'name_en' => 'Junior',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nextLevelId = DB::table('levels')->insertGetId([
            'name_ar' => 'متوسط',
            'name_en' => 'Mid-level',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('courses')->where('id', $this->courseId)->update([
            'path_id' => $this->pathId,
            'level_id' => $currentLevelId,
        ]);
        DB::table('courses')->insert([
            'name_ar' => 'كورس المستوى التالي',
            'name_en' => 'Next level course',
            'grade_id' => $this->gradeId,
            'path_id' => $this->pathId,
            'level_id' => $nextLevelId,
            'price' => 100,
            'active' => true,
            'course_type' => 'online',
            'rate' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondSectionId = DB::table('course_sections')->insertGetId([
            'course_id' => $this->courseId,
            'title_ar' => 'قسم 2',
            'title_en' => 'Section 2',
            'sort_order' => 2,
            'is_free' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('student_section_progress')->insert([
            'user_id' => $this->user->id,
            'course_section_id' => $secondSectionId,
            'is_completed' => true,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/user/paths')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User paths retrieved successfully')
            ->assertJsonPath('data.0.current_level.id', $currentLevelId);

        self::assertSame(
            $nextLevelId,
            $response->json('data.0.next_level.id'),
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );
        self::assertSame($nextLevelId, $response->json('data.0.levels.0.id'));
        self::assertSame(50, $response->json('data.0.required_progress_percentage'));

        self::assertNotContains(
            $currentLevelId,
            collect($response->json('data.0.levels'))->pluck('id')->all()
        );
    }
}
