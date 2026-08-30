<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Abstract base test case for API endpoint feature tests.
 * Sets up an isolated in-memory SQLite schema and base seed fixtures cleanly
 * without modifying or running historical migrations.
 */
abstract class ApiTestCase extends TestCase
{
    protected User $user;
    protected int $gradeId;
    protected int $courseId;
    protected int $sectionId;
    protected int $pathId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchema();
        $this->setUpData();

        // Disable middlewares that depend on tenant subdomain state or visitor tracking blocks
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSchema();
        parent::tearDown();
    }

    private function setUpSchema(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name_ar')->nullable();
            $table->string('site_name_en')->nullable();
            $table->boolean('enforce_course_section_order')->default(false);
            $table->unsignedInteger('welcome_bonus_coins')->default(20);
            $table->unsignedInteger('reward_balance_cap')->default(1200);
            $table->unsignedInteger('max_reward_contribution_per_course')->default(1200);
            $table->unsignedInteger('daily_reward_coins')->default(15);
            $table->unsignedInteger('daily_reward_rolling_30_day_cap')->default(150);
            $table->unsignedSmallInteger('streak_reward_days')->default(7);
            $table->unsignedInteger('streak_reward_coins')->default(100);
            $table->unsignedInteger('streak_reward_rolling_30_day_cap')->default(400);
            $table->unsignedInteger('study_reward_coins')->default(10);
            $table->unsignedSmallInteger('study_reward_minutes')->default(5);
            $table->unsignedInteger('study_reward_daily_cap')->default(20);
            $table->unsignedInteger('study_reward_rolling_30_day_cap')->default(200);
            $table->unsignedInteger('first_project_reward_coins')->default(150);
            $table->unsignedInteger('course_completion_reward_coins')->default(200);
            $table->unsignedInteger('course_completion_rolling_30_day_cap')->default(200);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('first_name')->nullable();
            $table->string('second_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('phone')->nullable()->unique();
            $table->string('parent_phone')->nullable();
            $table->string('parent_job')->nullable();
            $table->string('password')->nullable();
            $table->enum('role', ['admin', 'client', 'provider', 'merchant'])->default('client');
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->date('birthday')->nullable();
            $table->integer('rate')->default(0);
            $table->float('balance')->default(0);
            $table->integer('wallet_coins')->default(1000);
            $table->unsignedInteger('wallet_purchased_coins')->default(0);
            $table->unsignedInteger('wallet_reward_coins')->default(1000);
            $table->string('api_token', 100)->unique()->nullable();
            $table->string('device_os')->nullable();
            $table->string('locked_device_id')->nullable();
            $table->string('access_token')->nullable();
            $table->string('social_provider')->nullable();
            $table->string('social_id')->nullable();
            $table->string('profile_image')->nullable();
            $table->string('job_title')->nullable();
            $table->text('bio')->nullable();
            $table->text('bio_ar')->nullable();
            $table->text('bio_en')->nullable();
            $table->string('type')->nullable();
            $table->string('governorate')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_online')->default(false);
            $table->boolean('provider_request')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->string('photoable_type');
            $table->unsignedBigInteger('photoable_id');
            $table->string('type')->default('gallery');
            $table->string('url')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->decimal('price', 10, 2)->default(100.00);
            $table->boolean('active')->default(true);
            $table->boolean('is_main_course')->default(true);
            $table->boolean('is_coming_soon')->default(false);
            $table->boolean('is_catalog_visible')->default(false);
            $table->string('course_type')->default('online');
            $table->float('rate')->default(5.0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('course_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('course_teacher', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id');
            $table->timestamps();
        });

        Schema::create('classification_course', function (Blueprint $table) {
            $table->unsignedBigInteger('classification_id');
            $table->unsignedBigInteger('course_id');
        });

        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->string('section_type')->default('lesson');
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->integer('order')->default(0);
            $table->integer('sort_order')->default(1);
            $table->boolean('is_free')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedInteger('package_coins')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('access_granted_at')->nullable();
            $table->float('progress')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_ref')->nullable()->unique();
            $table->string('transaction_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('course_code_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('payment_method', 50)->default('online');
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('payment_screenshot')->nullable();
            $table->json('payment_gateway_response')->nullable();
            $table->string('checkout_request_key')->nullable();
            $table->decimal('amount', 10, 2)->default(100.00);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('final_amount', 10, 2)->default(100.00);
            $table->string('status')->default('approved');
            $table->string('financial_status')->default('settled');
            $table->unsignedInteger('total_coins')->default(0);
            $table->unsignedInteger('paid_coins')->default(0);
            $table->unsignedInteger('reward_coins')->default(0);
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->unsignedInteger('recovered_coins')->default(0);
            $table->unsignedInteger('unrecovered_coins')->default(0);
            $table->boolean('is_premium_user')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('bill_number')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('payment_status')->default('pending');
            $table->string('payment_method')->default('online');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('delivery_key', 64)->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('source_project_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('slug')->nullable();
            $table->string('role')->nullable();
            $table->json('tools')->nullable();
            $table->text('external_url')->nullable();
            $table->date('completed_at')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('portfolio_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portfolio_item_id');
            $table->string('file_type')->default('image');
            $table->string('caption')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('file_path')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->text('requirements_text')->nullable();
            $table->text('ai_prompt')->nullable();
            $table->integer('passing_score')->default(50);
            $table->boolean('is_graduation_project')->default(false);
            $table->timestamps();
        });

        Schema::create('course_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type')->default('course');
            $table->string('target_content_name')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_grant')->default(false);
            $table->json('allowed_email_domains')->nullable();
            $table->boolean('is_used')->default(false);
            $table->integer('used_count')->default(0);
            $table->integer('max_uses')->default(1);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('course_code_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_code_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('course_pdfs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->string('file_path')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('random_quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('quiz_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->integer('attempt_number')->default(1);
            $table->float('score')->default(0);
            $table->boolean('passed')->default(false);
            $table->boolean('is_passed')->default(false);
            $table->string('status')->default('in_progress');
            $table->integer('time_taken_minutes')->default(0);
            $table->integer('total_questions')->default(10);
            $table->integer('answered_questions')->default(0);
            $table->integer('correct_answers')->default(0);
            $table->float('score_percentage')->default(0);
            $table->integer('score_points')->default(0);
            $table->json('exam_data')->nullable();
            $table->json('security_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_attempt_id');
            $table->unsignedBigInteger('question_id');
            $table->string('selected_answer')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->integer('points_earned')->default(0);
            $table->integer('max_points')->default(1);
            $table->timestamp('answered_at')->nullable();
            $table->json('question_data')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_attempt_id');
            $table->string('event_type');
            $table->json('details')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->nullable()->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('image_path')->default('pending');
            $table->string('status', 20)->default('active');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('course_grant_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->char('normalized_email_hash', 64)->unique();
            $table->string('email_hint')->nullable();
            $table->unsignedBigInteger('course_code_id');
            $table->unsignedBigInteger('course_code_usage_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->string('status')->default('active');
            $table->timestamp('claimed_at');
            $table->timestamp('reassigned_at')->nullable();
            $table->unsignedBigInteger('reassigned_by')->nullable();
            $table->text('support_note')->nullable();
            $table->timestamps();
        });

        Schema::create('paths', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('classification_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('classification_id');
            $table->timestamps();
        });

        Schema::create('student_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('delivery_key', 64)->nullable();
            $table->string('notification_type')->default('info');
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->text('message_ar')->nullable();
            $table->text('message_en')->nullable();
            $table->string('link')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('push_attempted_at')->nullable();
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'delivery_key']);
        });

        Schema::create('coin_earning_methods', function (Blueprint $table) {
            $table->id();
            $table->string('action_key')->nullable()->unique();
            $table->integer('coins_amount')->default(20);
            $table->boolean('is_repeatable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_coin_earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('coin_earning_method_id')->nullable();
            $table->integer('amount');
            $table->timestamps();
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 255)->unique();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('provider_email')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
        });

        Schema::create('deleted_social_reward_tombstones', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->char('identity_hmac', 64);
            $table->json('consumed_reward_keys');
            $table->timestamps();
            $table->unique(['provider', 'identity_hmac']);
        });

        Schema::create('user_coin_task_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('coin_earning_method_id');
            $table->string('status', 20)->default('started');
            $table->timestamp('started_at');
            $table->timestamp('claim_available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'coin_earning_method_id']);
        });

        Schema::create('user_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_token');
            $table->string('device_type')->nullable();
            $table->string('device_os')->nullable();
            $table->timestamps();
        });

        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('code');
            $table->string('type')->default('verification');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('list_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('quiz_id')->nullable();
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_opened')->default(true);
            $table->integer('duration_minutes')->default(10);
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('account_file_deletions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('disk', 64);
            $table->string('path_hash', 64);
            $table->text('path')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 190)->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path_hash']);
        });

        Schema::create('lesson_watch_evidence', function (Blueprint $table) {
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

        Schema::create('user_daily_learning_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('activity_date');
            $table->unsignedInteger('qualified_seconds')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'activity_date']);
        });

        Schema::create('user_reward_checkins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('checkin_date');
            $table->timestamps();
            $table->unique(['user_id', 'checkin_date']);
        });

        Schema::create('reward_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->unsignedInteger('coins_amount');
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedInteger('daily_cap')->nullable();
            $table->unsignedInteger('rolling_30_day_cap')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('direction', 10);
            $table->string('category', 40);
            $table->string('bucket', 24)->default('legacy_reward');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('paid_amount')->default(0);
            $table->unsignedInteger('reward_amount')->default(0);
            $table->integer('balance_after');
            $table->unsignedInteger('paid_balance_after')->default(0);
            $table->unsignedInteger('reward_balance_after')->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 140);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('saved_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saved_folder_id');
            $table->unsignedBigInteger('lesson_id');
            $table->timestamps();
        });

        Schema::create('saved_folder_lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saved_folder_id');
            $table->unsignedBigInteger('lesson_id');
            $table->timestamps();
        });

        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_type')->default('text');
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('student_section_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_section_id');
            $table->boolean('is_completed')->default(false);
            $table->string('status')->default('completed');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lists', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('type')->default('quiz');
            $table->integer('priority')->default(1);
            $table->text('description')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_opened')->default(true);
            $table->integer('time_minutes')->default(30);
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('list_id');
            $table->string('title')->nullable();
            $table->text('question')->nullable();
            $table->string('question_image')->nullable();
            $table->text('description')->nullable();
            $table->integer('priority')->default(1);
            $table->string('choice1')->nullable();
            $table->string('choice2')->nullable();
            $table->string('choice3')->nullable();
            $table->string('choice4')->nullable();
            $table->string('choice5')->nullable();
            $table->string('choice6')->nullable();
            $table->string('right_answer')->nullable();
            $table->timestamps();
        });

    }

    private function tearDownSchema(): void
    {
        $tables = [
            'course_grant_claims', 'course_code_usages', 'exam_security_logs', 'exam_answers', 'student_section_progress', 'account_file_deletions', 'api_tokens', 'photos', 'verification_codes', 'user_device_tokens', 'deleted_social_reward_tombstones', 'social_accounts', 'user_coin_task_attempts', 'user_coin_earnings', 'coin_earning_methods',
            'payment_methods', 'categories', 'portfolios', 'portfolio_media', 'portfolio_items', 'saved_folder_lessons', 'saved_sections', 'wallet_transactions', 'reward_rules', 'user_reward_checkins', 'user_daily_learning_activities', 'lesson_watch_evidence', 'lessons', 'saved_folders',
            'student_notifications', 'classification_user', 'classifications', 'paths', 'certificates', 'exam_attempts',
            'exams', 'random_quizzes', 'quizzes', 'questions', 'lists', 'course_pdfs', 'course_codes', 'bills', 'orders',
            'course_enrollments', 'course_sections', 'projects', 'course_ratings', 'course_teacher', 'classification_course', 'courses', 'grades', 'users', 'settings'
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function setUpData(): void
    {
        DB::table('settings')->insert([
            'site_name_ar' => 'ركن',
            'site_name_en' => 'Rokn',
            'enforce_course_section_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('reward_rules')->insert([
            ['event_key' => 'welcome_bonus', 'title_ar' => 'هدية أول تسجيل', 'coins_amount' => 20, 'interval_count' => 1, 'daily_cap' => null, 'rolling_30_day_cap' => null, 'is_active' => 1, 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'daily_checkin', 'title_ar' => 'فتح يومي', 'coins_amount' => 15, 'interval_count' => 1, 'daily_cap' => null, 'rolling_30_day_cap' => 150, 'is_active' => 1, 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'streak_milestone', 'title_ar' => 'استمرارية', 'coins_amount' => 100, 'interval_count' => 7, 'daily_cap' => null, 'rolling_30_day_cap' => 400, 'is_active' => 1, 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'study_session', 'title_ar' => 'دراسة', 'coins_amount' => 10, 'interval_count' => 5, 'daily_cap' => 20, 'rolling_30_day_cap' => 200, 'is_active' => 1, 'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'first_project_passed', 'title_ar' => 'أول مشروع', 'coins_amount' => 150, 'interval_count' => 1, 'daily_cap' => null, 'rolling_30_day_cap' => 150, 'is_active' => 1, 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'course_completed', 'title_ar' => 'إنهاء كورس', 'coins_amount' => 200, 'interval_count' => 1, 'daily_cap' => null, 'rolling_30_day_cap' => 200, 'is_active' => 1, 'sort_order' => 60, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('coin_earning_methods')->insert([
            'action_key' => 'register',
            'coins_amount' => 20,
            'is_repeatable' => 0,
            'is_active' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->gradeId = (int) DB::table('grades')->insertGetId([
            'name_ar' => 'الصف الأول',
            'name_en' => 'Grade 1',
            'sort_order' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->courseId = (int) DB::table('courses')->insertGetId([
            'name_ar' => 'دورة تجريبية',
            'name_en' => 'Test Course',
            'grade_id' => $this->gradeId,
            'price' => 100.00,
            'active' => 1,
            'is_main_course' => 1,
            'is_coming_soon' => 0,
            'course_type' => 'online',
            'rate' => 5.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sectionId = (int) DB::table('course_sections')->insertGetId([
            'course_id' => $this->courseId,
            'title_ar' => 'قسم 1',
            'title_en' => 'Section 1',
            'sort_order' => 1,
            'is_free' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pathId = (int) DB::table('paths')->insertGetId([
            'title_ar' => 'مسار تجريبي',
            'title_en' => 'Test Path',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = new User();
        $this->user->name = 'API Test User';
        $this->user->phone = '01234567890';
        $this->user->email = 'test@rokn.com';
        $this->user->password = bcrypt('password123');
        $this->user->active = true;
        $this->user->wallet_coins = 1000;
        $this->user->save();

        // Base fixtures needed across various controllers so they return valid responses instead of 404
        DB::table('course_codes')->insert([
            'code' => 'TESTCODE',
            'type' => 'course',
            'course_id' => $this->courseId,
            'is_active' => 1,
            'is_used' => 0,
            'used_count' => 0,
            'max_uses' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_notifications')->insert([
            'id' => 1,
            'user_id' => $this->user->id,
            'title_ar' => 'اشعار 1',
            'title_en' => 'Notification 1',
            'message_ar' => 'محتوى الاشعار',
            'message_en' => 'Notification body',
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('classifications')->insert([
            'id' => 1,
            'name_ar' => 'برمجة',
            'name_en' => 'Programming',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('portfolio_items')->insert([
            'id' => 1,
            'user_id' => $this->user->id,
            'title' => 'Sample Portfolio Item',
            'description' => 'A sample portfolio entry for testing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_folders')->insert([
            'id' => 1,
            'user_id' => $this->user->id,
            'name' => 'Folder 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => 10,
            'list_id' => $this->courseId,
            'course_id' => $this->courseId,
            'section_id' => $this->sectionId,
            'title' => 'Lesson 10',
            'title_ar' => 'الدرس 10',
            'title_en' => 'Lesson 10',
            'description' => 'Lesson 10 description',
            'duration_minutes' => 15,
            'is_opened' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lists')->insert([
            'id' => 1,
            'title' => 'Quiz 1',
            'title_ar' => 'اختبار 1',
            'title_en' => 'Quiz 1',
            'course_id' => $this->courseId,
            'type' => 'quiz',
            'time_minutes' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('questions')->insert([
            'id' => 1,
            'list_id' => 1,
            'title' => 'Question 1',
            'question' => 'What is 2 + 2?',
            'choice1' => '3',
            'choice2' => '4',
            'choice3' => '5',
            'choice4' => '6',
            'right_answer' => '4',
            'priority' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_sections')->insert([
            'id' => 1,
            'saved_folder_id' => 1,
            'lesson_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_folder_lessons')->insert([
            'id' => 1,
            'saved_folder_id' => 1,
            'lesson_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('quizzes')->insert([
            'id' => 1,
            'title_ar' => 'اختبار 1',
            'title_en' => 'Quiz 1',
            'course_id' => $this->courseId,
            'section_id' => $this->sectionId,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('random_quizzes')->insert([
            'id' => 1,
            'title_ar' => 'عشوائي 1',
            'title_en' => 'Random Quiz 1',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exams')->insert([
            'id' => 1,
            'title_ar' => 'امتحان 1',
            'title_en' => 'Exam 1',
            'course_id' => $this->courseId,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_attempts')->insert([
            'id' => 1,
            'user_id' => $this->user->id,
            'quiz_id' => 1,
            'course_id' => $this->courseId,
            'section_id' => $this->sectionId,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'total_questions' => 10,
            'answered_questions' => 0,
            'correct_answers' => 0,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
