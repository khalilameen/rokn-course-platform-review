<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Text is required because Laravel's encrypted cast expands the
            // ciphertext beyond a conventional varchar boundary.
            $table->text('admin_totp_secret')->nullable();
            $table->timestamp('admin_totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('admin_totp_last_used_step')->nullable();
            $table->text('admin_mfa_backup_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'admin_totp_secret',
                'admin_totp_confirmed_at',
                'admin_totp_last_used_step',
                'admin_mfa_backup_codes',
            ]);
        });
    }
};
