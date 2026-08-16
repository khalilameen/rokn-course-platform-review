<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_pdfs', function (Blueprint $table): void {
            // Null deliberately means the pre-migration local disk. The
            // migration command copies and verifies each object before this
            // field is changed, so a schema deploy cannot orphan old files.
            $table->string('storage_disk', 64)->nullable()->after('file_path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('course_pdfs', function (Blueprint $table): void {
            $table->dropIndex(['storage_disk']);
            $table->dropColumn('storage_disk');
        });
    }
};
