<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * The frozen baseline intentionally skipped this legacy SQLite-only column.
     * Reconcile it in the forward tail without rewriting released migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $source = 'student_notifications';
        $temporary = 'student_notifications_without_tenant';
        if (! Schema::hasTable($source)) {
            if (! Schema::hasTable($temporary)) {
                throw new RuntimeException('Both the notification table and its recovery copy are missing.');
            }

            Schema::rename($temporary, $source);
        }

        if (! Schema::hasColumn($source, 'tenant_id')) {
            $this->ensureIndexes();

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
            Schema::dropIfExists($temporary);
            Schema::create($temporary, function (Blueprint $table): void {
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

            DB::table($temporary)->insertUsing(
                $columns,
                DB::table($source)->select($columns)
            );
            Schema::drop($source);
            Schema::rename($temporary, $source);
            $this->ensureIndexes();
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

    private function ensureIndexes(): void
    {
        $indexes = [
            'student_notifications_user_id_index' => fn (Blueprint $table) => $table->index('user_id'),
            'student_notifications_is_read_index' => fn (Blueprint $table) => $table->index('is_read'),
            'student_notifications_notification_type_index' => fn (Blueprint $table) => $table->index('notification_type'),
            'student_notifications_created_at_index' => fn (Blueprint $table) => $table->index('created_at'),
            'student_notifications_user_id_is_read_index' => fn (Blueprint $table) => $table->index(['user_id', 'is_read']),
            'student_notifications_notifiable_type_notifiable_id_index' => fn (Blueprint $table) => $table->index(['notifiable_type', 'notifiable_id']),
            'student_notifications_unread_timeline' => fn (Blueprint $table) => $table->index(
                ['user_id', 'is_read', 'created_at'],
                'student_notifications_unread_timeline'
            ),
            'student_notifications_user_timeline' => fn (Blueprint $table) => $table->index(
                ['user_id', 'created_at'],
                'student_notifications_user_timeline'
            ),
            'student_notifications_delivery_once' => fn (Blueprint $table) => $table->unique(
                ['user_id', 'delivery_key'],
                'student_notifications_delivery_once'
            ),
        ];

        foreach ($indexes as $index => $definition) {
            if (! Schema::hasIndex('student_notifications', $index)) {
                Schema::table('student_notifications', $definition);
            }
        }
    }
};
