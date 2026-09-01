<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $columns = [
            'project_followup_message_limit' => fn (Blueprint $table) => $table->unsignedInteger('project_followup_message_limit')->default(0)->after('project_feedback_reserve_usd'),
            'project_followup_token_budget' => fn (Blueprint $table) => $table->unsignedBigInteger('project_followup_token_budget')->default(0)->after('project_followup_message_limit'),
            'project_followup_budget_usd' => fn (Blueprint $table) => $table->decimal('project_followup_budget_usd', 12, 6)->default(0)->after('project_followup_token_budget'),
            'project_followup_reserve_usd' => fn (Blueprint $table) => $table->decimal('project_followup_reserve_usd', 12, 6)->default(0)->after('project_followup_budget_usd'),
        ];
        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('course_access_plans', $column)) {
                Schema::table('course_access_plans', $definition);
            }
        }

        // Existing enhanced plans promised an interactive review. Give that
        // promise an explicit, bounded contract instead of an unmetered flag.
        DB::table('course_access_plans')
            ->where('project_feedback_level', 'enhanced')
            ->update([
                'project_followup_message_limit' => 20,
                'project_followup_token_budget' => 12000,
                'project_followup_budget_usd' => .300000,
                'project_followup_reserve_usd' => .025000,
            ]);
    }

    public function down(): void
    {
        Schema::table('course_access_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'project_followup_message_limit',
                'project_followup_token_budget',
                'project_followup_budget_usd',
                'project_followup_reserve_usd',
            ]);
        });
    }
};
