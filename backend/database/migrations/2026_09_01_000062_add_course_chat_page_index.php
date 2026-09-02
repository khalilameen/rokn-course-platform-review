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
        if (
            !Schema::hasTable('course_chat_turns')
            || Schema::hasIndex('course_chat_turns', 'course_chat_turn_page_index')
        ) {
            return;
        }

        Schema::table('course_chat_turns', fn (Blueprint $table) =>
            $table->index(
                ['user_id', 'course_id', 'lesson_id', 'id'],
                'course_chat_turn_page_index'
            )
        );
    }

    public function down(): void
    {
        if (
            !Schema::hasTable('course_chat_turns')
            || !Schema::hasIndex('course_chat_turns', 'course_chat_turn_page_index')
        ) {
            return;
        }

        Schema::table('course_chat_turns', fn (Blueprint $table) =>
            $table->dropIndex('course_chat_turn_page_index')
        );
    }
};
