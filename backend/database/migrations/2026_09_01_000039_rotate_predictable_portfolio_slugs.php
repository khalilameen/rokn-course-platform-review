<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'portfolio_slug')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('portfolio_slug')
            ->select(['id', 'portfolio_slug'])
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    if (strtolower(trim((string) $user->portfolio_slug)) !== 'student-'.(int) $user->id) {
                        continue;
                    }

                    DB::table('users')->where('id', $user->id)->update([
                        'portfolio_slug' => $this->freshSlug(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Predictable public aliases are intentionally not restored.
    }

    private function freshSlug(): string
    {
        do {
            $slug = 'rokn-'.Str::lower(Str::random(24));
        } while (DB::table('users')->where('portfolio_slug', $slug)->exists());

        return $slug;
    }
};
