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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('portfolio_slug')->nullable()->unique()->after('bio_en');
            $table->boolean('portfolio_is_public')->default(false)->after('portfolio_slug');
            $table->string('portfolio_headline')->nullable()->after('portfolio_is_public');
            $table->string('portfolio_location')->nullable()->after('portfolio_headline');
            $table->json('portfolio_skills')->nullable()->after('portfolio_location');
            $table->json('portfolio_links')->nullable()->after('portfolio_skills');
        });

        DB::table('users')->select('id')->orderBy('id')->each(function ($user): void {
            DB::table('users')->where('id', $user->id)->update([
                'portfolio_slug' => 'student-' . $user->id,
            ]);
        });

        Schema::table('portfolio_items', function (Blueprint $table): void {
            $table->foreignId('course_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('source_project_id')->nullable()->after('course_id')->constrained('projects')->nullOnDelete();
            $table->string('slug')->nullable()->after('description');
            $table->string('role')->nullable()->after('slug');
            $table->json('tools')->nullable()->after('role');
            $table->text('external_url')->nullable()->after('tools');
            $table->date('completed_at')->nullable()->after('external_url');
            $table->boolean('is_public')->default(false)->after('completed_at');
            $table->boolean('is_featured')->default(false)->after('is_public');
            $table->unsignedInteger('sort_order')->default(0)->after('is_featured');
        });

        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->string('caption')->nullable()->after('file_type');
            $table->string('thumbnail_path')->nullable()->after('caption');
            $table->unsignedInteger('width')->nullable()->after('thumbnail_path');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedInteger('duration_seconds')->nullable()->after('height');
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->string('status', 20)->default('active')->after('image_path');
            $table->timestamp('revoked_at')->nullable()->after('generated_at');
        });

        DB::table('certificates')->select('id')->orderBy('id')->each(function ($certificate): void {
            DB::table('certificates')->where('id', $certificate->id)->update([
                'public_id' => (string) Str::uuid(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropColumn(['public_id', 'status', 'revoked_at']);
        });
        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->dropColumn(['caption', 'thumbnail_path', 'width', 'height', 'duration_seconds']);
        });
        Schema::table('portfolio_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_project_id');
            $table->dropConstrainedForeignId('course_id');
            $table->dropColumn(['slug', 'role', 'tools', 'external_url', 'completed_at', 'is_public', 'is_featured', 'sort_order']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'portfolio_slug', 'portfolio_is_public', 'portfolio_headline',
                'portfolio_location', 'portfolio_skills', 'portfolio_links',
            ]);
        });
    }
};
