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
        if (! Schema::hasTable('lists') || Schema::hasColumn('lists', 'course_id')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table) {
            $table->bigInteger("course_id")->nullable()->after("id");
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('lists') || ! Schema::hasColumn('lists', 'course_id')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table) {
            $table->dropColumn("course_id");
        });
    }
};
