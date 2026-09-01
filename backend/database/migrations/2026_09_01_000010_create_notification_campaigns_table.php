<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('notification_campaigns')) {
            Schema::create('notification_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->string('delivery_key', 64)->unique();
                $table->string('notification_type', 64)->index();
                $table->string('audience', 32)->default('all');
                $table->unsignedBigInteger('course_id')->nullable()->index();
                $table->string('notifiable_type')->nullable();
                $table->unsignedBigInteger('notifiable_id')->nullable();
                $table->json('user_ids')->nullable();
                $table->json('exclude_user_ids')->nullable();
                $table->unsignedBigInteger('authored_by')->nullable()->index();
                $table->string('title_ar');
                $table->string('title_en')->nullable();
                $table->text('message_ar');
                $table->text('message_en')->nullable();
                $table->string('action_label_ar', 80)->nullable();
                $table->string('action_label_en', 80)->nullable();
                $table->text('link')->nullable();
                $table->text('image_url')->nullable();
                $table->string('status', 24)->default('queued')->index();
                $table->unsignedInteger('recipients_count')->default(0);
                $table->unsignedInteger('inbox_count')->default(0);
                $table->unsignedTinyInteger('retry_count')->default(0);
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('coordinator_finished_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('student_notifications')) {
            return;
        }

        DB::table('student_notifications')
            ->whereNotNull('delivery_key')
            ->selectRaw('delivery_key')
            ->selectRaw('MAX(notification_type) as notification_type')
            ->selectRaw('MAX(title_ar) as title_ar')
            ->selectRaw('MAX(title_en) as title_en')
            ->selectRaw('MAX(message_ar) as message_ar')
            ->selectRaw('MAX(message_en) as message_en')
            ->selectRaw('MAX(link) as link')
            ->selectRaw('MAX(image_url) as image_url')
            ->selectRaw('COUNT(*) as recipients_count')
            ->selectRaw('MIN(created_at) as queued_at')
            ->selectRaw('MAX(created_at) as completed_at')
            ->groupBy('delivery_key')
            ->orderBy('delivery_key')
            ->chunk(200, function ($campaigns): void {
                $now = now();
                $rows = collect($campaigns)->map(static fn ($campaign): array => [
                    'delivery_key' => (string) $campaign->delivery_key,
                    'notification_type' => (string) $campaign->notification_type,
                    'audience' => 'all',
                    'course_id' => null,
                    'notifiable_type' => null,
                    'notifiable_id' => null,
                    'user_ids' => null,
                    'exclude_user_ids' => null,
                    'authored_by' => null,
                    'title_ar' => (string) $campaign->title_ar,
                    'title_en' => $campaign->title_en,
                    'message_ar' => (string) $campaign->message_ar,
                    'message_en' => $campaign->message_en,
                    'action_label_ar' => null,
                    'action_label_en' => null,
                    'link' => $campaign->link,
                    'image_url' => $campaign->image_url,
                    'status' => 'completed',
                    'recipients_count' => (int) $campaign->recipients_count,
                    'inbox_count' => (int) $campaign->recipients_count,
                    'retry_count' => 0,
                    'queued_at' => $campaign->queued_at,
                    'coordinator_finished_at' => $campaign->completed_at,
                    'completed_at' => $campaign->completed_at,
                    'failed_at' => null,
                    'failure_code' => null,
                    'created_at' => $campaign->queued_at ?: $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('notification_campaigns')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaigns');
    }
};
