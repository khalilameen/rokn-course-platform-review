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
        if (!Schema::hasTable('exam_attempts')) {
            return;
        }

        Schema::table('exam_attempts', function (Blueprint $table): void {
            if (!Schema::hasColumn('exam_attempts', 'quiz_title')) {
                $table->string('quiz_title')->nullable()->after('quiz_id');
            }
            if (!Schema::hasColumn('exam_attempts', 'quiz_description')) {
                $table->text('quiz_description')->nullable()->after('quiz_title');
            }
            if (!Schema::hasColumn('exam_attempts', 'quiz_image')) {
                $table->text('quiz_image')->nullable()->after('quiz_description');
            }
        });

        if (!Schema::hasTable('lists')) {
            return;
        }

        DB::table('exam_attempts')
            ->whereNull('quiz_title')
            ->select(['id', 'quiz_id'])
            ->orderBy('id')
            ->chunkById(500, function ($attempts): void {
                $quizzes = DB::table('lists')
                    ->whereIn('id', $attempts->pluck('quiz_id')->filter()->unique())
                    ->get([
                        'id',
                        'title',
                        'title_ar',
                        'title_en',
                        'description',
                        'description_ar',
                        'description_en',
                        'image',
                    ])
                    ->keyBy('id');

                foreach ($attempts as $attempt) {
                    $quiz = $quizzes->get($attempt->quiz_id);
                    if (!$quiz) {
                        continue;
                    }
                    DB::table('exam_attempts')->where('id', $attempt->id)->update([
                        'quiz_title' => $this->firstText([
                            $quiz->title_ar,
                            $quiz->title_en,
                            $quiz->title,
                        ]),
                        'quiz_description' => $this->firstText([
                            $quiz->description_ar,
                            $quiz->description_en,
                            $quiz->description,
                        ]),
                        'quiz_image' => $this->firstText([$quiz->image]),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('exam_attempts')) {
            return;
        }
        Schema::table('exam_attempts', function (Blueprint $table): void {
            $columns = collect(['quiz_title', 'quiz_description', 'quiz_image'])
                ->filter(fn (string $column): bool => Schema::hasColumn('exam_attempts', $column))
                ->all();
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /** @param array<int, mixed> $values */
    private function firstText(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
};
