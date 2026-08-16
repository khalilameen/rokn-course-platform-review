<?php

use App\Models\CourseGrantClaim;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_grant_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('normalized_email_hash', 64);
            $table->string('email_hint', 190)->nullable();
            $table->foreignId('course_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_code_usage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default(CourseGrantClaim::STATUS_ACTIVE);
            $table->timestamp('claimed_at');
            $table->timestamp('reassigned_at')->nullable();
            $table->foreignId('reassigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('support_note')->nullable();
            $table->timestamps();

            // One durable scholarship identity per account and per original
            // email. A support reassignment updates this row; it never creates
            // a second claim and therefore keeps a complete audit trail.
            $table->unique('user_id', 'grant_claims_user_unique');
            $table->unique('normalized_email_hash', 'grant_claims_email_unique');
            $table->index(['course_id', 'status']);
            $table->index(['course_code_id', 'status']);
        });

        // Preserve grants already consumed before this migration. Earliest
        // claim wins if legacy data contains duplicates; later duplicates stay
        // visible in course_code_usages for manual audit.
        DB::table('course_code_usages as usage')
            ->join('course_codes as code', 'code.id', '=', 'usage.course_code_id')
            ->join('users as user', 'user.id', '=', 'usage.user_id')
            ->where(function ($query): void {
                $query->where('code.is_grant', true)
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNotNull('code.allowed_email_domains')
                            ->where('code.allowed_email_domains', '!=', '[]');
                    });
            })
            ->orderBy('usage.used_at')
            ->select([
                'usage.id as usage_id', 'usage.user_id', 'usage.course_code_id',
                'usage.used_at', 'user.email', 'code.course_id',
            ])
            ->each(function ($row): void {
                if (!$row->course_id) return;
                DB::table('course_grant_claims')->insertOrIgnore([
                    'user_id' => $row->user_id,
                    'normalized_email_hash' => CourseGrantClaim::emailHash($row->email),
                    'email_hint' => CourseGrantClaim::emailHint($row->email),
                    'course_code_id' => $row->course_code_id,
                    'course_code_usage_id' => $row->usage_id,
                    'course_id' => $row->course_id,
                    'status' => CourseGrantClaim::STATUS_ACTIVE,
                    'claimed_at' => $row->used_at ?: now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_grant_claims');
    }
};
