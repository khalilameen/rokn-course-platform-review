<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * This migration deliberately guards each DDL step so MySQL can resume
     * after an implicit commit or an interrupted deployment.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('project_submissions', 'evaluation_snapshot')) {
            Schema::table('project_submissions', function (Blueprint $table): void {
                $table->json('evaluation_snapshot')->nullable()->after('submission_metadata');
            });
        }

        if (!Schema::hasTable('project_submission_review_decisions')) {
            Schema::create('project_submission_review_decisions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('decision_id')->unique();
                $table->foreignId('submission_id')
                    ->constrained('project_submissions')
                    ->cascadeOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('status', 30);
                $table->unsignedTinyInteger('score')->nullable();
                $table->text('feedback');
                $table->string('source', 40);
                // Keep the historical reviewer identity even if that staff account
                // is later removed. This is audit evidence, not an auth relation.
                $table->unsignedBigInteger('reviewer_id')->nullable()->index();
                $table->string('reviewer_role', 24)->nullable();
                $table->timestamp('decided_at');
                $table->json('decision_metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['submission_id', 'sequence'], 'project_review_decision_sequence');
            });
        }

        // Preserve the current durable decision of submissions reviewed before
        // this append-only ledger existed. Earlier overwritten details cannot
        // be invented, but the surviving complete summary must not be lost.
        DB::table('project_submissions')
            ->where(function ($query): void {
                $query->whereNotNull('reviewed_at')
                    ->orWhereNotNull('review_source');
            })
            ->whereNotExists(function ($decisions): void {
                $decisions->selectRaw('1')
                    ->from('project_submission_review_decisions')
                    ->whereColumn(
                        'project_submission_review_decisions.submission_id',
                        'project_submissions.id'
                    );
            })
            ->chunkById(500, function ($submissions): void {
                $reviewerRoles = DB::table('users')
                    ->whereIn('id', $submissions->pluck('reviewed_by')->filter()->all())
                    ->pluck('role', 'id');
                $now = now();
                DB::table('project_submission_review_decisions')->insertOrIgnore(
                    $submissions->map(static function ($submission) use ($reviewerRoles, $now): array {
                        $decidedAt = $submission->reviewed_at
                            ?: $submission->submitted_at
                            ?: $submission->updated_at
                            ?: $now;

                        return [
                            'decision_id' => (string) Str::uuid(),
                            'submission_id' => (int) $submission->id,
                            'sequence' => 1,
                            'status' => (string) $submission->review_status,
                            'score' => $submission->score,
                            'feedback' => (string) ($submission->feedback ?? ''),
                            'source' => (string) ($submission->review_source ?: 'legacy_review'),
                            'reviewer_id' => $submission->reviewed_by,
                            'reviewer_role' => $submission->reviewed_by
                                ? $reviewerRoles->get($submission->reviewed_by)
                                : null,
                            'decided_at' => $decidedAt,
                            'decision_metadata' => json_encode(
                                ['legacy_baseline' => true],
                                JSON_THROW_ON_ERROR
                            ),
                            'created_at' => $now,
                        ];
                    })->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_submission_review_decisions');

        if (Schema::hasColumn('project_submissions', 'evaluation_snapshot')) {
            Schema::table('project_submissions', function (Blueprint $table): void {
                $table->dropColumn('evaluation_snapshot');
            });
        }
    }
};
