<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lists')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table): void {
            $table->index(
                ['type', 'course_id', 'id'],
                'lists_quiz_course_page_lookup'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lists')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table): void {
            $table->dropIndex('lists_quiz_course_page_lookup');
        });
    }
};
