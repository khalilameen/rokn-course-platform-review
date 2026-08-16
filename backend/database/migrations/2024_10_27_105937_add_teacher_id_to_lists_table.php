<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('lists') || Schema::hasColumn('lists', 'teacher_id')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_id')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('lists') || ! Schema::hasColumn('lists', 'teacher_id')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table) {
            $table->dropColumn('teacher_id');
        });
    }
};
