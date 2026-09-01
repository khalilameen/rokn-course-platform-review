<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (
            DB::connection()->getDriverName() !== 'mysql'
            || !Schema::hasColumn('social_oauth_attempts', 'encrypted_session_response')
        ) {
            return;
        }

        DB::statement(
            'ALTER TABLE `social_oauth_attempts` '
                . 'MODIFY `encrypted_session_response` MEDIUMTEXT NULL'
        );
    }

    public function down(): void
    {
        if (
            DB::connection()->getDriverName() !== 'mysql'
            || !Schema::hasColumn('social_oauth_attempts', 'encrypted_session_response')
        ) {
            return;
        }

        $oversized = DB::table('social_oauth_attempts')
            ->whereRaw('OCTET_LENGTH(`encrypted_session_response`) > 65535')
            ->exists();
        if ($oversized) {
            throw new RuntimeException(
                'Cannot shrink OAuth replay responses while oversized sessions exist.'
            );
        }

        DB::statement(
            'ALTER TABLE `social_oauth_attempts` '
                . 'MODIFY `encrypted_session_response` TEXT NULL'
        );
    }
};
