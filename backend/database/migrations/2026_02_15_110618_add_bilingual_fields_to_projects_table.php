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
        Schema::table('projects', function (Blueprint $table) {
            $table->text('requirements_text_ar')->nullable()->after('id');
            $table->text('requirements_text_en')->nullable()->after('requirements_text_ar');
        });

        // Migrate existing data
        DB::table('projects')->whereNotNull('requirements_text')->update([
            'requirements_text_ar' => DB::raw('requirements_text')
        ]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Make original column nullable
        Schema::table('projects', function (Blueprint $table) {
            $table->text('requirements_text')->nullable()->change();
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

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['requirements_text_ar', 'requirements_text_en']);
            $table->text('requirements_text')->nullable(false)->change();
        });
    }
};
