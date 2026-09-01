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
            && !Schema::hasColumn('social_oauth_attempts', 'encrypted_session_response')
        ) {
            Schema::table('social_oauth_attempts', function (Blueprint $table): void {
                // StudentProfileResource can include enrolled-course summaries.
                // Encryption/base64 adds overhead, so a 64 KiB TEXT column can
                // reject an otherwise successful returning-user login.
                $table->mediumText('encrypted_session_response')
                    ->nullable()
                    ->after('encrypted_token');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('social_oauth_attempts')
            && Schema::hasColumn('social_oauth_attempts', 'encrypted_session_response')
        ) {
            Schema::table('social_oauth_attempts', function (Blueprint $table): void {
                $table->dropColumn('encrypted_session_response');
            });
        }
    }
};
