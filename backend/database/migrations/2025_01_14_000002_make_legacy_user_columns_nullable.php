<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MakeLegacyUserColumnsNullable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Clean SQLite installs already receive nullable legacy identity
            // columns from their owning migrations; avoid rebuilding users.
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->default(null)->change();
            $table->string('second_name')->nullable()->default(null)->change();
            $table->string('last_name')->nullable()->default(null)->change();
            $table->string('parent_phone')->nullable()->default(null)->change();
            $table->string('type')->nullable()->default(null)->change();
            $table->string('governorate')->nullable()->default(null)->change();
            $table->date('birthday')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Reversing would require setting NOT NULL which could fail with existing data
    }
}
