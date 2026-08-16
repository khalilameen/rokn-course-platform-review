<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            // Existing rows remain public until the audited migration command
            // verifies a private copy. Every new upload is private immediately.
            $table->string('storage_disk', 64)->default('public')->after('file_path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropIndex(['storage_disk']);
            $table->dropColumn('storage_disk');
        });
    }
};
