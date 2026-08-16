<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\API\CoursePdfController;
use App\Http\Controllers\Admin\CoursePdfController as AdminCoursePdfController;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CoursePdfSharedStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->timestamps();
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_pdfs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->string('file_path');
            $table->string('storage_disk')->nullable();
            $table->string('original_filename')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('courses')->insert(['id' => 7, 'name_ar' => 'اختبار', 'created_at' => now(), 'updated_at' => now()]);
        Storage::fake('course-pdfs-shared');
        config([
            'course_pdfs.disk' => 'course-pdfs-shared',
            'course_pdfs.shared_storage' => true,
            'filesystems.disks.course-pdfs-shared' => [
                'driver' => 'local',
                'root' => sys_get_temp_dir() . '/course-pdfs-shared',
                'visibility' => 'private',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('course_pdfs');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('courses');
        parent::tearDown();
    }

    public function test_entitled_pdf_stream_reads_shared_disk_and_supports_bounded_ranges(): void
    {
        $pdf = CoursePdf::create([
            'course_id' => 7,
            'title' => 'ملف',
            'file_path' => 'courses/7/example.pdf',
            'storage_disk' => 'course-pdfs-shared',
            'file_size' => 10,
            'is_active' => true,
        ]);
        Storage::disk('course-pdfs-shared')->put($pdf->file_path, '0123456789');
        DB::table('course_enrollments')->insert([
            'user_id' => 42,
            'course_id' => 7,
            'is_active' => true,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->authenticate(42);

        $request = Request::create('/stream', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=2-5']);
        $response = app(CoursePdfController::class)->stream($request, 7, $pdf->id);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 2-5/10', $response->headers->get('Content-Range'));
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        self::assertSame('2345', $content);
    }

    public function test_expired_enrollment_cannot_read_pdf(): void
    {
        $pdf = CoursePdf::create([
            'course_id' => 7,
            'title' => 'ملف',
            'file_path' => 'courses/7/example.pdf',
            'storage_disk' => 'course-pdfs-shared',
            'file_size' => 4,
            'is_active' => true,
        ]);
        Storage::disk('course-pdfs-shared')->put($pdf->file_path, '%PDF');
        DB::table('course_enrollments')->insert([
            'user_id' => 42,
            'course_id' => 7,
            'is_active' => true,
            'expires_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->authenticate(42);

        $response = app(CoursePdfController::class)->stream(Request::create('/stream'), 7, $pdf->id);
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_migration_gives_duplicate_legacy_references_distinct_verified_keys(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('course-pdfs/7/legacy.pdf', '%PDF-legacy');
        CoursePdf::create([
            'course_id' => 7,
            'title' => 'الأول',
            'file_path' => 'course-pdfs/7/legacy.pdf',
            'storage_disk' => null,
            'file_size' => 11,
        ]);
        CoursePdf::create([
            'course_id' => 7,
            'title' => 'الثاني',
            'file_path' => 'course-pdfs/7/legacy.pdf',
            'storage_disk' => 'local',
            'file_size' => 11,
        ]);

        $status = Artisan::call('course-pdfs:migrate-storage', ['--execute' => true]);
        self::assertSame(0, $status, Artisan::output());
        $rows = CoursePdf::query()->orderBy('id')->get();
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]->file_path, $rows[1]->file_path);
        foreach ($rows as $row) {
            self::assertSame('course-pdfs-shared', $row->storage_disk);
            Storage::disk('course-pdfs-shared')->assertExists($row->file_path);
            self::assertSame('%PDF-legacy', Storage::disk('course-pdfs-shared')->get($row->file_path));
        }
    }

    public function test_admin_upload_persists_configured_disk_and_server_generated_unique_keys(): void
    {
        $course = Course::query()->findOrFail(7);

        foreach (['first', 'second'] as $title) {
            $request = Request::create('/admin/course-pdf', 'POST', [
                'title' => $title,
                'is_active' => true,
            ]);
            $request->files->set(
                'pdf_file',
                UploadedFile::fake()->create('same-original-name.pdf', 1, 'application/pdf')
            );

            $response = app(AdminCoursePdfController::class)->store($request, $course);
            self::assertTrue($response->isRedirect());
        }

        $rows = CoursePdf::query()->orderBy('id')->get();
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]->file_path, $rows[1]->file_path);
        foreach ($rows as $row) {
            self::assertSame('course-pdfs-shared', $row->storage_disk);
            self::assertMatchesRegularExpression('~^courses/7/[0-9a-f-]{36}\.pdf$~', $row->file_path);
            self::assertStringNotContainsString('same-original-name', $row->file_path);
            Storage::disk('course-pdfs-shared')->assertExists($row->file_path);
        }
    }

    private function authenticate(int $id): void
    {
        $user = new User();
        $user->forceFill(['id' => $id, 'active' => true]);
        $user->exists = true;
        auth('api')->setUser($user);
    }
}
