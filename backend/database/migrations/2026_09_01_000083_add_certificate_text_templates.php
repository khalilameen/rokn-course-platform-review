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
        if (!Schema::hasColumn('courses', 'certificate_text_template_key')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->string('certificate_text_template_key', 32)
                    ->default('completion')
                    ->after('awards_badge');
            });
        }

        if (!Schema::hasColumn('certificates', 'certificate_text_template_key')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->string('certificate_text_template_key', 32)
                    ->nullable()
                    ->after('course_name');
            });
        }
        if (!Schema::hasColumn('certificates', 'certificate_text')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->string('certificate_text', 255)
                    ->nullable()
                    ->after('certificate_text_template_key');
            });
        }
        // Existing artifacts keep these fields null. Their pixels predate the
        // selectable wording and must not claim text that was never printed.
        // A missing artifact receives one atomic snapshot when it is rebuilt.
    }

    public function down(): void
    {
        if (Schema::hasColumn('certificates', 'certificate_text')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->dropColumn('certificate_text')
            );
        }
        if (Schema::hasColumn('certificates', 'certificate_text_template_key')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->dropColumn('certificate_text_template_key')
            );
        }
        if (Schema::hasColumn('courses', 'certificate_text_template_key')) {
            Schema::table('courses', fn (Blueprint $table) =>
                $table->dropColumn('certificate_text_template_key')
            );
        }
    }
};
