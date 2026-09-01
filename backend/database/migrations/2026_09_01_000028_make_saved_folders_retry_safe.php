<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Schema DDL must remain rerunnable after a partially completed deploy. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('saved_folders', 'normalized_name')) {
            Schema::table('saved_folders', function (Blueprint $table): void {
                $table->string('normalized_name', 255)->nullable()->after('name');
            });
        }
        if (!Schema::hasColumn('saved_folders', 'client_request_id')) {
            Schema::table('saved_folders', function (Blueprint $table): void {
                $table->uuid('client_request_id')->nullable()->after('normalized_name');
            });
        }

        DB::table('saved_folders')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function ($folders): void {
                foreach ($folders as $folder) {
                    $this->normalizeFolder((int) $folder->id);
                }
            });

        if (!Schema::hasIndex('saved_folders', 'saved_folders_user_normalized_name_unique')) {
            Schema::table('saved_folders', function (Blueprint $table): void {
                $table->unique(
                    ['user_id', 'normalized_name'],
                    'saved_folders_user_normalized_name_unique'
                );
            });
        }
        if (!Schema::hasIndex('saved_folders', 'saved_folders_user_client_request_unique')) {
            Schema::table('saved_folders', function (Blueprint $table): void {
                $table->unique(
                    ['user_id', 'client_request_id'],
                    'saved_folders_user_client_request_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('saved_folders', 'saved_folders_user_normalized_name_unique')) {
            Schema::table('saved_folders', fn (Blueprint $table) =>
                $table->dropUnique('saved_folders_user_normalized_name_unique')
            );
        }
        if (Schema::hasIndex('saved_folders', 'saved_folders_user_client_request_unique')) {
            Schema::table('saved_folders', fn (Blueprint $table) =>
                $table->dropUnique('saved_folders_user_client_request_unique')
            );
        }
        if (Schema::hasColumn('saved_folders', 'client_request_id')) {
            Schema::table('saved_folders', fn (Blueprint $table) => $table->dropColumn('client_request_id'));
        }
        if (Schema::hasColumn('saved_folders', 'normalized_name')) {
            Schema::table('saved_folders', fn (Blueprint $table) => $table->dropColumn('normalized_name'));
        }
    }

    private function normalizeFolder(int $folderId): void
    {
        DB::transaction(function () use ($folderId): void {
            $folder = DB::table('saved_folders')->where('id', $folderId)->lockForUpdate()->first();
            if (!$folder) {
                return;
            }

            // New writes use the same account lock. It keeps the merge local
            // to one learner without blocking unrelated libraries.
            DB::table('users')->where('id', $folder->user_id)->lockForUpdate()->first();
            $cleanName = self::cleanName((string) $folder->name);
            $cleanName = $cleanName !== '' ? $cleanName : 'قائمة محفوظة';
            $normalized = $this->normalizeName($cleanName);
            $keeper = DB::table('saved_folders')
                ->where('user_id', $folder->user_id)
                ->where('normalized_name', $normalized)
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id']);

            if (!$keeper) {
                DB::table('saved_folders')
                    ->where('id', $folderId)
                    ->update(['name' => $cleanName, 'normalized_name' => $normalized]);
                return;
            }
            if ((int) $keeper->id === $folderId) {
                if (!hash_equals((string) $folder->name, $cleanName)) {
                    DB::table('saved_folders')->where('id', $folderId)->update(['name' => $cleanName]);
                }
                return;
            }

            DB::table('saved_folder_lessons')
                ->where('saved_folder_id', $folderId)
                ->select(['id', 'lesson_id', 'created_at', 'updated_at'])
                ->orderBy('id')
                ->chunkById(500, function ($memberships) use ($keeper): void {
                    foreach ($memberships as $membership) {
                        DB::table('saved_folder_lessons')->insertOrIgnore([
                            'saved_folder_id' => (int) $keeper->id,
                            'lesson_id' => $membership->lesson_id,
                            'created_at' => $membership->created_at,
                            'updated_at' => $membership->updated_at,
                        ]);
                    }
                });

            DB::table('saved_folders')->where('id', $folderId)->delete();
        }, 3);
    }

    private function normalizeName(string $name): string
    {
        $name = self::cleanName($name);

        return mb_strtolower($name !== '' ? $name : 'قائمة محفوظة', 'UTF-8');
    }

    /** Keep migration replay independent from mutable application helpers. */
    private static function cleanName(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $repaired = function_exists('iconv')
                ? iconv('UTF-8', 'UTF-8//IGNORE', $value)
                : false;
            $value = is_string($repaired) ? $repaired : '';
        }
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        $value = preg_replace(
            '/[\x{00AD}\x{034F}\x{061C}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u',
            '',
            $value
        ) ?? '';
        $value = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace(
            '/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u',
            ' ',
            $value
        ) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
};
