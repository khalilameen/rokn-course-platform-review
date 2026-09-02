<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_chat_turns')) {
            return;
        }

        $addConsumed = !Schema::hasColumn('course_chat_turns', 'admission_quota_consumed_at');
        $addReleased = !Schema::hasColumn('course_chat_turns', 'admission_quota_released_at');
        $addMinuteKey = !Schema::hasColumn('course_chat_turns', 'admission_minute_key');
        $addDailyKey = !Schema::hasColumn('course_chat_turns', 'admission_daily_key');
        if (!$addConsumed && !$addReleased && !$addMinuteKey && !$addDailyKey) {
            return;
        }

        Schema::table('course_chat_turns', function (Blueprint $table) use (
            $addConsumed,
            $addReleased,
            $addMinuteKey,
            $addDailyKey
        ): void {
            if ($addMinuteKey) {
                $table->string('admission_minute_key', 190)->nullable();
            }
            if ($addDailyKey) {
                $table->string('admission_daily_key', 190)->nullable();
            }
            if ($addConsumed) {
                $table->timestamp('admission_quota_consumed_at')->nullable();
            }
            if ($addReleased) {
                $table->timestamp('admission_quota_released_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('course_chat_turns')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('course_chat_turns', 'admission_minute_key')
                ? 'admission_minute_key'
                : null,
            Schema::hasColumn('course_chat_turns', 'admission_daily_key')
                ? 'admission_daily_key'
                : null,
            Schema::hasColumn('course_chat_turns', 'admission_quota_consumed_at')
                ? 'admission_quota_consumed_at'
                : null,
            Schema::hasColumn('course_chat_turns', 'admission_quota_released_at')
                ? 'admission_quota_released_at'
                : null,
        ]));
        if ($columns !== []) {
            Schema::table('course_chat_turns', fn (Blueprint $table) =>
                $table->dropColumn($columns)
            );
        }
    }
};
