<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->text('bunny_api_key_secret')->nullable();
            $table->text('bunny_storage_password_secret')->nullable();
            $table->text('bunny_security_key_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'bunny_api_key_secret',
                'bunny_storage_password_secret',
                'bunny_security_key_secret',
            ]);
        });
    }
};
