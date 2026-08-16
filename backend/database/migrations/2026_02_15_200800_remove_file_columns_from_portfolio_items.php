<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemoveFileColumnsFromPortfolioItems extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // These are retired compatibility columns. Keeping them in SQLite is
        // safer than rebuilding a table referenced by portfolio_media; MySQL
        // production upgrades still remove them.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_type', 'thumbnail_path']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->string('file_path')->nullable();
            $table->enum('file_type', ['image', 'video', 'text'])->default('text');
            $table->string('thumbnail_path')->nullable();
        });
    }
}
