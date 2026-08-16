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
        Schema::table('app_versions', function (Blueprint $table): void {
            // Nullable keeps old Android rows usable as an explicitly scoped
            // legacy fallback. New dashboard writes always set the channel.
            $table->string('distribution_channel', 16)->nullable()->after('platform');
            $table->index(
                ['platform', 'distribution_channel', 'is_active'],
                'app_versions_platform_channel_active_index',
            );
        });

        // iOS has one supported distribution path, so this backfill is
        // unambiguous. Android legacy rows remain null because guessing Play
        // versus direct could return an APK to a store build.
        DB::table('app_versions')
            ->where('platform', 'ios')
            ->whereNull('distribution_channel')
            ->update(['distribution_channel' => 'appstore']);

        // Preserve duplicate historical rows without deleting data: only the
        // newest duplicate keeps the explicit channel, while older copies
        // remain legacy/null. This makes the new database invariant deployable
        // even if the old dashboard wrote the same build more than once.
        $duplicateBuilds = DB::table('app_versions')
            ->select(
                'platform',
                'distribution_channel',
                'build_number',
                DB::raw('MAX(id) as keep_id'),
            )
            ->whereNotNull('distribution_channel')
            ->whereNotNull('build_number')
            ->groupBy('platform', 'distribution_channel', 'build_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateBuilds as $duplicate) {
            DB::table('app_versions')
                ->where('platform', $duplicate->platform)
                ->where('distribution_channel', $duplicate->distribution_channel)
                ->where('build_number', $duplicate->build_number)
                ->where('id', '<>', $duplicate->keep_id)
                ->update(['distribution_channel' => null]);
        }

        Schema::table('app_versions', function (Blueprint $table): void {
            $table->unique(
                ['platform', 'distribution_channel', 'version_code'],
                'app_versions_channel_version_code_unique',
            );
            $table->unique(
                ['platform', 'distribution_channel', 'build_number'],
                'app_versions_channel_build_number_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('app_versions', function (Blueprint $table): void {
            $table->dropUnique('app_versions_channel_version_code_unique');
            $table->dropUnique('app_versions_channel_build_number_unique');
            $table->dropIndex('app_versions_platform_channel_active_index');
            $table->dropColumn('distribution_channel');
        });
    }
};
