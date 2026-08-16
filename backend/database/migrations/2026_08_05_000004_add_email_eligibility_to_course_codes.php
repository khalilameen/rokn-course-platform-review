<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_codes', function (Blueprint $table): void {
            $table->json('allowed_email_domains')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('course_codes', function (Blueprint $table): void {
            $table->dropColumn('allowed_email_domains');
        });
    }
};
