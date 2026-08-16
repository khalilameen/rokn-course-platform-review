<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddVideoSourceFieldsToLessonsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('lessons')) {
            // The legacy database contained lessons before its migration
            // history was imported. Define the clean-install baseline here so
            // new environments no longer depend on an untracked SQL dump.
            Schema::create('lessons', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('list_id')->nullable()->index();
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_opened')->default(false);
                $table->string('video_link')->nullable();
                $table->enum('video_source_type', ['youtube', 'bunny'])->default('youtube');
                $table->string('bunny_video_id')->nullable();
                $table->string('file_link1')->nullable();
                $table->string('file_link2')->nullable();
                $table->integer('priority')->default(0);
                $table->unsignedBigInteger('quiz_id')->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('lessons', 'video_source_type')) {
                $table->enum('video_source_type', ['youtube', 'bunny'])->default('youtube')->after('video_link');
            }
            if (! Schema::hasColumn('lessons', 'bunny_video_id')) {
                $table->string('bunny_video_id')->nullable()->after('video_source_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('lessons')) {
            return;
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['video_source_type', 'bunny_video_id']);
        });
    }
}

