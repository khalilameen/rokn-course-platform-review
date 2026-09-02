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
            Schema::hasTable('project_feedback_messages')
            && !Schema::hasIndex(
                'project_feedback_messages',
                'project_feedback_messages_thread_state'
            )
        ) {
            Schema::table('project_feedback_messages', function (Blueprint $table): void {
                $table->index(
                    ['thread_id', 'role', 'status', 'id'],
                    'project_feedback_messages_thread_state'
                );
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('project_feedback_messages')
            && Schema::hasIndex(
                'project_feedback_messages',
                'project_feedback_messages_thread_state'
            )
        ) {
            Schema::table('project_feedback_messages', function (Blueprint $table): void {
                $table->dropIndex('project_feedback_messages_thread_state');
            });
        }
    }
};
