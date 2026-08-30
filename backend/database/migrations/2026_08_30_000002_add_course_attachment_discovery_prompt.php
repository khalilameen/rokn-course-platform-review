<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('attachment_prompt_enabled')->default(true);
            $table->unsignedSmallInteger('attachment_prompt_at_seconds')->default(20);
            $table->string('attachment_prompt_title')->nullable();
            $table->text('attachment_prompt_body')->nullable();
            $table->string('attachment_prompt_button_text', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn([
                'attachment_prompt_enabled',
                'attachment_prompt_at_seconds',
                'attachment_prompt_title',
                'attachment_prompt_body',
                'attachment_prompt_button_text',
            ]);
        });
    }
};
