<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('portfolio_media', 'public_id')) {
            Schema::table('portfolio_media', fn (Blueprint $table) =>
                $table->uuid('public_id')->nullable()->after('id')->unique()
            );
        }
        DB::table('portfolio_media')->whereNull('public_id')->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('portfolio_media')->where('id', $row->id)
                        ->whereNull('public_id')
                        ->update(['public_id' => (string) Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolio_media', 'public_id')) {
            Schema::table('portfolio_media', function (Blueprint $table): void {
                $table->dropUnique(['public_id']);
                $table->dropColumn('public_id');
            });
        }
    }
};
