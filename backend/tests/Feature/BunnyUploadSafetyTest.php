<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Setting;
use App\Services\BunnyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BunnyUploadSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('bunny_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->string('video_source_type')->nullable();
            $table->string('video_link')->nullable();
            $table->string('bunny_video_id')->nullable();
            $table->timestamps();
        });
        Schema::create('lesson_media_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lesson_id')->unique();
            $table->string('provider');
            $table->string('provider_media_id');
            $table->string('status');
            $table->string('protocol')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('available_qualities')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->string('integrity_status')->default('unknown');
            $table->json('integrity_issues')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->unsignedBigInteger('probe_generation')->default(0);
            $table->timestamps();
        });
        Schema::create('bunny_video_cleanup_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('video_guid')->unique();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->string('reason');
            $table->timestamp('eligible_after');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('remote_deleted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('requires_review')->default(true);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_09_01_000069_create_bunny_video_allocation_intents.php'))->up();
        (require database_path('migrations/2026_09_01_000034_create_bunny_storage_cleanup_candidates.php'))->up();
        (require database_path('migrations/2026_09_01_000051_quarantine_bunny_storage_cleanup.php'))->up();

        config()->set('bunny.stream_api_key', 'test-stream-key');
        config()->set('bunny.library_id', '123');
        Setting::create(['bunny_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bunny_video_allocation_intents');
        Schema::dropIfExists('bunny_storage_cleanup_candidates');
        Schema::dropIfExists('bunny_video_cleanup_candidates');
        Schema::dropIfExists('lesson_media_states');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('settings');
        parent::tearDown();
    }

    public function test_verified_replacement_is_published_without_deleting_the_previous_video(): void
    {
        $lesson = Lesson::create([
            'title_ar' => 'درس اختباري',
            'video_source_type' => 'bunny',
            'bunny_video_id' => 'old-guid',
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['guid' => 'new-guid', 'title' => 'new'], 201);
            }
            if ($request->method() === 'PUT') {
                return Http::response([], 200);
            }
            if ($request->method() === 'GET') {
                return Http::response([
                    'guid' => 'new-guid',
                    'videoLibraryId' => '123',
                ], 200);
            }

            self::fail('Replacement must never delete a remote video inline.');
        });

        $result = app(BunnyService::class)->replaceLessonVideo(
            $lesson,
            UploadedFile::fake()->create('lesson.mp4', 4, 'video/mp4')
        );

        self::assertTrue($result);
        self::assertSame('new-guid', $lesson->fresh()->bunny_video_id);
        self::assertSame('bunny', $lesson->fresh()->video_source_type);
        $this->assertDatabaseHas('bunny_video_cleanup_candidates', [
            'video_guid' => 'old-guid',
            'reason' => 'superseded_video',
            'requires_review' => true,
        ]);
        // One marker-recovery probe precedes allocation, followed by create,
        // upload and provider-integrity verification.
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_failed_verification_never_changes_the_database_pointer_or_reports_success(): void
    {
        $lesson = Lesson::create([
            'title_ar' => 'درس اختباري',
            'video_source_type' => 'bunny',
            'bunny_video_id' => 'old-guid',
        ]);

        Http::fake(function (Request $request) {
            return match ($request->method()) {
                'POST' => Http::response(['guid' => 'candidate-guid', 'title' => 'candidate'], 201),
                'PUT' => Http::response([], 200),
                'GET' => Http::response([], 503),
                default => Http::response([], 500),
            };
        });

        $result = app(BunnyService::class)->replaceLessonVideo(
            $lesson,
            UploadedFile::fake()->create('lesson.mp4', 4, 'video/mp4')
        );

        self::assertFalse($result);
        self::assertSame('old-guid', $lesson->fresh()->bunny_video_id);
        $this->assertDatabaseHas('bunny_video_cleanup_candidates', [
            'video_guid' => 'candidate-guid',
            'reason' => 'unpublished_upload',
            'requires_review' => false,
        ]);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_video_upload_uses_a_file_stream_instead_of_reading_the_whole_file(): void
    {
        $source = file_get_contents(app_path('Services/BunnyService.php'));

        self::assertStringContainsString("fopen(\$file->getRealPath(), 'rb')", $source);
        self::assertStringNotContainsString(
            'file_get_contents($file->getRealPath())',
            $source
        );
    }

    public function test_storage_uploads_use_unique_server_names_and_mime_extensions(): void
    {
        config()->set('bunny.storage_zone', 'test-zone');
        config()->set('bunny.storage_password', 'test-password');
        Http::fake(['storage.bunnycdn.com/*' => Http::response([], 201)]);

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            true
        );
        self::assertIsString($png);
        $service = app(BunnyService::class);
        $first = $service->uploadFileToStorage(
            UploadedFile::fake()->createWithContent('student portrait.png', $png),
            'portfolio'
        );
        $second = $service->uploadFileToStorage(
            UploadedFile::fake()->createWithContent('student portrait.png', $png),
            'portfolio'
        );

        self::assertIsString($first);
        self::assertIsString($second);
        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression(
            '#^portfolio/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\.png$#',
            $first
        );
        self::assertStringNotContainsString('student', $first);
        self::assertStringNotContainsString('portrait', $first);

        $urls = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
        self::assertCount(2, $urls);
        self::assertSame(2, $urls->unique()->count());
    }
}
