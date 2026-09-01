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
        if (!Schema::hasTable('student_notifications') || Schema::hasColumn('student_notifications', 'image_url')) {
            return;
        }

        Schema::table('student_notifications', function (Blueprint $table): void {
            $table->string('image_url', 2048)->nullable()->after('link');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('student_notifications') || !Schema::hasColumn('student_notifications', 'image_url')) {
            return;
        }

        Schema::table('student_notifications', function (Blueprint $table): void {
            $table->dropColumn('image_url');
        });
    }
};
