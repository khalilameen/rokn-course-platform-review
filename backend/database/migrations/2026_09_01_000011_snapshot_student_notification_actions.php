<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('student_notifications')) {
            return;
        }

        Schema::table('student_notifications', function (Blueprint $table): void {
            if (!Schema::hasColumn('student_notifications', 'action_label_ar')) {
                $table->string('action_label_ar', 80)->nullable()->after('image_url');
            }
            if (!Schema::hasColumn('student_notifications', 'action_label_en')) {
                $table->string('action_label_en', 80)->nullable()->after('action_label_ar');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('student_notifications')) {
            return;
        }
        Schema::table('student_notifications', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['action_label_ar', 'action_label_en'],
                static fn (string $column): bool => Schema::hasColumn('student_notifications', $column)
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
