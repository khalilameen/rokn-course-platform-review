<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('request_type', 40)->nullable()->after('read')->index();
            $table->string('resolution_status', 30)->nullable()->after('request_type')->index();
            $table->timestamp('resolved_at')->nullable()->after('resolution_status');
            $table->foreignId('resolved_by')
                ->nullable()
                ->after('resolved_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('resolved_user_id')
                ->nullable()
                ->after('resolved_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->json('resolution_metadata')->nullable()->after('resolved_user_id');
        });

        DB::table('contacts')
            ->where('message', 'like', '[ACCOUNT_DELETION_REQUEST]%')
            ->update(['request_type' => 'account_deletion']);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_user_id');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn([
                'request_type',
                'resolution_status',
                'resolved_at',
                'resolution_metadata',
            ]);
        });
    }
};
