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
        if (!Schema::hasTable('social_oauth_attempts')) {
            return;
        }

        Schema::table('social_oauth_attempts', function (Blueprint $table): void {
            if (!Schema::hasColumn('social_oauth_attempts', 'nonce_hash')) {
                $table->char('nonce_hash', 64)->nullable()->after('code_challenge');
            }
            if (!Schema::hasColumn('social_oauth_attempts', 'encrypted_completion_code')) {
                $table->text('encrypted_completion_code')->nullable()->after('encrypted_token');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('social_oauth_attempts')) {
            return;
        }

        Schema::table('social_oauth_attempts', function (Blueprint $table): void {
            if (Schema::hasColumn('social_oauth_attempts', 'encrypted_completion_code')) {
                $table->dropColumn('encrypted_completion_code');
            }
            if (Schema::hasColumn('social_oauth_attempts', 'nonce_hash')) {
                $table->dropColumn('nonce_hash');
            }
        });
    }
};
