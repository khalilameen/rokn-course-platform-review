<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\CourseResource;
use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Setting;
use App\Models\User;
use App\Services\CoursePresentationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CourseContentSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enforce_course_section_order')->default(true);
            $table->boolean('bunny_enabled')->default(false);
            $table->string('bunny_api_key')->nullable();
            $table->string('bunny_library_id')->nullable();
            $table->string('bunny_cdn_hostname')->nullable();
            $table->text('bunny_api_key_secret')->nullable();
            $table->text('bunny_security_key_secret')->nullable();
            $table->timestamps();
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('access_granted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('order');
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->nullableMorphs('sectionable');
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('student_section_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_section_id');
            $table->boolean('is_completed')->default(false);
            $table->timestamps();
        });
        Schema::create('user_project_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->boolean('passed')->default(false);
            $table->json('evaluation_data')->nullable();
            $table->timestamps();
        });
        Schema::create('course_ratings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('lesson_media_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lesson_id')->unique();
            $table->string('provider', 32)->default('bunny');
            $table->string('provider_media_id')->nullable();
            $table->string('status', 24)->default('unknown');
            $table->string('protocol', 16)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('available_qualities')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->string('integrity_status', 24)->default('unknown');
            $table->json('integrity_issues')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();
        });
        Schema::create('lesson_watch_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('course_section_id');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('verified_seconds')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'lesson_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lesson_watch_evidence');
        Schema::dropIfExists('lesson_media_states');
        Schema::dropIfExists('course_ratings');
        Schema::dropIfExists('user_project_evaluations');
        Schema::dropIfExists('student_section_progress');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    public function test_locked_steps_keep_map_metadata_without_content_or_module_attachments(): void
    {
        config()->set('bunny.stream_api_key', 'test-stream-key');
        config()->set('bunny.library_id', '123');
        config()->set('bunny.cdn_hostname', 'video.example.test');
        config()->set('bunny.token_auth_key', 'test-token-key');

        Setting::create([
            'enforce_course_section_order' => true,
            'bunny_enabled' => true,
        ]);

        $user = new User();
        $user->forceFill(['id' => 42, 'active' => true]);
        $user->exists = true;
        auth('api')->setUser($user);

        CourseEnrollment::create([
            'user_id' => 42,
            'course_id' => 77,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ]);

        $course = new Course();
        $course->forceFill([
            'id' => 77,
            'name_ar' => 'اختبار حماية المحتوى',
            'description_ar' => 'وصف عام',
            'price' => 4000,
            'is_main_course' => true,
            'is_coming_soon' => false,
            'path_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $course->exists = true;
        $course->setRelation('classifications', collect());
        $course->setRelation('teachers', collect());
        $course->setRelation('ratings', collect());
        $course->setRelation('coursePath', null);
        $course->setRelation('photo', null);

        $openLesson = $this->lesson(11, false, 'open-guid');
        $lockedLesson = $this->lesson(12, false, 'locked-guid');
        $previewLesson = $this->lesson(13, true, 'preview-guid');

        $first = $this->section(101, 1, 201, $openLesson, 'الخطوة الأولى');
        $locked = $this->section(102, 2, 202, $lockedLesson, 'عنوان الخطوة المقفولة');
        $preview = $this->section(103, 3, 203, $previewLesson, 'معاينة مجانية');
        $sections = collect([$first, $locked, $preview]);
        $course->setRelation('sections', $sections);

        $openModule = $this->module(201, 1, $first, 'https://files.example.test/open', 301);
        $lockedModule = $this->module(202, 2, $locked, 'https://files.example.test/locked', 302);
        $previewModule = $this->module(203, 3, $preview, 'https://files.example.test/preview', 303);
        foreach ([$openModule, $lockedModule, $previewModule] as $module) {
            \Illuminate\Support\Facades\DB::table('course_modules')->insert([
                'id' => $module->id,
                'course_id' => 77,
                'order' => $module->order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $course->setRelation('modules', collect([$openModule, $lockedModule, $previewModule]));

        $payload = (new CourseResource($course))
            ->withLearningContext(
                collect(),
                [
                    'has_learning_access' => true,
                    'project_feedback_level' => 'pass_only',
                ],
                CourseEnrollment::query()->first()
            )
            ->resolve(request());

        self::assertSame('عنوان الخطوة المقفولة', $payload['sections'][1]['title']);
        self::assertTrue($payload['sections'][1]['is_locked']);
        self::assertArrayNotHasKey('content', $payload['sections'][1]);
        self::assertNull($payload['sections'][0]['content']['bunny_video_url']);

        // An explicit free preview is the only locked step allowed to carry a
        // playable URL.
        self::assertTrue($payload['sections'][2]['is_locked']);
        self::assertTrue($payload['sections'][2]['is_preview']);
        self::assertStringContainsString('preview-guid', $payload['sections'][2]['content']['bunny_video_url']);

        self::assertSame(2, $payload['modules'][1]['attachments_count']);
        self::assertTrue($payload['modules'][1]['is_locked']);
        self::assertArrayNotHasKey('attachments_link', $payload['modules'][1]);
        self::assertArrayNotHasKey('attachments', $payload['modules'][1]);
        self::assertArrayNotHasKey('content', $payload['modules'][1]['sections'][0]);

        self::assertFalse($payload['modules'][0]['is_locked']);
        self::assertSame('https://files.example.test/open', $payload['modules'][0]['attachments_link']);
        self::assertCount(1, $payload['modules'][0]['attachments']);
        self::assertArrayHasKey('download_url', $payload['modules'][0]['attachments'][0]);
        self::assertArrayNotHasKey('file_url', $payload['modules'][0]['attachments'][0]);
        self::assertStringContainsString('/attachments/301/download', $payload['modules'][0]['attachments'][0]['download_url']);
        self::assertFalse($payload['modules'][2]['is_locked']);
        self::assertSame('https://files.example.test/preview', $payload['modules'][2]['attachments_link']);
    }

    public function test_first_step_of_later_module_does_not_bypass_previous_module(): void
    {
        Setting::create(['enforce_course_section_order' => true]);
        \Illuminate\Support\Facades\DB::table('course_modules')->insert([
            ['id' => 201, 'course_id' => 77, 'order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 202, 'course_id' => 77, 'order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $first = $this->section(101, 1, 201, $this->lesson(11, false, 'first'), 'الأول');
        $laterModuleFirst = $this->section(102, 1, 202, $this->lesson(12, false, 'later'), 'التالي');

        $states = app(CoursePresentationService::class)->sectionLockStatus(
            collect([$laterModuleFirst, $first]),
            collect(),
            null
        )->keyBy('section_id');

        self::assertFalse($states[101]['is_locked']);
        self::assertTrue($states[102]['is_locked']);
        self::assertSame('previous_section_incomplete', $states[102]['lock_reason']);
    }

    public function test_every_project_in_previous_module_must_pass_before_next_module(): void
    {
        Setting::create(['enforce_course_section_order' => true]);
        $firstProject = new CourseSection();
        $firstProject->forceFill([
            'id' => 201,
            'course_id' => 77,
            'module_id' => 301,
            'section_type' => 'project',
            'sectionable_type' => \App\Models\Project::class,
            'sectionable_id' => 401,
            'order' => 1,
        ]);
        $secondProject = new CourseSection();
        $secondProject->forceFill([
            'id' => 202,
            'course_id' => 77,
            'module_id' => 301,
            'section_type' => 'project',
            'sectionable_type' => \App\Models\Project::class,
            'sectionable_id' => 402,
            'order' => 2,
        ]);
        $nextLesson = $this->section(
            203,
            1,
            302,
            $this->lesson(21, false, 'next-module'),
            'الوحدة التالية'
        );
        \Illuminate\Support\Facades\DB::table('user_project_evaluations')->insert([
            'user_id' => 42,
            'project_id' => 401,
            'passed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $states = app(CoursePresentationService::class)->sectionLockStatus(
            collect([$nextLesson, $secondProject, $firstProject]),
            collect([201, 202]),
            42
        )->keyBy('section_id');

        self::assertTrue($states[203]['is_locked']);
        self::assertSame('module_project_not_passed', $states[203]['lock_reason']);
    }

    private function lesson(int $id, bool $isPreview, string $guid): Lesson
    {
        $lesson = new Lesson();
        $lesson->forceFill([
            'id' => $id,
            'title_ar' => "درس {$id}",
            'description_ar' => "وصف {$id}",
            'is_opened' => $isPreview,
            'video_source_type' => 'bunny',
            'bunny_video_id' => $guid,
            'thumbnail_path' => "thumbnails/{$id}.jpg",
            'duration_minutes' => 2,
        ]);
        $lesson->exists = true;
        $mediaState = new LessonMediaState();
        $mediaState->forceFill([
            'lesson_id' => $id,
            'provider_media_id' => $guid,
            'status' => 'ready',
            'integrity_status' => 'verified',
            'duration_seconds' => 120,
        ]);
        $mediaState->exists = true;
        $lesson->setRelation('mediaState', $mediaState);

        return $lesson;
    }

    private function section(
        int $id,
        int $order,
        int $moduleId,
        Lesson $lesson,
        string $title
    ): CourseSection {
        $section = new CourseSection();
        $section->forceFill([
            'id' => $id,
            'course_id' => 77,
            'module_id' => $moduleId,
            'title_ar' => $title,
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => $order,
        ]);
        $section->exists = true;
        $section->setRelation('sectionable', $lesson);
        $section->setRelation('attachments', collect());

        return $section;
    }

    private function module(
        int $id,
        int $order,
        CourseSection $section,
        string $attachmentsLink,
        int $attachmentId
    ): CourseModule {
        $module = new CourseModule();
        $module->forceFill([
            'id' => $id,
            'course_id' => 77,
            'title_ar' => "وحدة {$id}",
            'description_ar' => "وصف الوحدة {$id}",
            'attachments_link' => $attachmentsLink,
            'attachment_platform' => 'mobile',
            'order' => $order,
        ]);
        $module->exists = true;
        $module->setRelation('sections', collect([$section]));

        $attachment = new Attachment();
        $attachment->forceFill([
            'id' => $attachmentId,
            'title' => "ملف {$attachmentId}",
            'file_path' => "private/{$attachmentId}.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 1024,
        ]);
        $attachment->exists = true;
        $module->setRelation('attachments', collect([$attachment]));

        return $module;
    }
}
