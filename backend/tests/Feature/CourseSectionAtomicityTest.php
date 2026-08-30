<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CourseSectionController;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Services\BunnyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class CourseSectionAtomicityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->timestamps();
        });
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->timestamps();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('video_link')->nullable();
            $table->string('video_source_type');
            $table->string('bunny_video_id')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('file_link1')->nullable();
            $table->string('file_link2')->nullable();
            $table->unsignedBigInteger('list_id');
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_opened')->default(false);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamps();
        });
        Schema::create('lesson_media_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lesson_id')->unique();
            $table->string('provider');
            $table->string('provider_media_id');
            $table->string('status');
            $table->string('protocol')->nullable();
            $table->json('available_qualities')->nullable();
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('title_ar');
            // Non-null on purpose: one test forces the section insert to fail
            // after the lesson insert, proving that both roll back together.
            $table->string('title_en');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('section_type');
            $table->string('sectionable_type');
            $table->unsignedBigInteger('sectionable_id');
            $table->unsignedInteger('order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('lesson_media_states');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('courses');
        parent::tearDown();
    }

    public function test_failed_section_insert_rolls_back_lesson_and_queues_staged_video(): void
    {
        $course = Course::create(['name_ar' => 'اختبار']);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('uploadVerifiedVideo')->once()->andReturn('staged-guid');
        $bunny->shouldReceive('queueVideoCleanup')
            ->once()
            ->with('staged-guid', null, 'section_create_rollback', 24)
            ->andReturnNull();
        app()->instance(BunnyService::class, $bunny);

        $response = app(CourseSectionController::class)->store(
            $this->lessonRequest(null),
            $course
        );

        self::assertSame(0, \App\Models\Lesson::query()->count());
        self::assertSame(0, \App\Models\CourseSection::query()->count());
        self::assertTrue($response->isRedirect());
    }

    public function test_verified_video_and_section_pointer_commit_together(): void
    {
        $course = Course::create(['name_ar' => 'اختبار']);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('uploadVerifiedVideo')->once()->andReturn('published-guid');
        $bunny->shouldNotReceive('queueVideoCleanup');
        app()->instance(BunnyService::class, $bunny);

        $response = app(CourseSectionController::class)->store(
            $this->lessonRequest('Lesson'),
            $course
        );

        $this->assertDatabaseHas('lessons', ['bunny_video_id' => 'published-guid']);
        $this->assertDatabaseHas('course_sections', [
            'course_id' => $course->id,
            'section_type' => 'lesson',
        ]);
        self::assertTrue($response->isRedirect());
    }

    public function test_reorder_keeps_the_optional_crossing_project_last_in_its_module(): void
    {
        $course = Course::create(['name_ar' => 'اختبار']);
        $module = CourseModule::create(['course_id' => $course->id]);
        $lesson = CourseSection::create([
            'title_ar' => 'مقطع',
            'title_en' => 'Clip',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => 10,
            'order' => 1,
        ]);
        $project = CourseSection::create([
            'title_ar' => 'مشروع عبور',
            'title_en' => 'Crossing project',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => 20,
            'order' => 2,
        ]);
        $request = Request::create('/dashboard/courses/1/sections/reorder', 'POST', [
            'sections' => [
                ['id' => $project->id, 'order' => 1, 'module_id' => $module->id],
                ['id' => $lesson->id, 'order' => 2, 'module_id' => $module->id],
            ],
        ]);

        $response = app(CourseSectionController::class)->reorder($request, $course);

        self::assertTrue($response->getData(true)['success']);
        self::assertSame(1, (int) $lesson->fresh()->order);
        self::assertSame(2, (int) $project->fresh()->order);
    }

    private function lessonRequest(?string $sectionTitleEn): Request
    {
        $request = Request::create('/dashboard/courses/1/sections', 'POST', [
            'title_ar' => 'خطوة تجريبية',
            'title_en' => $sectionTitleEn,
            'section_type' => 'lesson',
            'lesson_title_ar' => 'خطوة تجريبية',
            'lesson_title_en' => 'Test step',
            'lesson_duration_minutes' => 2,
        ]);
        $request->files->set(
            'bunny_video',
            UploadedFile::fake()->create('lesson.mp4', 4, 'video/mp4')
        );
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }
}
