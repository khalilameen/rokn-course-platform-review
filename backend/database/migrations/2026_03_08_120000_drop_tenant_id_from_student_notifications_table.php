<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('student_notifications', 'tenant_id')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTableWithoutTenant();
            return;
        }

        Schema::table('student_notifications', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (DB::connection()->getDriverName() === 'sqlite' || Schema::hasColumn('student_notifications', 'tenant_id')) {
            return;
        }

        Schema::table('student_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('read_at');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('tenant_id');
        });
    }

    private function rebuildSqliteTableWithoutTenant(): void
    {
        $columns = [
            'id',
            'user_id',
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
            'created_at',
            'updated_at',
        ];

        Schema::disableForeignKeyConstraints();
        try {
            Schema::dropIfExists('student_notifications_without_tenant');
            Schema::create('student_notifications_without_tenant', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
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
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index('user_id');
                $table->index('is_read');
                $table->index('notification_type');
                $table->index('created_at');
                $table->index(['user_id', 'is_read']);
                $table->index(['notifiable_type', 'notifiable_id']);
            });

            DB::table('student_notifications_without_tenant')->insertUsing(
                $columns,
                DB::table('student_notifications')->select($columns)
            );
            Schema::drop('student_notifications');
            Schema::rename('student_notifications_without_tenant', 'student_notifications');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
