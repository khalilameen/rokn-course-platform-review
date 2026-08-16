<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_codes', function (Blueprint $table): void {
            $table->boolean('is_grant')->default(false)->after('is_active')->index();
        });

        // Existing institution-restricted codes keep their intended semantics.
        DB::table('course_codes')
            ->whereNotNull('allowed_email_domains')
            ->where('allowed_email_domains', '!=', '[]')
            ->update(['is_grant' => true]);
    }

    public function down(): void
    {
        Schema::table('course_codes', function (Blueprint $table): void {
            $table->dropIndex(['is_grant']);
            $table->dropColumn('is_grant');
        });
    }
};
