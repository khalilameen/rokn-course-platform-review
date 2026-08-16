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
use App\Models\Setting;
use App\Models\User;
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('course_ratings');
        Schema::dropIfExists('user_project_evaluations');
        Schema::dropIfExists('student_section_progress');
        Schema::dropIfExists('course_enrollments');
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

        $openModule = $this->module(201, 1, $first, 'private/open', 301);
        $lockedModule = $this->module(202, 2, $locked, 'private/locked', 302);
        $previewModule = $this->module(203, 3, $preview, 'private/preview', 303);
        $course->setRelation('modules', collect([$openModule, $lockedModule, $previewModule]));

        $payload = (new CourseResource($course))->resolve(request());

        self::assertSame('عنوان الخطوة المقفولة', $payload['sections'][1]['title']);
        self::assertTrue($payload['sections'][1]['is_locked']);
        self::assertArrayNotHasKey('content', $payload['sections'][1]);
        self::assertStringContainsString('open-guid', $payload['sections'][0]['content']['bunny_video_url']);

        // An explicit free preview is the only locked step allowed to carry a
        // playable URL.
        self::assertTrue($payload['sections'][2]['is_locked']);
        self::assertTrue($payload['sections'][2]['is_preview']);
        self::assertStringContainsString('preview-guid', $payload['sections'][2]['content']['bunny_video_url']);

        self::assertSame(1, $payload['modules'][1]['attachments_count']);
        self::assertTrue($payload['modules'][1]['is_locked']);
        self::assertArrayNotHasKey('attachments_link', $payload['modules'][1]);
        self::assertArrayNotHasKey('attachments', $payload['modules'][1]);
        self::assertArrayNotHasKey('content', $payload['modules'][1]['sections'][0]);

        self::assertFalse($payload['modules'][0]['is_locked']);
        self::assertSame('private/open', $payload['modules'][0]['attachments_link']);
        self::assertCount(1, $payload['modules'][0]['attachments']);
        self::assertArrayHasKey('download_url', $payload['modules'][0]['attachments'][0]);
        self::assertArrayNotHasKey('file_url', $payload['modules'][0]['attachments'][0]);
        self::assertStringContainsString('/attachments/301/download', $payload['modules'][0]['attachments'][0]['download_url']);
        self::assertFalse($payload['modules'][2]['is_locked']);
        self::assertSame('private/preview', $payload['modules'][2]['attachments_link']);
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
