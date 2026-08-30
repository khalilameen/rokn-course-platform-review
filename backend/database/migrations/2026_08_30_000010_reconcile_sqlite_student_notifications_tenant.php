<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The frozen baseline intentionally skipped this legacy SQLite-only column.
     * Reconcile it in the forward tail without rewriting released migrations.
     */
    public function up(): void
    {
        if (
            DB::connection()->getDriverName() !== 'sqlite'
            || ! Schema::hasColumn('student_notifications', 'tenant_id')
        ) {
            return;
        }

        $columns = [
            'id',
            'user_id',
            'delivery_key',
            'notification_type',
            'notifiable_type',
            'notifiable_id',
            'title_ar',
            'title_en',
            'message_ar',
            'message_en',
            'link',
            'is_read',
            'read_at',
            'push_attempted_at',
            'push_sent_at',
            'created_at',
            'updated_at',
        ];

        Schema::disableForeignKeyConstraints();
        try {
            Schema::dropIfExists('student_notifications_without_tenant');
            Schema::create('student_notifications_without_tenant', function (Blueprint $table): void {
                $table->bigIncrements('id');
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
                $table->boolean('is_read')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamp('push_attempted_at')->nullable();
                $table->timestamp('push_sent_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });

            DB::table('student_notifications_without_tenant')->insertUsing(
                $columns,
                DB::table('student_notifications')->select($columns)
            );
            Schema::drop('student_notifications');
            Schema::rename('student_notifications_without_tenant', 'student_notifications');

            Schema::table('student_notifications', function (Blueprint $table): void {
                $table->index('user_id');
                $table->index('is_read');
                $table->index('notification_type');
                $table->index('created_at');
                $table->index(['user_id', 'is_read']);
                $table->index(['notifiable_type', 'notifiable_id']);
                $table->index(
                    ['user_id', 'is_read', 'created_at'],
                    'student_notifications_unread_timeline'
                );
                $table->index(
                    ['user_id', 'created_at'],
                    'student_notifications_user_timeline'
                );
                $table->unique(
                    ['user_id', 'delivery_key'],
                    'student_notifications_delivery_once'
                );
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        if (
            DB::connection()->getDriverName() !== 'sqlite'
            || Schema::hasColumn('student_notifications', 'tenant_id')
        ) {
            return;
        }

        Schema::table('student_notifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('read_at');
            $table->index('tenant_id');
        });
    }
};
