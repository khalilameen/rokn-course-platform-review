<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_ar');
            $table->text('bio_ar')->nullable()->after('bio');
            $table->text('bio_en')->nullable()->after('bio_ar');
        });

        // Migrate existing data: copy name -> name_ar and bio -> bio_ar
        DB::statement('UPDATE users SET name_ar = name WHERE name_ar IS NULL');
        DB::statement('UPDATE users SET bio_ar = bio WHERE bio_ar IS NULL AND bio IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'name_en', 'bio_ar', 'bio_en']);
        });
    }
};
