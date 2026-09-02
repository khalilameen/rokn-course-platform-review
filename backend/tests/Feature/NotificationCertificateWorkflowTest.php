<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\CourseCompleted;
use App\Http\Controllers\Admin\NotificationsController;
use App\Jobs\DeliverStudentNotificationChunk;
use App\Jobs\SendStudentNotification;
use App\Jobs\SendUserPushNotification;
use App\Listeners\GenerateCourseCertificate;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CoursePublishingService;
use App\Services\NotificationService;
use App\Services\PublicPortfolioService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class NotificationCertificateWorkflowTest extends TestCase
{
    /** @var list<string> */
    private array $tables = [
        'classification_course', 'course_teacher',
        'photos',
        'account_file_deletions',
        'user_device_tokens', 'student_notifications', 'user_project_evaluations',
        'portfolio_media', 'portfolio_items', 'user_level', 'levels',
        'project_submissions', 'projects', 'student_section_progress', 'course_sections', 'certificates', 'course_enrollments',
        'courses', 'users',
    ];

    private ?string $certificateDiskRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        Log::spy();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->certificateDiskRoot) {
            File::deleteDirectory($this->certificateDiskRoot);
        }
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_large_broadcast_is_split_into_bounded_queue_jobs(): void
    {
        $now = now();
        $rows = [];
        for ($index = 1; $index <= 1001; $index++) {
            $rows[] = [
                'name' => 'Student ' . $index,
                'email' => 'student-' . $index . '@example.com',
                'role' => 'client',
                'active' => true,
                'notifications_status' => true,
                'marketing_notifications_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }
        Queue::fake([DeliverStudentNotificationChunk::class]);

        $job = new SendStudentNotification(
            'admin_broadcast',
            [],
            null,
            null,
            'عنوان',
            'Title',
            'رسالة',
            'Message',
            null,
            [],
            'broadcast:test:1001'
        );
        $job->handle();

        Queue::assertPushed(DeliverStudentNotificationChunk::class, 3);
        $queued = Queue::pushed(DeliverStudentNotificationChunk::class);
        foreach ($queued as $queuedJob) {
            $reflection = new \ReflectionProperty($queuedJob, 'userIds');
            $reflection->setAccessible(true);
            self::assertLessThanOrEqual(500, count($reflection->getValue($queuedJob)));
        }
    }

    public function test_course_notification_service_queues_a_selector_instead_of_enrollment_ids(): void
    {
        $this->allowPublishedCourseNotification();
        $course = $this->course();
        $student = $this->user('service-selector@example.com');
        DB::table('course_enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Queue::fake([SendStudentNotification::class]);

        NotificationService::notifyCourseUpdate($course);

        Queue::assertPushed(SendStudentNotification::class, function (SendStudentNotification $job) use ($course): bool {
            return $this->jobProperty($job, 'userIds') === []
                && $this->jobProperty($job, 'courseId') === (int) $course->id
                && $this->jobProperty($job, 'audience') === SendStudentNotification::AUDIENCE_ENROLLED;
        });
    }

    public function test_admin_course_broadcast_queues_audience_selector_without_materializing_ids(): void
    {
        $this->allowPublishedCourseNotification();
        $course = $this->course();
        $student = $this->user('admin-selector@example.com');
        DB::table('course_enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Queue::fake([SendStudentNotification::class]);
        $request = Request::create('/admin/notifications', 'POST', [
            'title_ar' => 'جرّب الكورس',
            'message_ar' => 'ابدأ أول خطوة الآن',
            'course_id' => $course->id,
            'audience' => 'not_enrolled',
            'authoring_request_id' => (string) Str::uuid(),
        ]);

        app(NotificationsController::class)->store($request);

        Queue::assertPushed(SendStudentNotification::class, function (SendStudentNotification $job) use ($course): bool {
            return $this->jobProperty($job, 'userIds') === []
                && $this->jobProperty($job, 'excludeUserIds') === []
                && $this->jobProperty($job, 'courseId') === (int) $course->id
                && $this->jobProperty($job, 'audience') === SendStudentNotification::AUDIENCE_NOT_ENROLLED;
        });
    }

    public function test_worker_resolves_course_audiences_with_chunked_queries(): void
    {
        $course = $this->course();
        $enrolled = $this->user('enrolled-selector@example.com');
        $notEnrolled = $this->user('not-enrolled-selector@example.com');
        DB::table('course_enrollments')->insert([
            'user_id' => $enrolled->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake([DeliverStudentNotificationChunk::class]);
        $enrolledJob = new SendStudentNotification(
            'course_update',
            [],
            Course::class,
            $course->id,
            'تحديث',
            'Update',
            'المحتوى جاهز',
            'Content is ready',
            null,
            [],
            'course-selector:enrolled',
            $course->id,
            SendStudentNotification::AUDIENCE_ENROLLED
        );
        $enrolledJob->handle();

        Queue::assertPushed(DeliverStudentNotificationChunk::class, function (DeliverStudentNotificationChunk $job) use ($enrolled): bool {
            return $this->jobProperty($job, 'userIds') === [(int) $enrolled->id];
        });

        Queue::fake([DeliverStudentNotificationChunk::class]);
        $notEnrolledJob = new SendStudentNotification(
            'course_promotion',
            [],
            Course::class,
            $course->id,
            'جرّب الكورس',
            'Try the course',
            'ابدأ الآن',
            'Start now',
            null,
            [],
            'course-selector:not-enrolled',
            $course->id,
            SendStudentNotification::AUDIENCE_NOT_ENROLLED
        );
        $notEnrolledJob->handle();

        Queue::assertPushed(DeliverStudentNotificationChunk::class, function (DeliverStudentNotificationChunk $job) use ($notEnrolled): bool {
            return $this->jobProperty($job, 'userIds') === [(int) $notEnrolled->id];
        });
    }

    public function test_explicit_audience_is_bounded_and_failure_logs_only_counts(): void
    {
        $job = new SendStudentNotification(
            'account_notice',
            [37, 42],
            null,
            null,
            'تنبيه',
            'Notice',
            'راجع حسابك',
            'Review your account'
        );

        $job->failed(new \RuntimeException('test failure'));

        Log::shouldHaveReceived('error')->with(
            'SendStudentNotification job failed',
            Mockery::on(static function (array $context): bool {
                return ($context['explicit_user_ids_count'] ?? null) === 2
                    && !array_key_exists('user_ids', $context);
            })
        )->once();

        $this->expectException(\InvalidArgumentException::class);
        new SendStudentNotification(
            'account_notice',
            range(1, SendStudentNotification::MAX_EXPLICIT_USER_IDS + 1),
            null,
            null,
            'تنبيه',
            'Notice',
            'راجع حسابك',
            'Review your account'
        );
    }

    public function test_chunk_retry_creates_one_inbox_row_per_user_and_delivery_key(): void
    {
        $first = $this->user('first@example.com');
        $second = $this->user('second@example.com');
        Queue::fake([SendUserPushNotification::class]);

        $job = new DeliverStudentNotificationChunk(
            [$first->id, $second->id],
            'broadcast:retry-safe',
            'admin_broadcast',
            null,
            null,
            'عنوان',
            'Title',
            'رسالة',
            'Message'
        );
        $job->handle();
        $job->handle();

        self::assertSame(2, StudentNotification::query()->count());
        self::assertSame(2, StudentNotification::query()
            ->where('delivery_key', 'broadcast:retry-safe')
            ->distinct('user_id')
            ->count('user_id'));
    }

    public function test_push_job_claim_is_at_most_once_across_retries(): void
    {
        $user = $this->user('push@example.com');
        $notification = StudentNotification::query()->create([
            'user_id' => $user->id,
            'delivery_key' => 'push:once',
            'notification_type' => 'admin_message',
            'title_ar' => 'عنوان',
            'title_en' => 'Title',
            'message_ar' => 'رسالة',
            'message_en' => 'Message',
            'is_read' => false,
        ]);

        Carbon::setTestNow('2026-08-07 10:00:00');
        (new SendUserPushNotification($notification->id))->handle(
            app(\App\Services\StudentNotificationPresentationService::class)
        );
        $firstAttempt = $notification->fresh()->push_attempted_at;
        Carbon::setTestNow('2026-08-07 11:00:00');
        (new SendUserPushNotification($notification->id))->handle(
            app(\App\Services\StudentNotificationPresentationService::class)
        );

        self::assertNotNull($firstAttempt);
        self::assertTrue($firstAttempt->equalTo($notification->fresh()->push_attempted_at));
    }

    public function test_stalled_push_claim_is_quarantined_without_duplicate_delivery(): void
    {
        Queue::fake([SendUserPushNotification::class]);
        Carbon::setTestNow('2026-08-31 12:00:00');
        $user = $this->user('stalled-push@example.com');
        $user->forceFill(['notifications_status' => true, 'active' => true])->save();
        DB::table('user_device_tokens')->insert([
            'user_id' => $user->id,
            'device_token' => 'stalled-push-token',
            'device_type' => 'android',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $notification = StudentNotification::query()->create([
            'user_id' => $user->id,
            'delivery_key' => 'push:stalled',
            'notification_type' => 'service_notice',
            'title_ar' => 'عنوان',
            'title_en' => 'Title',
            'message_ar' => 'رسالة',
            'message_en' => 'Message',
            'is_read' => false,
            'push_attempted_at' => now()->subMinutes(20),
        ]);

        $this->artisan('notifications:retry-stalled')->assertExitCode(0);

        $notification->refresh();
        self::assertNotNull($notification->push_attempted_at);
        self::assertNotNull($notification->push_failed_at);
        self::assertSame('delivery_unknown_after_worker_loss', $notification->push_failure_code);
        Queue::assertNotPushed(SendUserPushNotification::class);
    }

    public function test_certificate_listener_rethrows_null_generation_for_queue_retry(): void
    {
        $user = $this->user('graduate@example.com');
        $course = $this->course();
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = DB::table('course_sections')->insertGetId([
            'course_id' => $course->id,
            'section_type' => 'lesson',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('student_section_progress')->insert(
            DB::table('course_sections')
                ->where('course_id', $course->id)
                ->pluck('id')
                ->map(fn ($courseSectionId): array => [
                    'user_id' => $user->id,
                    'course_section_id' => (int) $courseSectionId,
                    'is_completed' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all()
        );
        Certificate::query()->create([
            'public_id' => '99999999-2222-4333-8444-555555555555',
            'user_id' => $user->id,
            'course_id' => $course->id,
            'holder_name' => 'Graduate Student',
            'course_name' => (string) ($course->name_ar ?: $course->name_en),
            'image_path' => 'pending',
            'generated_at' => now(),
            'status' => 'active',
        ]);

        $service = Mockery::mock(CertificateService::class);
        $service->shouldReceive('generate')->once()->andReturnNull();
        $this->app->instance(CertificateService::class, $service);

        // A real queue worker must receive the failure so its retry/backoff
        // policy runs. The sync driver intentionally swallows it to avoid
        // turning a successfully completed lesson into an HTTP 500 response.
        config()->set('queue.default', 'database');
        $this->expectException(\RuntimeException::class);
        app(GenerateCourseCertificate::class)->handle(new CourseCompleted($user, $course));
    }

    public function test_pending_certificate_is_recovered_to_configured_shared_disk(): void
    {
        $this->certificateDiskRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rokn-certificate-' . uniqid('', true);
        File::ensureDirectoryExists($this->certificateDiskRoot);
        config()->set('filesystems.disks.certificate-test', [
            'driver' => 'local',
            'root' => $this->certificateDiskRoot,
            'url' => 'https://cdn.example.test/certificates',
            'visibility' => 'public',
        ]);
        Storage::forgetDisk('certificate-test');
        config()->set('certificate.disk', 'certificate-test');
        config()->set('certificate.font_regular', resource_path('fonts/Cairo.ttf'));
        config()->set('certificate.font_bold', resource_path('fonts/Cairo.ttf'));

        $user = $this->user('certificate@example.com');
        $user->forceFill(['portfolio_slug' => 'certificate-student'])->save();
        $course = $this->course();
        $course->forceFill(['certificate_text_template_key' => 'projects'])->save();
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pending = Certificate::query()->create([
            'public_id' => '11111111-2222-4333-8444-555555555555',
            'user_id' => $user->id,
            'course_id' => $course->id,
            // Simulate a row left half-written by an older rolling worker.
            // Recovery must repair the key/text pair from one course snapshot.
            'certificate_text_template_key' => 'knowledge',
            'certificate_text' => null,
            'image_path' => 'pending',
            'generated_at' => now(),
            'status' => 'active',
        ]);

        $certificate = app(CertificateService::class)->generate($user, $course);

        self::assertNotNull($certificate);
        self::assertSame($pending->id, $certificate->id);
        self::assertSame('projects', $certificate->certificate_text_template_key);
        self::assertSame(
            'تقديرًا لإنجاز مشروعات كورس',
            $certificate->certificate_text
        );
        self::assertNotSame('pending', $certificate->image_path);
        Storage::disk('certificate-test')->assertExists($certificate->image_path);
        self::assertStringContainsString(
            '/c/'.$certificate->public_id,
            $certificate->portfolio_url
        );

        $verification = app(PublicPortfolioService::class)->find(
            'certificate-student',
            (string) $certificate->public_id
        );
        self::assertNotNull($verification);
        self::assertFalse($verification['is_limited_certificate_view']);
        self::assertSame(
            $certificate->public_id,
            $verification['highlighted_certificate']['public_id']
        );
        self::assertSame('active', $verification['highlighted_certificate']['status']);

        $firstArtifact = Storage::disk('certificate-test')->get($certificate->image_path);
        Storage::disk('certificate-test')->delete($certificate->image_path);
        $course->forceFill(['certificate_text_template_key' => 'applied'])->save();
        config()->set(
            'certificate.text_templates.projects.text',
            'نص حي جديد لا يجوز أن يغير شهادة صادرة'
        );

        $recovered = app(CertificateService::class)->generate($user, $course);

        self::assertNotNull($recovered);
        self::assertSame('projects', $recovered->certificate_text_template_key);
        self::assertSame(
            'تقديرًا لإنجاز مشروعات كورس',
            $recovered->certificate_text
        );
        self::assertSame(
            hash('sha256', $firstArtifact),
            hash(
                'sha256',
                Storage::disk('certificate-test')->get($recovered->image_path)
            )
        );
    }

    private function user(string $email): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Student',
            'email' => $email,
            'role' => 'client',
            'active' => true,
            'notifications_status' => true,
            'marketing_notifications_enabled' => true,
            'portfolio_slug' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function course(): Course
    {
        $id = DB::table('courses')->insertGetId([
            'name_ar' => 'كورس تجريبي',
            'name_en' => 'Test course',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('course_sections')->insert([
            'course_id' => $id,
            'section_type' => 'lesson',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Course::query()->findOrFail($id);
    }

    private function jobProperty(object $job, string $name): mixed
    {
        $property = new \ReflectionProperty($job, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    private function allowPublishedCourseNotification(): void
    {
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => []]);
        $this->app->instance(CoursePublishingService::class, $publishing);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('client');
            $table->boolean('active')->default(true);
            $table->boolean('notifications_status')->default(true);
            $table->boolean('marketing_notifications_enabled')->default(true);
            $table->string('portfolio_slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->boolean('awards_badge')->default(false);
            $table->string('certificate_text_template_key', 32)->default('completion');
            $table->boolean('is_coming_soon')->default(false);
            $table->boolean('is_catalog_visible')->default(true);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('badge_track')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_teacher', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id');
            $table->timestamps();
        });
        Schema::create('classification_course', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('classification_id');
        });
        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->string('path');
            $table->string('type')->default('featured');
            $table->string('photoable_type');
            $table->unsignedBigInteger('photoable_id');
            $table->timestamps();
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('holder_name')->nullable();
            $table->string('course_name')->nullable();
            $table->string('certificate_text_template_key', 32)->nullable();
            $table->string('certificate_text')->nullable();
            $table->string('image_path');
            $table->uuid('generation_lease_id')->nullable()->index();
            $table->timestamp('generated_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
        });
        Schema::create('portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('source_project_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('slug')->nullable();
            $table->string('role')->nullable();
            $table->json('tools')->nullable();
            $table->string('external_url')->nullable();
            $table->date('completed_at')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('portfolio_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('portfolio_item_id');
            $table->string('file_path');
            $table->string('file_type');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('badge_image')->nullable();
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
        });
        Schema::create('user_level', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('level_id');
            $table->unsignedBigInteger('course_id');
            $table->timestamp('earned_at')->nullable();
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->string('section_type')->nullable();
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
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_graduation_project')->default(false);
            $table->timestamps();
        });
        Schema::create('project_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->string('idempotency_key', 100);
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('submission_metadata')->nullable();
            $table->string('effort_status', 30)->default('unknown');
            $table->string('review_status', 30)->default('pending');
            $table->string('review_source', 40)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('auto_pass_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'project_id', 'idempotency_key']);
        });
        Schema::create('user_project_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->boolean('passed')->default(false);
            $table->timestamps();
        });
        Schema::create('student_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('delivery_key', 64)->nullable();
            $table->string('notification_type');
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('message_ar');
            $table->text('message_en');
            $table->string('link')->nullable();
            $table->string('image_url')->nullable();
            $table->string('action_label_ar')->nullable();
            $table->string('action_label_en')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('push_attempted_at')->nullable();
            $table->unsignedSmallInteger('push_attempts')->default(0);
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamp('push_failed_at')->nullable();
            $table->string('push_failure_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'delivery_key']);
        });
        Schema::create('user_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_token')->unique();
            $table->string('device_type')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_08_07_000022_create_account_file_deletions_table.php'))->up();
    }
}
