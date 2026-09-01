<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('attachments', 'mime_type')) {
            Schema::table('attachments', fn (Blueprint $table) =>
                $table->string('mime_type', 190)->nullable()->after('file_type')
            );
        }
        if (!Schema::hasColumn('attachments', 'content_sha256')) {
            Schema::table('attachments', fn (Blueprint $table) =>
                $table->char('content_sha256', 64)->nullable()->after('file_size')
            );
        }
        if (!Schema::hasIndex('attachments', 'attachments_content_unique')) {
            Schema::table('attachments', fn (Blueprint $table) =>
                $table->unique(
                    ['attachable_type', 'attachable_id', 'content_sha256'],
                    'attachments_content_unique'
                )
            );
        }
        if (!Schema::hasColumn('course_pdfs', 'content_sha256')) {
            Schema::table('course_pdfs', fn (Blueprint $table) =>
                $table->char('content_sha256', 64)->nullable()->after('file_size')
            );
        }
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropUnique('attachments_content_unique');
            $table->dropColumn(['mime_type', 'content_sha256']);
        });
        Schema::table('course_pdfs', function (Blueprint $table): void {
            $table->dropColumn('content_sha256');
        });
    }
};
