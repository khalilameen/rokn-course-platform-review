<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseModule;
use App\Services\CourseModuleAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class CourseAttachmentDownloadTest extends ApiTestCase
{
    private CourseModule $module;
    private Attachment $attachment;
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title_ar')->nullable();
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
        });
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('title');
            $table->string('file_path');
            $table->string('storage_disk')->default('module-attachments');
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        DB::table('settings')->update(['enforce_course_section_order' => false]);
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->module = CourseModule::query()->create([
            'course_id' => $this->courseId,
            'title_ar' => 'مرفقات الوحدة الأولى',
            'order' => 1,
        ]);
        $this->attachment = Attachment::query()->create([
            'attachable_type' => CourseModule::class,
            'attachable_id' => $this->module->id,
            'title' => 'ملف التطبيق',
            'file_path' => 'course-attachments/example.pdf',
            'storage_disk' => 'module-attachments',
            'file_type' => 'pdf',
            'file_size' => 12,
        ]);

        $this->diskRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rokn-attachments-' . Str::uuid();
        File::ensureDirectoryExists($this->diskRoot);
        File::ensureDirectoryExists($this->diskRoot . DIRECTORY_SEPARATOR . 'views');
        config()->set('view.compiled', $this->diskRoot . DIRECTORY_SEPARATOR . 'views');
        config()->set('filesystems.disks.module-attachments.root', $this->diskRoot);
        Storage::forgetDisk('module-attachments');
        Storage::disk('module-attachments')->put($this->attachment->file_path, 'private file');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('course_modules');
        Storage::forgetDisk('module-attachments');
        if (isset($this->diskRoot)) {
            File::deleteDirectory($this->diskRoot);
        }
        parent::tearDown();
    }

    public function test_signed_link_downloads_without_authorization_and_rechecks_access(): void
    {
        config()->set('course_attachments.signed_url_minutes', 30);
        $course = Course::query()->findOrFail($this->courseId);
        $url = app(CourseModuleAccessService::class)->temporaryDownloadUrl(
            $this->user,
            $course,
            $this->module,
            $this->attachment
        );

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-disposition');

        DB::table('users')->where('id', $this->user->id)->update(['active' => false]);
        $this->get($url)->assertForbidden();

        DB::table('users')->where('id', $this->user->id)->update(['active' => true]);
        DB::table('course_enrollments')
            ->where('user_id', $this->user->id)
            ->where('course_id', $this->courseId)
            ->update(['is_active' => false]);

        $this->get($url)->assertForbidden();
    }

    public function test_tampered_or_expired_link_is_rejected_without_streaming(): void
    {
        $course = Course::query()->findOrFail($this->courseId);
        $valid = app(CourseModuleAccessService::class)->temporaryDownloadUrl(
            $this->user,
            $course,
            $this->module,
            $this->attachment
        );

        $tampered = $valid . '&tampered=1';
        $this->get($tampered)->assertForbidden();

        $expired = URL::temporarySignedRoute(
            'api.course-module-attachments.download',
            now()->subMinute(),
            [
                'course' => $course->getRouteKey(),
                'module' => $this->module->getRouteKey(),
                'attachment' => $this->attachment->getRouteKey(),
                'uid' => $this->user->getKey(),
            ]
        );

        $this->get($expired)->assertForbidden();
    }
}
