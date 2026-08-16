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
        Schema::table('courses', function (Blueprint $table) {
            $table->string('ai_model_type')->nullable()->after('course_type');
            $table->text('chat_ai_prompt')->nullable()->after('ai_model_type');
            $table->float('temperature')->nullable()->after('chat_ai_prompt');
            $table->integer('tokens_number')->nullable()->after('temperature');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['ai_model_type', 'chat_ai_prompt', 'temperature', 'tokens_number']);
        });
    }
};
