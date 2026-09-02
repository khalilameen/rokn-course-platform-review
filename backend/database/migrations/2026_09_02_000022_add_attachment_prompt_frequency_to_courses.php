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
        if (!Schema::hasTable('courses') || Schema::hasColumn('courses', 'attachment_prompt_frequency')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table): void {
            $table->string('attachment_prompt_frequency', 24)
                ->default('once_per_course')
                ->after('attachment_prompt_at_seconds');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('courses') || !Schema::hasColumn('courses', 'attachment_prompt_frequency')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('attachment_prompt_frequency');
        });
    }
};
