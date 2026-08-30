<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\CourseCompleted;
use App\Http\Requests\Admin\CourseRequest;
use App\Http\Resources\BaseCourseResource;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Listeners\AwardLevelBadge;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ProjectSubmissionService;
use App\Services\CourseChatAccessService;
use App\Services\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BackendHardeningTest extends TestCase
{
    /** @var list<string> */
    private array $tables = [
        'contacts', 'user_level', 'levels', 'user_project_evaluations', 'project_submissions',
        'course_code_usages', 'course_codes',
        'projects', 'wallet_transactions', 'course_enrollments', 'orders',
        'classification_course', 'classifications', 'course_teacher', 'photos',
        'course_ratings', 'grades',
        'lessons', 'course_sections', 'courses', 'paths', 'settings', 'users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        Cache::flush();
        $this->withoutMiddleware(WebsiteVisitorCount::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_wallet_is_idempotent_and_rejects_conflicting_replays(): void
    {
        $user = $this->user(['wallet_coins' => 200, 'wallet_purchased_coins' => 100, 'wallet_reward_coins' => 100]);
        $wallet = app(WalletService::class);

        $credit = $wallet->credit(
            $user->id,
            50,
            'test_credit',
            'wallet-test-credit',
            null,
            [],
            WalletTransaction::BUCKET_PAID
        );
        $replay = $wallet->credit(
            $user->id,
            50,
            'test_credit',
            'wallet-test-credit',
            null,
            [],
            WalletTransaction::BUCKET_PAID
        );

        self::assertSame($credit->id, $replay->id);
        self::assertSame(1, WalletTransaction::query()->count());

        $this->expectException(\UnexpectedValueException::class);
        $wallet->credit(
            $user->id,
            51,
            'test_credit',
            'wallet-test-credit',
            null,
            [],
            WalletTransaction::BUCKET_PAID
        );
    }

    public function test_wallet_keeps_paid_and_reward_attribution_on_debit(): void
    {
        $user = $this->user(['wallet_coins' => 200, 'wallet_purchased_coins' => 100, 'wallet_reward_coins' => 100]);

        $transaction = app(WalletService::class)->debit(
            $user->id,
            120,
            'course_purchase',
            'wallet-test-debit',
            null,
            [],
            40
        );

        self::assertSame(80, $transaction->paid_amount);
        self::assertSame(40, $transaction->reward_amount);
        self::assertSame(80, $transaction->balance_after);
        self::assertSame(20, $transaction->paid_balance_after);
        self::assertSame(60, $transaction->reward_balance_after);
    }

    public function test_project_pending_is_authoritative_and_idempotency_cannot_change_content(): void
    {
        $user = $this->user();
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 30,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $service = app(ProjectSubmissionService::class);

        $submission = $service->submit(
            $user,
            $project,
            'هذه محاولة حقيقية قابلة للمراجعة',
            null,
            'project-test-key'
        );
        $replay = $service->submit(
            $user,
            $project,
            'هذه محاولة حقيقية قابلة للمراجعة',
            null,
            'project-test-key'
        );

        self::assertSame(ProjectSubmission::STATUS_PENDING, $submission->review_status);
        self::assertSame($submission->id, $replay->id);
        $this->assertDatabaseHas('user_project_evaluations', [
            'user_id' => $user->id,
            'project_id' => $project->id,
            'passed' => false,
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $service->submit(
            $user,
            $project,
            'محتوى مختلف بنفس المفتاح',
            null,
            'project-test-key'
        );
    }

    public function test_admin_can_pass_project_submission_with_a_complete_audit_record(): void
    {
        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $submission = app(ProjectSubmissionService::class)->submit(
            $student,
            $project,
            'هذه محاولة تنتظر قرار المراجع الإداري',
            null,
            'admin-project-pass'
        );

        $this->actingAs($admin)
            ->post(route('admin.project-submissions.pass', $submission), [
                'feedback' => 'تنفيذ واضح ومستوفٍ للمتطلبات.',
            ])
            ->assertRedirect(route('admin.project-submissions.show', $submission));

        $submission->refresh();
        self::assertSame(ProjectSubmission::STATUS_PASSED, $submission->review_status);
        self::assertSame('admin_manual', $submission->review_source);
        self::assertSame(100, $submission->score);
        self::assertSame($admin->id, $submission->reviewed_by);
        self::assertNotNull($submission->reviewed_at);
        self::assertSame('تنفيذ واضح ومستوفٍ للمتطلبات.', $submission->feedback);
        self::assertSame(
            $admin->id,
            data_get($submission->submission_metadata, 'review_history.0.reviewer_id')
        );
        $this->assertDatabaseHas('user_project_evaluations', [
            'user_id' => $student->id,
            'project_id' => $project->id,
            'score' => 100,
            'passed' => true,
        ]);
    }

    public function test_graceful_project_fallback_grants_progress_without_claiming_a_skill_score(): void
    {
        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 30,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $service = app(ProjectSubmissionService::class);
        $submission = $service->submit(
            $student,
            $project,
            'محاولة واضحة بذل فيها الطالب مجهودًا حقيقيًا',
            null,
            'graceful-participation-test'
        );
        $submission->forceFill(['auto_pass_at' => now()->subSecond()])->save();

        $submission = $service->finalizeIfDue($submission->fresh());

        self::assertSame(ProjectSubmission::STATUS_PASSED, $submission->review_status);
        self::assertSame('graceful_fallback', $submission->review_source);
        self::assertNull($submission->score);
        self::assertSame('participation', data_get($submission->submission_metadata, 'assessment_type'));
        self::assertFalse((bool) data_get($submission->submission_metadata, 'skill_verified'));
        $this->assertDatabaseHas('user_project_evaluations', [
            'user_id' => $student->id,
            'project_id' => $project->id,
            'score' => 0,
            'passed' => true,
        ]);

        $submission = $service->reviewByAdmin(
            $submission,
            $admin,
            true,
            'راجعها الفريق واعتمد جودة التنفيذ.'
        );
        self::assertSame('admin_manual', $submission->review_source);
        self::assertSame(100, $submission->score);
        self::assertTrue((bool) data_get($submission->submission_metadata, 'skill_verified'));
    }

    public function test_client_duration_cannot_create_or_lower_the_completion_threshold(): void
    {
        $lesson = new \App\Models\Lesson(['duration_minutes' => null]);
        config()->set('learning_evidence.minimum_verified_seconds', 20);
        config()->set('learning_evidence.required_fraction', 0.80);

        $service = app(\App\Services\LearningEvidenceService::class);
        self::assertNull($service->requiredSeconds($lesson, 1));

        $lesson->duration_minutes = 1;
        self::assertSame(48, $service->requiredSeconds($lesson, 1));
        self::assertSame(48, $service->requiredSeconds($lesson, 3600));
    }

    public function test_admin_can_reject_pending_project_but_cannot_overwrite_final_decision(): void
    {
        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $service = app(ProjectSubmissionService::class);
        $submission = $service->submit(
            $student,
            $project,
            'هذه محاولة تحتاج إلى توضيح بعض الخطوات',
            null,
            'admin-project-reject'
        );

        $this->actingAs($admin)
            ->post(route('admin.project-submissions.reject', $submission), [
                'feedback' => 'أرفق صورة النتيجة واشرح الخطوة الأخيرة.',
            ])
            ->assertRedirect(route('admin.project-submissions.show', $submission));

        $submission->refresh();
        self::assertSame(ProjectSubmission::STATUS_NEEDS_RESUBMISSION, $submission->review_status);
        self::assertSame(0, $submission->score);
        self::assertSame($admin->id, $submission->reviewed_by);

        $this->expectException(ValidationException::class);
        $service->reviewByAdmin($submission, $admin, true, 'محاولة تغيير القرار');
    }

    public function test_project_review_service_rejects_non_admin_reviewer(): void
    {
        $student = $this->user();
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $service = app(ProjectSubmissionService::class);
        $submission = $service->submit(
            $student,
            $project,
            'محاولة لا يحق للطالب مراجعتها بنفسه',
            null,
            'non-admin-project-review'
        );

        try {
            $service->reviewByAdmin($submission, $student, true);
            self::fail('A client was allowed to review a project submission.');
        } catch (AuthorizationException $exception) {
            self::assertSame(ProjectSubmission::STATUS_PENDING, $submission->fresh()->review_status);
        }
    }

    public function test_admin_downloads_project_file_from_private_submission_path(): void
    {
        Storage::fake('local');
        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $submission = app(ProjectSubmissionService::class)->submit(
            $student,
            $project,
            'المحاولة لها ملف خاص لا يعرض من public storage',
            null,
            'admin-project-download'
        );
        $path = "project_submissions/{$student->id}/{$project->id}/stored-file.pdf";
        Storage::disk('local')->put($path, 'private project payload');
        $submission->update([
            'submission_file' => $path,
            'original_file_name' => '../../answer.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 23,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.project-submissions.download', $submission))
            ->assertOk()
            ->assertDownload('answer.pdf');

        $submission->update(['submission_file' => '../outside-project-submissions.txt']);
        $this->actingAs($admin)
            ->get(route('admin.project-submissions.download', $submission))
            ->assertNotFound();
    }

    public function test_project_submission_keeps_and_reads_the_exact_shared_disk_used_at_upload(): void
    {
        $sharedRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'rokn-project-test-'.bin2hex(random_bytes(6));
        config()->set('filesystems.disks.project-shared', [
            'driver' => 'local',
            'root' => $sharedRoot,
            'throw' => false,
        ]);
        app('filesystem')->forgetDisk('project-shared');
        config()->set('projects.submission_disk', 'project-shared');

        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $file = UploadedFile::fake()->create('answer.pdf', 4, 'application/pdf');

        $submission = app(ProjectSubmissionService::class)->submit(
            $student,
            $project,
            null,
            $file,
            'shared-storage-submission'
        );

        self::assertSame('project-shared', $submission->submission_disk);
        self::assertSame(
            'project-shared',
            data_get($submission->submission_metadata, 'storage_disk')
        );
        Storage::disk('project-shared')->assertExists($submission->submission_file);

        // A later process can have a different default and must still read the
        // immutable disk recorded with the submission.
        config()->set('projects.submission_disk', 'local');
        $this->actingAs($admin)
            ->get(route('admin.project-submissions.download', $submission))
            ->assertOk()
            ->assertDownload('answer.pdf');

        Storage::disk('project-shared')->deleteDirectory('');
    }

    public function test_badge_course_requires_an_existing_level(): void
    {
        $rules = (new CourseRequest())->rules();
        $missing = Validator::make([
            'name_ar' => 'كورس شارات',
            'awards_badge' => 1,
            'badge_track' => 'professional',
        ], $rules);
        self::assertTrue($missing->errors()->has('level_id'));

        $invalid = Validator::make([
            'name_ar' => 'كورس شارات',
            'awards_badge' => 1,
            'badge_track' => 'professional',
            'level_id' => 999999,
        ], $rules);
        self::assertTrue($invalid->errors()->has('level_id'));

        $levelId = DB::table('levels')->insertGetId([
            'name_ar' => 'مبتدئ',
            'name_en' => 'Beginner',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $valid = Validator::make([
            'name_ar' => 'كورس شارات',
            'awards_badge' => 1,
            'badge_track' => 'professional',
            'level_id' => $levelId,
        ], $rules);
        self::assertFalse($valid->fails());
    }

    public function test_public_deletion_request_is_typed_and_cannot_spoof_resolution_audit(): void
    {
        $this->post('/account-deletion', [
            'name' => 'Deletion Test',
            'email' => '  Delete.Me@Example.COM ',
            'phone' => '+201000000000',
            'reason' => 'لم أعد أستخدم الحساب',
            'confirm' => '1',
            'resolution_status' => Contact::RESOLUTION_FULFILLED,
            'resolved_by' => 999,
            'resolution_metadata' => ['spoofed' => true],
        ])->assertRedirect(route('account-deletion.show'));

        $contact = Contact::query()->latest('id')->firstOrFail();
        self::assertSame('delete.me@example.com', $contact->email);
        self::assertSame(Contact::TYPE_ACCOUNT_DELETION, $contact->request_type);
        self::assertFalse($contact->read);
        self::assertSame(Contact::RESOLUTION_PENDING, $contact->resolution_status);
        self::assertNull($contact->resolved_by);
        self::assertNull($contact->resolution_metadata);

        $ordinary = Contact::create([
            'name' => 'Ordinary Contact',
            'email' => 'ordinary@example.com',
            'phone' => '201000000001',
            'message' => 'A sufficiently long message',
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
            'resolution_status' => Contact::RESOLUTION_FULFILLED,
        ]);
        self::assertNull($ordinary->request_type);
        self::assertNull($ordinary->resolution_status);
    }

    public function test_admin_cannot_delete_account_deletion_request_audit_record(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $contact = new Contact();
        $contact->forceFill([
            'name' => 'Deletion Test',
            'email' => 'delete.audit@example.com',
            'phone' => '-',
            'message' => "[ACCOUNT_DELETION_REQUEST]\nReference: DEL-TEST",
            'read' => false,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
        ])->save();

        $this->actingAs($admin)
            ->delete(route('admin.contacts.destroy', $contact))
            ->assertRedirect(route('admin.contacts.show', $contact));

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
        ]);
    }

    public function test_admin_can_process_and_close_deletion_request_without_deleting_account(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $account = $this->user(['email' => 'owner@example.com']);
        $contact = new Contact();
        $contact->forceFill([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'phone' => '-',
            'message' => "[ACCOUNT_DELETION_REQUEST]\nReference: DEL-WORKFLOW",
            'read' => false,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
            'resolution_status' => Contact::RESOLUTION_PENDING,
        ])->save();

        $this->actingAs($admin)
            ->post(route('admin.contacts.processing', $contact))
            ->assertRedirect(route('admin.contacts.show', $contact));
        self::assertSame(Contact::RESOLUTION_PROCESSING, $contact->fresh()->resolution_status);

        $this->actingAs($admin)
            ->post(route('admin.contacts.close-deletion-request', $contact), [
                'outcome' => 'duplicate',
                'resolution_note' => 'تم ربطه بالطلب الأصلي.',
                'confirm_close' => '1',
            ])
            ->assertRedirect(route('admin.contacts.show', $contact));

        $contact->refresh();
        self::assertSame(Contact::RESOLUTION_CLOSED, $contact->resolution_status);
        self::assertSame($admin->id, $contact->resolved_by);
        self::assertSame($account->id, $contact->resolved_user_id);
        self::assertSame('duplicate', data_get($contact->resolution_metadata, 'outcome'));
        self::assertNotNull($contact->resolved_at);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'email' => 'owner@example.com']);
    }

    public function test_admin_cannot_claim_self_service_deletion_while_matching_account_exists(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $account = $this->user(['email' => 'still-active@example.com']);
        $contact = new Contact();
        $contact->forceFill([
            'name' => 'Owner',
            'email' => $account->email,
            'phone' => '-',
            'message' => "[ACCOUNT_DELETION_REQUEST]\nReference: DEL-GUARD",
            'read' => false,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
            'resolution_status' => Contact::RESOLUTION_PROCESSING,
        ])->save();

        $this->actingAs($admin)
            ->post(route('admin.contacts.close-deletion-request', $contact), [
                'outcome' => 'self_service_completed',
                'confirm_close' => '1',
            ])
            ->assertRedirect(route('admin.contacts.show', $contact));

        self::assertSame(Contact::RESOLUTION_PROCESSING, $contact->fresh()->resolution_status);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'email' => 'still-active@example.com']);
    }

    public function test_course_code_grant_cannot_consume_ai_but_paid_enrollment_can(): void
    {
        $user = $this->user();
        $course = $this->course();
        $grantOrder = $this->order($user, $course, Order::PAYMENT_METHOD_COURSE_CODE, 0, 0);
        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $grantOrder->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', ['message' => 'اشرح الفكرة'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'chat_upgrade_required');

        $paidOrder = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        DB::table('course_enrollments')->where('id', $enrollmentId)->update(['order_id' => $paidOrder->id]);
        config()->set('openrouter.api_key', 'test-key');
        config()->set('openrouter.default_model', 'test/model');
        config()->set('openrouter.allowed_models', ['test/model']);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'الإجابة المختصرة']]]], 200),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', ['message' => 'اشرح الفكرة'])
            ->assertOk()
            ->assertJsonPath('data.message', 'الإجابة المختصرة');
        Http::assertSentCount(1);
    }

    public function test_course_details_entitlement_marks_grants_and_paid_access_authoritatively(): void
    {
        $user = $this->user();
        $course = $this->course(['ai_model_type' => 'test/model']);
        $grantOrder = $this->order($user, $course, Order::PAYMENT_METHOD_COURSE_CODE, 0, 0);
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $grantOrder->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $access = app(CourseChatAccessService::class)->entitlementFor($user->id, $course->id);
        self::assertSame('scholarship', $access['access_type']);
        self::assertFalse($access['chat_available']);

        $grantPayload = (new BaseCourseResource($course))
            ->withEntitlement($access['access_type'], $access['chat_available'])
            ->resolve();
        self::assertSame('scholarship', $grantPayload['access_type']);
        self::assertFalse($grantPayload['chat_available']);
        self::assertFalse($grantPayload['metadata']['chat_available']);

        $paidOrder = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        DB::table('course_enrollments')->where('user_id', $user->id)->update([
            'order_id' => $paidOrder->id,
        ]);

        $paidAccess = app(CourseChatAccessService::class)->entitlementFor($user->id, $course->id);
        self::assertSame('paid', $paidAccess['access_type']);
        self::assertTrue($paidAccess['chat_available']);

        $course->update(['ai_chat_enabled' => false]);
        $disabledAccess = app(CourseChatAccessService::class)->entitlementFor($user->id, $course->id);
        self::assertSame('paid', $disabledAccess['access_type']);
        self::assertFalse($disabledAccess['chat_available']);

        $freeUser = $this->user(['email' => 'free@example.com']);
        $freeCourse = $this->course(['price' => 0]);
        $freeOrder = $this->order($freeUser, $freeCourse, Order::PAYMENT_METHOD_WALLET_COINS, 0, 0);
        DB::table('course_enrollments')->insert([
            'user_id' => $freeUser->id,
            'course_id' => $freeCourse->id,
            'order_id' => $freeOrder->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $freeAccess = app(CourseChatAccessService::class)->entitlementFor($freeUser->id, $freeCourse->id);
        self::assertSame('free', $freeAccess['access_type']);
        self::assertFalse($freeAccess['chat_available']);
    }

    public function test_course_chat_daily_cost_limit_is_enforced_before_second_provider_call(): void
    {
        $user = $this->user();
        $course = $this->course();
        $order = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config()->set('openrouter.api_key', 'test-key');
        config()->set('openrouter.default_model', 'test/model');
        config()->set('openrouter.allowed_models', ['test/model']);
        config()->set('openrouter.daily_user_limit', 1);
        config()->set('openrouter.per_minute_limit', 8);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'رد']]]], 200),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', ['message' => 'السؤال الأول'])
            ->assertOk()
            ->assertJsonPath('data.unavailable', false);
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', ['message' => 'السؤال الثاني'])
            ->assertOk()
            ->assertJsonPath('code', 'chat_daily_limit_reached');
        Http::assertSentCount(1);
    }

    public function test_social_completion_rejects_untrusted_provider_and_consumes_code_once(): void
    {
        $code = str_repeat('a', 64);
        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(
            base64_encode(hash('sha256', $verifier, true)),
            '+/',
            '-_'
        ), '=');
        Cache::put('social-oauth-complete:' . hash('sha256', $code), [
            'provider' => 'untrusted-provider',
            'encrypted_token' => Crypt::encryptString('provider-token'),
            'code_challenge' => $challenge,
        ], now()->addMinute());

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => $code,
            'code_verifier' => $verifier,
        ])
            ->assertStatus(410)
            ->assertJsonPath('code', 'social_login_expired');
        self::assertFalse(Cache::has('social-oauth-complete:' . hash('sha256', $code)));
    }

    public function test_same_level_can_be_awarded_once_per_course_without_silent_duplicates(): void
    {
        $user = $this->user();
        $levelId = DB::table('levels')->insertGetId([
            'name_ar' => 'Junior',
            'name_en' => 'Junior',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $first = $this->course(['level_id' => $levelId, 'awards_badge' => true, 'badge_track' => 'freelance']);
        $second = $this->course(['level_id' => $levelId, 'awards_badge' => true, 'badge_track' => 'professional']);
        $listener = new AwardLevelBadge();

        $listener->handle(new CourseCompleted($user, $first));
        $listener->handle(new CourseCompleted($user, $first));
        $listener->handle(new CourseCompleted($user, $second));

        self::assertSame(2, DB::table('user_level')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('user_level', ['user_id' => $user->id, 'course_id' => $first->id]);
        $this->assertDatabaseHas('user_level', ['user_id' => $user->id, 'course_id' => $second->id]);
    }

    public function test_guest_catalogue_is_real_and_bounded_to_fifteen_courses_per_row(): void
    {
        $classificationId = DB::table('classifications')->insertGetId([
            'name_ar' => 'الأكثر مشاهدة',
            'name_en' => 'Most watched',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        for ($index = 1; $index <= 16; $index++) {
            $course = $this->course(['name_ar' => 'كورس ' . $index, 'is_main_course' => $index === 1]);
            DB::table('classification_course')->insert([
                'classification_id' => $classificationId,
                'course_id' => $course->id,
            ]);
            DB::table('course_sections')->insert([
                'course_id' => $course->id,
                'sectionable_type' => null,
                'sectionable_id' => null,
                'section_type' => 'project',
                'title_ar' => 'مشروع',
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $childHero = $this->course([
            'parent_id' => 1,
            'is_main_course' => true,
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $childHero->id,
            'sectionable_type' => null,
            'sectionable_id' => null,
            'section_type' => 'project',
            'title_ar' => 'مشروع فرعي',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/mobile-main-page')
            ->assertOk()
            ->assertJsonPath('success', true);

        self::assertCount(15, $response->json('data.courses.الأكثر مشاهدة'));
        self::assertCount(1, $response->json('data.main_courses'));
        self::assertNotSame($childHero->id, (int) $response->json('data.main_courses.0.id'));
    }

    public function test_public_course_list_includes_published_and_announced_coming_soon_only(): void
    {
        $published = $this->course(['name_ar' => 'منشور']);
        DB::table('course_sections')->insert([
            'course_id' => $published->id,
            'section_type' => 'project',
            'title' => 'مشروع',
            'title_ar' => 'مشروع',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $announced = $this->course([
            'name_ar' => 'قريبًا ومُعلن',
            'is_coming_soon' => true,
            'is_catalog_visible' => true,
        ]);
        $hiddenDraft = $this->course([
            'name_ar' => 'مسودة داخلية',
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ]);

        $response = $this->getJson('/api/v1/courses/list?per_page=50')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data.courses'))->pluck('id')->map(fn ($id) => (int) $id);
        self::assertTrue($ids->contains($published->id));
        self::assertTrue($ids->contains($announced->id));
        self::assertFalse($ids->contains($hiddenDraft->id));
    }

    public function test_verified_institution_email_can_receive_only_one_course_grant(): void
    {
        $user = $this->user(['email' => 'student@college.edu', 'email_verified_at' => now()]);
        $first = CourseCode::query()->create([
            'code' => 'COLLEGE-ONE',
            'type' => 'course',
            'max_uses' => 100,
            'is_active' => true,
            'allowed_email_domains' => ['college.edu'],
        ]);
        $second = CourseCode::query()->create([
            'code' => 'COLLEGE-TWO',
            'type' => 'course',
            'max_uses' => 100,
            'is_active' => true,
            'allowed_email_domains' => ['college.edu'],
        ]);
        DB::table('course_code_usages')->insert([
            'course_code_id' => $first->id,
            'user_id' => $user->id,
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertTrue($second->hasReachedInstitutionalGrantLimit($user->id));
        self::assertFalse($second->canBeUsedByUser($user->id));
    }

    private function user(array $overrides = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User',
            'email' => uniqid('student-', true) . '@example.com',
            'phone' => null,
            'role' => 'client',
            'active' => true,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return User::query()->findOrFail($id);
    }

    private function course(array $overrides = []): Course
    {
        $id = DB::table('courses')->insertGetId(array_merge([
            'name_ar' => 'كورس تجريبي',
            'name_en' => 'Test course',
            'description_ar' => 'وصف',
            'description_en' => 'Description',
            'price' => 4000,
            'parent_id' => null,
            'is_main_course' => false,
            'is_coming_soon' => false,
            'ai_model_type' => null,
            'chat_ai_prompt' => 'اشرح مباشرة',
            'tokens_number' => 200,
            'temperature' => 0.3,
            'level_id' => null,
            'awards_badge' => false,
            'badge_track' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Course::query()->findOrFail($id);
    }

    private function order(User $user, Course $course, string $method, int $amount, int $coins): Order
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'payment_method' => $method,
            'amount' => $amount,
            'final_amount' => $amount,
            'total_coins' => $coins,
            'paid_coins' => $coins,
            'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('client');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('wallet_coins')->default(0);
            $table->unsignedInteger('wallet_purchased_coins')->default(0);
            $table->unsignedInteger('wallet_reward_coins')->default(0);
            $table->string('api_token')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enforce_course_section_order')->default(true);
            $table->timestamps();
        });
        Schema::create('paths', function (Blueprint $table): void {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->timestamps();
        });
        Schema::create('grades', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('price_before_discount', 10, 2)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('path_id')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->string('type')->default('course');
            $table->string('course_type')->default('online');
            $table->boolean('is_main_course')->default(false);
            $table->boolean('is_coming_soon')->default(false);
            $table->boolean('is_catalog_visible')->default(false);
            $table->string('ai_model_type')->nullable();
            $table->text('chat_ai_prompt')->nullable();
            $table->float('temperature')->nullable();
            $table->integer('tokens_number')->nullable();
            $table->boolean('ai_chat_enabled')->default(true);
            $table->unsignedBigInteger('level_id')->nullable();
            $table->boolean('awards_badge')->default(false);
            $table->string('badge_track')->nullable();
            $table->unsignedInteger('students_count')->default(0);
            $table->unsignedInteger('video_count')->default(0);
            $table->unsignedInteger('hours_count')->default(0);
            $table->unsignedInteger('home_work_count')->default(0);
            $table->unsignedInteger('files_count')->default(0);
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->string('section_type')->nullable();
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_ratings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name')->nullable();
            $table->string('type')->default('course');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->json('lesson_ids')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->json('allowed_email_domains')->nullable();
            $table->timestamps();
        });
        Schema::create('course_code_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_code_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('used_at');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->unique(['course_code_id', 'user_id']);
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('list_id')->nullable();
            $table->string('title')->nullable();
            $table->boolean('is_opened')->default(false);
            $table->timestamps();
        });
        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->string('photoable_type');
            $table->unsignedBigInteger('photoable_id');
            $table->string('url')->nullable();
            $table->string('path')->nullable();
            $table->string('type')->default('gallery');
            $table->timestamps();
        });
        Schema::create('course_teacher', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id');
            $table->timestamps();
        });
        Schema::create('classifications', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });
        Schema::create('classification_course', function (Blueprint $table): void {
            $table->unsignedBigInteger('classification_id');
            $table->unsignedBigInteger('course_id');
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedInteger('package_coins')->nullable();
            $table->string('payment_method');
            $table->string('order_ref')->nullable();
            $table->string('checkout_request_key')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('final_amount', 10, 2)->default(0);
            $table->unsignedInteger('total_coins')->nullable();
            $table->unsignedInteger('paid_coins')->nullable();
            $table->unsignedInteger('reward_coins')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
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
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('direction');
            $table->string('category');
            $table->string('bucket');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('paid_amount');
            $table->unsignedInteger('reward_amount');
            $table->integer('balance_after');
            $table->unsignedInteger('paid_balance_after');
            $table->unsignedInteger('reward_balance_after');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->text('requirements_text')->nullable();
            $table->text('requirements_text_ar')->nullable();
            $table->text('requirements_text_en')->nullable();
            $table->unsignedInteger('passing_score')->default(50);
            $table->unsignedInteger('fallback_review_delay_seconds')->default(8);
            $table->boolean('is_graduation_project')->default(false);
            $table->timestamps();
        });
        Schema::create('project_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->string('idempotency_key');
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('submission_metadata')->nullable();
            $table->string('effort_status');
            $table->string('review_status');
            $table->string('review_source')->nullable();
            $table->unsignedInteger('score')->nullable();
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
            $table->integer('score')->default(0);
            $table->boolean('passed')->default(false);
            $table->json('evaluation_data')->nullable();
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
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
            $table->unique(['user_id', 'level_id', 'course_id']);
        });
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->string('request_type')->nullable();
            $table->string('resolution_status')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->unsignedBigInteger('resolved_user_id')->nullable();
            $table->json('resolution_metadata')->nullable();
            $table->timestamps();
        });
    }
}
