<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('certificates', 'holder_name')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->string('holder_name')->nullable()->after('project_id')
            );
        }
        if (!Schema::hasColumn('certificates', 'course_name')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->string('course_name')->nullable()->after('holder_name')
            );
        }

        DB::table('certificates')
            ->leftJoin('users', 'users.id', '=', 'certificates.user_id')
            ->leftJoin('courses', 'courses.id', '=', 'certificates.course_id')
            ->select([
                'certificates.id as certificate_id',
                'users.name as user_name',
                'users.name_ar as user_name_ar',
                'users.name_en as user_name_en',
                'courses.name_ar as course_name_ar',
                'courses.name_en as course_name_en',
            ])
            ->orderBy('certificates.id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('certificates')
                        ->where('id', $row->certificate_id)
                        ->whereNull('holder_name')
                        ->update([
                            'holder_name' => $this->firstText([
                                $row->user_name_ar,
                                $row->user_name_en,
                                $row->user_name,
                            ], 'طالب ركن'),
                        ]);

                    DB::table('certificates')
                        ->where('id', $row->certificate_id)
                        ->whereNull('course_name')
                        ->update([
                            'course_name' => $this->firstText([
                                $row->course_name_ar,
                                $row->course_name_en,
                            ], 'كورس ركن'),
                        ]);
                }
            }, 'certificates.id', 'certificate_id');
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropColumn(['holder_name', 'course_name']);
        });
    }

    /** @param array<int, mixed> $values */
    private function firstText(array $values, string $fallback): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return mb_substr($text, 0, 255);
            }
        }

        return $fallback;
    }
};
