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
            Schema::hasTable('social_oauth_attempts')
            && !Schema::hasColumn('social_oauth_attempts', 'completion_processing_at')
        ) {
            Schema::table('social_oauth_attempts', function (Blueprint $table): void {
                $table->timestamp('completion_processing_at')
                    ->nullable()
                    ->after('completion_expires_at');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('social_oauth_attempts')
            && Schema::hasColumn('social_oauth_attempts', 'completion_processing_at')
        ) {
            Schema::table('social_oauth_attempts', function (Blueprint $table): void {
                $table->dropColumn('completion_processing_at');
            });
        }
    }
};
