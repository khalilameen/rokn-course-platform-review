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
        if (!Schema::hasTable('courses')) return;
        Schema::table('courses', function (Blueprint $table): void {
            if (!Schema::hasColumn('courses', 'attachment_prompt_enabled')) $table->boolean('attachment_prompt_enabled')->default(true);
            if (!Schema::hasColumn('courses', 'attachment_prompt_at_seconds')) $table->unsignedSmallInteger('attachment_prompt_at_seconds')->default(20);
            if (!Schema::hasColumn('courses', 'attachment_prompt_title')) $table->string('attachment_prompt_title')->nullable();
            if (!Schema::hasColumn('courses', 'attachment_prompt_body')) $table->text('attachment_prompt_body')->nullable();
            if (!Schema::hasColumn('courses', 'attachment_prompt_button_text')) $table->string('attachment_prompt_button_text', 80)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('courses')) return;
        $columns = array_values(array_filter([
            'attachment_prompt_enabled',
            'attachment_prompt_at_seconds',
            'attachment_prompt_title',
            'attachment_prompt_body',
            'attachment_prompt_button_text',
        ], static fn (string $column): bool => Schema::hasColumn('courses', $column)));
        if ($columns === []) return;
        Schema::table('courses', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
