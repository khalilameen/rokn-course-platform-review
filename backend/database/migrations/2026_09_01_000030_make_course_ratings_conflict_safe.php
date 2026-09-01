<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('course_ratings')) {
            return;
        }

        if (!Schema::hasColumn('course_ratings', 'version')) {
            Schema::table('course_ratings', fn (Blueprint $table) =>
                $table->unsignedBigInteger('version')->default(1)->after('comment')
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('course_ratings') || !Schema::hasColumn('course_ratings', 'version')) {
            return;
        }

        Schema::table('course_ratings', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
