<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_notifications', function (Blueprint $table): void {
            $table->string('delivery_key', 64)->nullable()->after('user_id');
            $table->timestamp('push_attempted_at')->nullable()->after('read_at');
            $table->timestamp('push_sent_at')->nullable()->after('push_attempted_at');
            $table->unique(['user_id', 'delivery_key'], 'student_notifications_delivery_once');
        });
    }

    public function down(): void
    {
        Schema::table('student_notifications', function (Blueprint $table): void {
            $table->dropUnique('student_notifications_delivery_once');
            $table->dropColumn(['delivery_key', 'push_attempted_at', 'push_sent_at']);
        });
    }
};
