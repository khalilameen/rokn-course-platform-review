<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_events')) {
            Schema::create('product_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('event_id')->unique();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->char('actor_key', 64)->nullable()->index();
                $table->char('session_key', 64)->nullable();
                $table->string('event_name', 64);
                $table->string('source', 32)->default('app');
                $table->string('screen_key', 64)->nullable();
                $table->string('campaign_key', 64)->nullable();
                $table->unsignedBigInteger('course_id')->nullable();
                $table->unsignedBigInteger('module_id')->nullable();
                $table->unsignedBigInteger('lesson_id')->nullable();
                $table->unsignedBigInteger('project_id')->nullable();
                $table->unsignedSmallInteger('milestone')->nullable();
                $table->integer('value')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamp('received_at');

                $table->index(['event_name', 'occurred_at']);
                $table->index(['course_id', 'event_name', 'occurred_at']);
                $table->index(['user_id', 'event_name', 'occurred_at']);
                $table->index(['campaign_key', 'event_name', 'occurred_at']);
            });
        }

        if (!Schema::hasTable('outbox_events')) {
            Schema::create('outbox_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('event_key')->unique();
                $table->string('topic', 96)->index();
                $table->string('aggregate_type', 64)->nullable();
                $table->string('aggregate_id', 96)->nullable();
                $table->json('payload');
                $table->string('status', 24)->default('pending');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable()->index();
                $table->timestamp('delivered_at')->nullable();
                $table->char('last_error_fingerprint', 64)->nullable();
                $table->timestamps();

                $table->index(['status', 'available_at', 'id']);
                $table->index(['aggregate_type', 'aggregate_id']);
            });
        }

        if (!Schema::hasTable('webhook_endpoints')) {
            Schema::create('webhook_endpoints', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 120);
                $table->string('url', 2048);
                $table->text('secret');
                $table->json('events')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('timeout_seconds')->default(8);
                $table->timestamps();
                $table->index(['is_active', 'id']);
            });
        }

        if (!Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('webhook_endpoint_id');
                $table->unsignedBigInteger('outbox_event_id');
                $table->char('delivery_key', 64)->unique();
                $table->string('status', 24)->default('pending');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->timestamp('available_at')->nullable()->index();
                $table->timestamp('delivered_at')->nullable();
                $table->char('error_fingerprint', 64)->nullable();
                $table->timestamps();

                $table->unique(['webhook_endpoint_id', 'outbox_event_id'], 'webhook_delivery_once');
                $table->index(['status', 'available_at', 'id']);
            });
        }

        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                if (!Schema::hasColumn('courses', 'search_title_normalized')) {
                    $table->string('search_title_normalized', 512)->nullable();
                }
                if (!Schema::hasColumn('courses', 'search_terms_normalized')) {
                    $table->text('search_terms_normalized')->nullable();
                }
            });

            DB::table('courses')->orderBy('id')->chunkById(200, function ($courses) {
                foreach ($courses as $course) {
                    $title = trim(implode(' ', array_filter([
                        $course->name_ar ?? null,
                        $course->name_en ?? null,
                    ])));
                    $terms = trim(implode(' ', array_filter([
                        $title,
                        $course->description_ar ?? null,
                        $course->description_en ?? null,
                    ])));
                    DB::table('courses')->where('id', $course->id)->update([
                        'search_title_normalized' => self::normalizeArabic($title),
                        'search_terms_normalized' => self::normalizeArabic($terms),
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                $columns = array_values(array_filter(
                    ['search_title_normalized', 'search_terms_normalized'],
                    fn (string $column) => Schema::hasColumn('courses', $column)
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('product_events');
    }

    private static function normalizeArabic(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', 'ة' => 'ه', 'ـ' => '',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $value = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
};
