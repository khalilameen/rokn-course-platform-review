<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('lists') || Schema::hasColumn('lists', 'time_minutes')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table) {
            $table->integer('time_minutes')->nullable()->after('description')->comment('Exam time limit in minutes');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('lists') || ! Schema::hasColumn('lists', 'time_minutes')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table) {
            $table->dropColumn('time_minutes');
        });
    }
};
