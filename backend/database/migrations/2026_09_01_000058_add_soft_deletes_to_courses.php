<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('courses')
            && ! Schema::hasColumn('courses', 'deleted_at')
        ) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        // Additive production migration. Dropping this column would silently
        // resurrect deleted courses; old releases safely ignore it.
    }
};
