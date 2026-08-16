<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_device_tokens', 'device_os')) {
            Schema::table('user_device_tokens', function (Blueprint $table) {
                $table->string('device_os', 20)->nullable()->after('device_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_device_tokens', 'device_os')) {
            Schema::table('user_device_tokens', function (Blueprint $table) {
                $table->dropColumn('device_os');
            });
        }
    }
};
