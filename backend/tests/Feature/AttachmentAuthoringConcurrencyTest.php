<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AttachmentController;
use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AttachmentAuthoringConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->boolean('is_coming_soon')->default(true);
            $table->unsignedBigInteger('authoring_version')->default(1);
            $table->timestamps();
        });
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->timestamps();
        });
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->morphs('attachable');
            $table->string('title');
            $table->string('file_path');
            $table->string('storage_disk')->default('module-attachments');
            $table->string('file_type')->nullable();
            $table->string('mime_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        config()->set('course_attachments.allowed_types', [
            'txt' => ['text/plain'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('courses');
        parent::tearDown();
    }

    public function test_stale_duplicate_upload_cannot_adopt_the_current_authoring_version(): void
    {
        $course = new Course();
        $course->forceFill([
            'name_ar' => 'كورس',
            'is_coming_soon' => true,
            'authoring_version' => 2,
        ])->save();
        $module = CourseModule::query()->create(['course_id' => $course->id]);
        $content = 'existing attachment';
        Attachment::query()->create([
            'attachable_type' => CourseModule::class,
            'attachable_id' => $module->id,
            'title' => 'ملف',
            'file_path' => 'attachments/existing.txt',
            'storage_disk' => 'module-attachments',
            'file_type' => 'txt',
            'mime_type' => 'text/plain',
            'file_size' => strlen($content),
            'content_sha256' => hash('sha256', $content),
            'order' => 1,
        ]);

        $request = Request::create('/dashboard/attachments', 'POST', [
            'attachable_type' => 'course_module',
            'attachable_id' => $module->id,
            // This form rendered version one, before another editor advanced it.
            'authoring_version' => 1,
        ]);
        $request->files->set(
            'file',
            UploadedFile::fake()->createWithContent('existing.txt', $content)
        );

        try {
            app(AttachmentController::class)->store($request);
            self::fail('A stale duplicate upload must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(409, $exception->status);
            self::assertArrayHasKey('authoring_version', $exception->errors());
        }

        self::assertSame(2, (int) $course->fresh()->authoring_version);
        self::assertSame(1, Attachment::query()->count());
    }
}
