<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // MySQL commits each DDL statement independently. Keep both table
    // creations resumable when a deployment is interrupted between them.
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('course_authoring_revisions')) {
            Schema::create('course_authoring_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('canonical_course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('revision_course_id')->constrained('courses')->cascadeOnDelete();
                $table->unsignedBigInteger('base_authoring_version');
                $table->unsignedBigInteger('published_authoring_version')->nullable();
                $table->string('status', 16)->default('draft');
                // MySQL permits several NULLs in a unique index. Exactly one live
                // draft owns this deterministic slot; archives release it.
                $table->string('active_slot', 80)->nullable()->unique();
                $table->uuid('clone_key')->unique();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('retain_until')->nullable()->index();
                $table->timestamps();
                $table->unique('revision_course_id');
                $table->index(['canonical_course_id', 'status']);
            });
        }

        if (!Schema::hasTable('course_authoring_revision_entities')) {
            Schema::create('course_authoring_revision_entities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_authoring_revision_id');
                $table->foreign(
                    'course_authoring_revision_id',
                    'course_auth_revision_entity_revision_fk'
                )->references('id')->on('course_authoring_revisions')->cascadeOnDelete();
                $table->string('entity_type', 120);
                $table->unsignedBigInteger('source_entity_id');
                $table->unsignedBigInteger('revision_entity_id');
                $table->boolean('survives_publish')->default(false)->index();
                $table->boolean('carries_learner_state')->default(false)->index();
                $table->unsignedBigInteger('learner_root_entity_id')->nullable()->index();
                $table->unique(
                    ['course_authoring_revision_id', 'entity_type', 'source_entity_id'],
                    'course_revision_source_entity_unique'
                );
                $table->unique(
                    ['course_authoring_revision_id', 'entity_type', 'revision_entity_id'],
                    'course_revision_working_entity_unique'
                );
                // Learner reads resolve an entire lineage in two set queries. The
                // indexes must follow those predicates; the per-revision unique
                // keys above cannot serve hot lookups across many publishes.
                $table->index(
                    ['entity_type', 'revision_entity_id', 'carries_learner_state'],
                    'course_revision_learner_current_idx'
                );
                $table->index(
                    ['entity_type', 'learner_root_entity_id', 'carries_learner_state'],
                    'course_revision_learner_root_idx'
                );
                $table->index(
                    ['entity_type', 'source_entity_id', 'survives_publish'],
                    'course_revision_authoring_source_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_authoring_revision_entities');
        Schema::dropIfExists('course_authoring_revisions');
    }
};
